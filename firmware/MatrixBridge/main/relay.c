#include "relay.h"
#include "matrix_alias.h"
#include <errno.h>
#include <fcntl.h>
#include <string.h>
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "freertos/semphr.h"
#include "esp_timer.h"
#include "esp_heap_caps.h"
#include "lwip/sockets.h"
#ifndef MATRIX_RELAY_PORT
#define MATRIX_RELAY_PORT 8080
#endif
#define CLIENTS 8
#define BUFFER_BYTES 16384
#define BURST_LIMIT 4
#define IDLE_US 30000000
static SemaphoreHandle_t lock;
static struct { bool connected, listening; esp_ip4_addr_t primary, aliases[2], target; unsigned generation, clients; } config;
typedef struct {
    int fd[2];
    bool connecting, eof[2], shutdown_sent[2];
    unsigned char *data[2];
    size_t used[2], sent[2];
    int64_t touched, deadline;
} pipe_t;
static pipe_t pipes[CLIENTS];
static unsigned char *buffer_pool;
static void close_pipe(pipe_t *p)
{
    for (unsigned i=0;i<2;i++) if (p->fd[i]>=0) close(p->fd[i]);
    unsigned char *a = p->data[0], *b = p->data[1];
    memset(p,0,sizeof(*p)); p->fd[0]=p->fd[1]=-1;
    p->data[0] = a; p->data[1] = b;
}
static bool nonblock(int fd)
{
    int flags=fcntl(fd,F_GETFL,0);return flags>=0 && fcntl(fd,F_SETFL,flags|O_NONBLOCK)==0;
}
static bool pump(pipe_t *p, unsigned direction)
{
    unsigned other = 1 - direction;
    for (unsigned burst = 0; burst < BURST_LIMIT; burst++) {
        if (p->used[direction] == p->sent[direction]) {
            p->used[direction] = p->sent[direction] = 0;
            if (!p->eof[direction]) {
                int n = recv(p->fd[direction], p->data[direction], BUFFER_BYTES, 0);
                if (n > 0) { p->used[direction] = n; p->touched = esp_timer_get_time(); }
                else if (n == 0) p->eof[direction] = true;
                else if (errno != EAGAIN && errno != EWOULDBLOCK) return false;
            }
        }
        if (p->used[direction] > p->sent[direction]) {
            int n = send(p->fd[other], p->data[direction] + p->sent[direction], p->used[direction] - p->sent[direction], 0);
            if (n > 0) { p->sent[direction] += n; p->touched = esp_timer_get_time(); }
            else if (n < 0 && errno != EAGAIN && errno != EWOULDBLOCK) return false;
        }
        if (p->eof[direction] && p->used[direction] == p->sent[direction] && !p->shutdown_sent[direction]) {
            shutdown(p->fd[other], SHUT_WR); p->shutdown_sent[direction] = true;
        }
        if (!p->used[direction] || p->used[direction] != p->sent[direction] || p->eof[direction]) break;
    }
    return true;
}
static void low_latency(int fd)
{
    int enabled = 1;
    setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &enabled, sizeof(enabled));
}
static void worker(void *unused)
{
    (void)unused;unsigned generation=0;
    for(unsigned i=0;i<CLIENTS;i++){
        memset(&pipes[i],0,sizeof(pipes[i])); pipes[i].fd[0]=pipes[i].fd[1]=-1;
        pipes[i].data[0] = buffer_pool + i * 2 * BUFFER_BYTES;
        pipes[i].data[1] = pipes[i].data[0] + BUFFER_BYTES;
    }
    int listener=socket(AF_INET,SOCK_STREAM,IPPROTO_TCP);
    int reuse=1;setsockopt(listener,SOL_SOCKET,SO_REUSEADDR,&reuse,sizeof(reuse));
    struct sockaddr_in address={.sin_family=AF_INET,.sin_port=htons(MATRIX_RELAY_PORT),.sin_addr.s_addr=INADDR_ANY};
    if(listener<0 || !nonblock(listener) || bind(listener,(struct sockaddr*)&address,sizeof(address))<0 || listen(listener,CLIENTS)<0) {
        if (listener >= 0) close(listener);
        vTaskDelete(NULL);
        return;
    }
    xSemaphoreTake(lock, portMAX_DELAY); config.listening = true; xSemaphoreGive(lock);
    for(;;) {
        xSemaphoreTake(lock,portMAX_DELAY);
        bool connected=config.connected;esp_ip4_addr_t primary=config.primary,target=config.target,aliases[2];
        memcpy(aliases,config.aliases,sizeof(aliases));unsigned next_generation=config.generation;
        xSemaphoreGive(lock);
        unsigned char *bytes=(unsigned char*)&target.addr;
        bool ready=connected && target.addr && matrix_alias_ready(bytes[2]);
        if(next_generation!=generation || !ready){for(unsigned i=0;i<CLIENTS;i++)close_pipe(&pipes[i]);generation=next_generation;}
        fd_set readable, writable; FD_ZERO(&readable); FD_ZERO(&writable);
        int highest = listener; unsigned free_slots = 0;
        for (unsigned i = 0; i < CLIENTS; i++) {
            pipe_t *p = &pipes[i];
            if (p->fd[0] < 0) { free_slots++; continue; }
            for (unsigned d = 0; d < 2; d++) {
                if (p->fd[d] > highest) highest = p->fd[d];
                if (p->connecting) { if (d == 1) FD_SET(p->fd[d], &writable); }
                else {
                    if (!p->eof[d] && p->used[d] == p->sent[d]) FD_SET(p->fd[d], &readable);
                    if (p->used[1-d] > p->sent[1-d]) FD_SET(p->fd[d], &writable);
                }
            }
        }
        // Keep excess browser connections in the listen backlog, rather than reset them.
        if (free_slots || !ready) FD_SET(listener, &readable);
        struct timeval wait = {.tv_usec = 100000};
        int events = select(highest + 1, &readable, &writable, NULL, &wait);
        int client = events > 0 && FD_ISSET(listener, &readable) ? accept(listener,NULL,NULL) : -1;
        if(client>=0) {
            unsigned slot=0;while(slot<CLIENTS && pipes[slot].fd[0]>=0)slot++;
            if(!ready || slot==CLIENTS || !nonblock(client))close(client);
            else {
                pipe_t *p=&pipes[slot];low_latency(client);p->fd[0]=client;p->fd[1]=socket(AF_INET,SOCK_STREAM,IPPROTO_TCP);
                esp_ip4_addr_t source=(primary.addr & PP_HTONL(0xffffff00))==(target.addr & PP_HTONL(0xffffff00)) ? primary : aliases[bytes[2]==10?0:1];
                struct sockaddr_in local={.sin_family=AF_INET,.sin_addr.s_addr=source.addr};
                struct sockaddr_in upstream={.sin_family=AF_INET,.sin_port=htons(80),.sin_addr.s_addr=target.addr};
                if(p->fd[1]<0 || !nonblock(p->fd[1]) || bind(p->fd[1],(struct sockaddr*)&local,sizeof(local))<0)close_pipe(p);
                else {
                    low_latency(p->fd[1]);
                    int rc=connect(p->fd[1],(struct sockaddr*)&upstream,sizeof(upstream));
                    if(rc<0 && errno!=EINPROGRESS)close_pipe(p);
                    else{p->connecting=rc<0;p->touched=esp_timer_get_time();p->deadline=p->touched+1500000;}
                }
            }
        }
        unsigned count=0;
        for(unsigned i=0;i<CLIENTS;i++) {
            pipe_t *p=&pipes[i];if(p->fd[0]<0)continue;
            if(p->connecting) {
                fd_set writefds;FD_ZERO(&writefds);FD_SET(p->fd[1],&writefds);struct timeval poll={0};
                if(select(p->fd[1]+1,NULL,&writefds,NULL,&poll)>0) {
                    int error=0;socklen_t len=sizeof(error);
                    if(getsockopt(p->fd[1],SOL_SOCKET,SO_ERROR,&error,&len)<0 || error){close_pipe(p);continue;}
                    p->connecting=false;
                }else if(esp_timer_get_time()>p->deadline){close_pipe(p);continue;}
            }
            if(!p->connecting && (!pump(p,0) || !pump(p,1) || (p->shutdown_sent[0] && p->shutdown_sent[1]))) {close_pipe(p);continue;}
            if(esp_timer_get_time()-p->touched>IDLE_US){close_pipe(p);continue;}
            count++;
        }
        xSemaphoreTake(lock,portMAX_DELAY);config.clients=count;xSemaphoreGive(lock);

    }
}
esp_err_t relay_init(void)
{
    lock=xSemaphoreCreateMutex();if(!lock)return ESP_ERR_NO_MEM;
    buffer_pool = heap_caps_malloc(CLIENTS * 2 * BUFFER_BYTES, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (!buffer_pool) return ESP_ERR_NO_MEM;
    return xTaskCreate(worker,"matrix_relay",6144,NULL,4,NULL)==pdPASS?ESP_OK:ESP_ERR_NO_MEM;
}
void relay_network(bool connected,esp_ip4_addr_t primary,const esp_ip4_addr_t aliases[2])
{
    xSemaphoreTake(lock,portMAX_DELAY);config.connected=connected;config.primary=primary;
    memcpy(config.aliases,aliases,sizeof(config.aliases));config.generation++;xSemaphoreGive(lock);
}
void relay_select(esp_ip4_addr_t target)
{
    xSemaphoreTake(lock,portMAX_DELAY);
    if (config.target.addr != target.addr) { config.target = target; config.generation++; }
    xSemaphoreGive(lock);
}
void relay_status(esp_ip4_addr_t *target,bool *ready,unsigned *clients)
{
    xSemaphoreTake(lock,portMAX_DELAY);*target=config.target;*clients=config.clients;
    *ready=config.listening && config.connected && config.target.addr && matrix_alias_ready(((unsigned char*)&config.target.addr)[2]);xSemaphoreGive(lock);
}

bool relay_available(void)
{
    xSemaphoreTake(lock, portMAX_DELAY); bool available = config.listening; xSemaphoreGive(lock); return available;
}

esp_err_t relay_check(esp_ip4_addr_t target)
{
    xSemaphoreTake(lock, portMAX_DELAY);
    esp_ip4_addr_t primary = config.primary;
    unsigned subnet = ((unsigned char *)&target.addr)[2];
    esp_ip4_addr_t source = (primary.addr & PP_HTONL(0xffffff00)) == (target.addr & PP_HTONL(0xffffff00)) ? primary : config.aliases[subnet == 10 ? 0 : 1];
    bool linked = config.connected;
    xSemaphoreGive(lock);
    if (!linked || !matrix_alias_ready(subnet)) return ESP_ERR_INVALID_STATE;
    int fd = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
    if (fd < 0) return ESP_ERR_NO_MEM;
    struct sockaddr_in local = {.sin_family = AF_INET, .sin_addr.s_addr = source.addr};
    struct sockaddr_in dest = {.sin_family = AF_INET, .sin_port = htons(80), .sin_addr.s_addr = target.addr};
    bool ok = false;
    if (nonblock(fd) && bind(fd, (struct sockaddr *)&local, sizeof(local)) == 0) {
        int result = connect(fd, (struct sockaddr *)&dest, sizeof(dest));
        if (result == 0) ok = true;
        else if (errno == EINPROGRESS) {
            fd_set writable; FD_ZERO(&writable); FD_SET(fd, &writable);
            struct timeval wait = {.tv_sec = 1, .tv_usec = 500000};
            if (select(fd + 1, NULL, &writable, NULL, &wait) > 0) {
                int error = 0; socklen_t len = sizeof(error);
                ok = getsockopt(fd, SOL_SOCKET, SO_ERROR, &error, &len) == 0 && error == 0;
            }
        }
    }
    close(fd);
    return ok ? ESP_OK : ESP_ERR_TIMEOUT;
}
