#include "discovery.h"
#include "matrix_alias.h"
#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <stdatomic.h>
#include <stdio.h>
#include <string.h>
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "freertos/semphr.h"
#include "esp_timer.h"
#include "lwip/sockets.h"

#define MAX_RESULTS 32
#define HOSTS_PER_SUBNET 50
#define TOTAL_HOSTS (2 * HOSTS_PER_SUBNET)
static SemaphoreHandle_t lock;
static atomic_bool cancelled;
static struct {
    bool running, complete, truncated;
    unsigned checked, count;
    struct { esp_ip4_addr_t ip; bool matrix; } devices[MAX_RESULTS];
} state;
static esp_ip4_addr_t sources[2];
static void worker(void *unused)
{
    (void)unused;
    char response[4097];
    for (unsigned host = 0; host < TOTAL_HOSTS && !atomic_load(&cancelled); host++) {
        unsigned subnet = host < HOSTS_PER_SUBNET ? 10 : 50;
        esp_ip4_addr_t ip;
        IP4_ADDR(&ip, 192, 168, subnet, host % HOSTS_PER_SUBNET + 1);
        bool service = false, matrix = false;
        int fd = -1;
        if (ip.addr != sources[0].addr && ip.addr != sources[1].addr && matrix_alias_ready(subnet)) {
            fd = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
            if (fd >= 0) {
                int flags = fcntl(fd, F_GETFL, 0);
                fcntl(fd, F_SETFL, flags | O_NONBLOCK);
                struct sockaddr_in local = {.sin_family = AF_INET, .sin_addr.s_addr = sources[host < HOSTS_PER_SUBNET ? 0 : 1].addr};
                struct sockaddr_in target = {.sin_family = AF_INET, .sin_port = htons(80), .sin_addr.s_addr = ip.addr};
                bool connected = false;
                if (bind(fd, (struct sockaddr *)&local, sizeof(local)) == 0) {
                    int rc = connect(fd, (struct sockaddr *)&target, sizeof(target));
                    if (rc == 0) connected = true;
                    else if (errno == EINPROGRESS) {
                        fd_set writable; FD_ZERO(&writable); FD_SET(fd, &writable);
                        struct timeval wait = {.tv_usec = 250000};
                        if (select(fd + 1, NULL, &writable, NULL, &wait) > 0) {
                            int err = 0; socklen_t len = sizeof(err);
                            connected = getsockopt(fd, SOL_SOCKET, SO_ERROR, &err, &len) == 0 && err == 0;
                        }
                    }
                }
                if (connected && !atomic_load(&cancelled)) {
                    char request[128];
                    int len = snprintf(request, sizeof(request), "GET / HTTP/1.0\r\nHost: " IPSTR "\r\nConnection: close\r\n\r\n", IP2STR(&ip));
                    size_t sent = 0, used = 0;
                    int64_t until = esp_timer_get_time() + 500000;
                    while (esp_timer_get_time() < until && sent < (size_t)len && !atomic_load(&cancelled)) {
                        int n = send(fd, request + sent, len - sent, 0);
                        if (n > 0) sent += n;
                        else if (errno != EAGAIN && errno != EWOULDBLOCK) break;
                        vTaskDelay(pdMS_TO_TICKS(10));
                    }
                    while (sent == (size_t)len && used < sizeof(response) - 1 && esp_timer_get_time() < until && !atomic_load(&cancelled)) {
                        int n = recv(fd, response + used, sizeof(response) - 1 - used, 0);
                        if (n > 0) used += n;
                        else if (n == 0 || (errno != EAGAIN && errno != EWOULDBLOCK)) break;
                        vTaskDelay(pdMS_TO_TICKS(10));
                    }
                    response[used] = 0;
                    service = used >= 5 && !memcmp(response, "HTTP/", 5);
                    for (size_t i = 0; i < used; i++) response[i] = tolower((unsigned char)response[i]);
                    matrix = service && (strstr(response, "matrix comsec") || strstr(response, "matrix_logo") || strstr(response, "cosec"));
                }
                close(fd);
            }
        }
        xSemaphoreTake(lock, portMAX_DELAY);
        state.checked = host + 1;
        if (service) {
            if (state.count < MAX_RESULTS) { state.devices[state.count].ip = ip; state.devices[state.count++].matrix = matrix; }
            else state.truncated = true;
        }
        xSemaphoreGive(lock);
        vTaskDelay(pdMS_TO_TICKS(10));
    }
    xSemaphoreTake(lock, portMAX_DELAY);
    state.complete = !atomic_load(&cancelled) && state.checked == TOTAL_HOSTS;
    state.running = false;
    xSemaphoreGive(lock);
    vTaskDelete(NULL);
}
esp_err_t discovery_start(esp_ip4_addr_t primary, const esp_ip4_addr_t aliases[2])
{
    if (!lock) return ESP_ERR_INVALID_STATE;
    xSemaphoreTake(lock, portMAX_DELAY);
    if (state.running) { xSemaphoreGive(lock); return ESP_ERR_INVALID_STATE; }
    memset(&state, 0, sizeof(state)); state.running = true;
    atomic_store(&cancelled, false);
    for (unsigned i = 0; i < 2; i++)
        sources[i] = (primary.addr & PP_HTONL(0xffffff00)) == (aliases[i].addr & PP_HTONL(0xffffff00)) ? primary : aliases[i];
    if (xTaskCreate(worker, "matrix_scan", 8192, NULL, 4, NULL) != pdPASS) {
        state.running = false; xSemaphoreGive(lock); return ESP_ERR_NO_MEM;
    }
    xSemaphoreGive(lock); return ESP_OK;
}
void discovery_cancel(void) { atomic_store(&cancelled, true); }
void discovery_status(char *out, size_t size)
{
    if (!lock) { snprintf(out, size, "{\"running\":false,\"complete\":false,\"checked\":0,\"total\":%u,\"truncated\":false,\"devices\":[]}", TOTAL_HOSTS); return; }
    xSemaphoreTake(lock, portMAX_DELAY);
    size_t used = snprintf(out, size, "{\"running\":%s,\"complete\":%s,\"checked\":%u,\"total\":%u,\"truncated\":%s,\"devices\":[", state.running ? "true" : "false", state.complete ? "true" : "false", state.checked, TOTAL_HOSTS, state.truncated ? "true" : "false");
    for (unsigned i = 0; i < state.count && used < size; i++)
        used += snprintf(out + used, size - used, "%s{\"ip\":\"" IPSTR "\",\"matrix\":%s}", i ? "," : "", IP2STR(&state.devices[i].ip), state.devices[i].matrix ? "true" : "false");
    if (used + 3 <= size) memcpy(out + used, "]}", 3);
    else snprintf(out, size, "{}");
    xSemaphoreGive(lock);
}

bool discovery_running(void)
{
    if (!lock) return false;
    xSemaphoreTake(lock, portMAX_DELAY);
    bool running = state.running;
    xSemaphoreGive(lock);
    return running;
}

esp_err_t discovery_init(void)
{
    lock = xSemaphoreCreateMutex();
    return lock ? ESP_OK : ESP_ERR_NO_MEM;
}
