#include "remote_bridge.h"
#include <stdio.h>
#include <string.h>
#include <stdatomic.h>
#include <time.h>
#include <errno.h>
#include <fcntl.h>
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "freertos/queue.h"
#include "freertos/semphr.h"
#include "esp_heap_caps.h"
#include "esp_http_client.h"
#include "esp_websocket_client.h"
#include "esp_crt_bundle.h"
#include "esp_netif_sntp.h"
#include "esp_timer.h"
#include "esp_wifi.h"
#include "nvs.h"
#include "psa/crypto.h"
#include "mbedtls/base64.h"
#include "cJSON.h"
#include "lwip/sockets.h"
#include "wifi_scan.h"
#include "matrix_alias.h"
#define CHANNELS 8
#define CHUNK 4096
#define WINDOW 32768
#define FRAME_MAX 8192
#define DATA 1
#define CLOSE 2
#define HALF 3
#define CREDIT 4

typedef struct { uint32_t magic; char id[37]; char url[256]; } binding_t;
typedef struct { int type,len; unsigned char data[]; } frame_t;
typedef struct { int fd; uint32_t id,credit; bool connecting,eof,peer_eof; size_t offset,size; int64_t touched,deadline; unsigned char *buffer; } channel_t;
static channel_t channels[CHANNELS];
static unsigned char *pool;
static nvs_handle_t store;
static binding_t binding;
static psa_key_id_t key;
static QueueHandle_t frames;
static SemaphoreHandle_t lock;
static esp_websocket_client_handle_t client;
static atomic_bool network,authenticated,restart_client,waiting_approval;
static esp_ip4_addr_t primary,aliases[2];
static char error_text[128]="Not paired";
static bool sntp_started,pair_pending;
static atomic_bool reset_requested;
static SemaphoreHandle_t reset_done;
static esp_err_t reset_result;
static char pair_url[256],pair_code[25];
static unsigned char assembled[FRAME_MAX];
static size_t assembled_len;
static uint32_t read32(const unsigned char *p){return (uint32_t)p[0]<<24|(uint32_t)p[1]<<16|(uint32_t)p[2]<<8|p[3];}
static void put32(unsigned char *p,uint32_t v){p[0]=v>>24;p[1]=v>>16;p[2]=v>>8;p[3]=v;}
static void set_error(const char *text){xSemaphoreTake(lock,portMAX_DELAY);snprintf(error_text,sizeof(error_text),"%s",text);xSemaphoreGive(lock);}
static void close_channel(channel_t *c){if(c->fd>=0)close(c->fd);unsigned char *p=c->buffer;memset(c,0,sizeof(*c));c->fd=-1;c->buffer=p;}
static bool send_text(cJSON *json){char *s=cJSON_PrintUnformatted(json);bool ok=s&&client&&esp_websocket_client_send_text(client,s,strlen(s),pdMS_TO_TICKS(2000))>=0;cJSON_free(s);cJSON_Delete(json);return ok;}
static bool send_control(const char *op,uint32_t id){cJSON*j=cJSON_CreateObject();cJSON_AddStringToObject(j,"op",op);cJSON_AddNumberToObject(j,"stream",id);return send_text(j);}
static bool send_binary(int kind,uint32_t id,const unsigned char *data,size_t len){unsigned char packet[CHUNK+5];if(len>CHUNK)return false;packet[0]=kind;put32(packet+1,id);if(len)memcpy(packet+5,data,len);return client&&esp_websocket_client_send_bin(client,(char*)packet,len+5,pdMS_TO_TICKS(2000))>=0;}
static void websocket_event(void *unused,esp_event_base_t base,int32_t event,void *payload){
 (void)unused;(void)base;
 if(event==WEBSOCKET_EVENT_DISCONNECTED||event==WEBSOCKET_EVENT_ERROR||event==WEBSOCKET_EVENT_CLOSED||event==WEBSOCKET_EVENT_FINISH){atomic_store(&authenticated,false);atomic_store(&restart_client,true);return;}
 if(event!=WEBSOCKET_EVENT_DATA)return;
 esp_websocket_event_data_t *d=payload;
 if(d->op_code!=1&&d->op_code!=2&&d->op_code!=0)return;
 if(d->payload_len<0||d->payload_len>FRAME_MAX||d->payload_offset<0||d->data_len<0||(size_t)(d->payload_offset+d->data_len)>FRAME_MAX){atomic_store(&restart_client,true);return;}
 if(d->payload_offset==0)assembled_len=0;
 if((size_t)d->payload_offset!=assembled_len){atomic_store(&restart_client,true);return;}
 memcpy(assembled+assembled_len,d->data_ptr,d->data_len);assembled_len+=d->data_len;
 if(assembled_len!=(size_t)d->payload_len)return;
 frame_t *f=heap_caps_malloc(sizeof(*f)+assembled_len+1,MALLOC_CAP_SPIRAM|MALLOC_CAP_8BIT);
 if(!f){atomic_store(&restart_client,true);return;}f->type=d->op_code;f->len=assembled_len;memcpy(f->data,assembled,assembled_len);f->data[assembled_len]=0;
 if(xQueueSend(frames,&f,0)!=pdTRUE){heap_caps_free(f);atomic_store(&restart_client,true);}
}
static esp_err_t load_key(void){
 if(key)return ESP_OK;
 if(psa_crypto_init()!=PSA_SUCCESS)return ESP_FAIL;
 unsigned char secret[32];size_t size=sizeof(secret);
 psa_key_attributes_t attrs=PSA_KEY_ATTRIBUTES_INIT;psa_set_key_type(&attrs,PSA_KEY_TYPE_ECC_KEY_PAIR(PSA_ECC_FAMILY_SECP_R1));psa_set_key_bits(&attrs,256);psa_set_key_usage_flags(&attrs,PSA_KEY_USAGE_SIGN_HASH|PSA_KEY_USAGE_EXPORT);psa_set_key_algorithm(&attrs,PSA_ALG_ECDSA(PSA_ALG_SHA_256));
 psa_status_t result;
 if(nvs_get_blob(store,"key",secret,&size)==ESP_OK&&size==32)result=psa_import_key(&attrs,secret,size,&key);
 else{result=psa_generate_key(&attrs,&key);if(result==PSA_SUCCESS){result=psa_export_key(key,secret,sizeof(secret),&size);if(result==PSA_SUCCESS){if(nvs_set_blob(store,"key",secret,size)!=ESP_OK||nvs_commit(store)!=ESP_OK)result=PSA_ERROR_STORAGE_FAILURE;}}}
 memset(secret,0,sizeof(secret));psa_reset_key_attributes(&attrs);return result==PSA_SUCCESS?ESP_OK:ESP_FAIL;
}
static bool public_pem(char *out,size_t capacity){unsigned char point[65],der[91];size_t len=0,encoded=0;const unsigned char prefix[]={0x30,0x59,0x30,0x13,0x06,0x07,0x2a,0x86,0x48,0xce,0x3d,0x02,0x01,0x06,0x08,0x2a,0x86,0x48,0xce,0x3d,0x03,0x01,0x07,0x03,0x42,0x00};if(psa_export_public_key(key,point,sizeof(point),&len)!=PSA_SUCCESS||len!=65)return false;memcpy(der,prefix,26);memcpy(der+26,point,65);char b64[128];if(mbedtls_base64_encode((unsigned char*)b64,sizeof(b64)-1,&encoded,der,sizeof(der)))return false;b64[encoded]=0;return snprintf(out,capacity,"-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n",b64)<capacity;}
static void authenticate(const char *nonce){if(strlen(nonce)!=64||load_key()!=ESP_OK){atomic_store(&restart_client,true);return;}char message[160];snprintf(message,sizeof(message),"biomatrix-bridge-v1\n%s\n%s",binding.id,nonce);unsigned char hash[32],sig[80];size_t hlen=0,slen=0,encoded=0;char b64[128];if(psa_hash_compute(PSA_ALG_SHA_256,(unsigned char*)message,strlen(message),hash,sizeof(hash),&hlen)!=PSA_SUCCESS||psa_sign_hash(key,PSA_ALG_ECDSA(PSA_ALG_SHA_256),hash,hlen,sig,sizeof(sig),&slen)!=PSA_SUCCESS||mbedtls_base64_encode((unsigned char*)b64,sizeof(b64)-1,&encoded,sig,slen)){atomic_store(&restart_client,true);return;}b64[encoded]=0;cJSON*j=cJSON_CreateObject();cJSON_AddStringToObject(j,"op","auth");cJSON_AddStringToObject(j,"bridge_id",binding.id);cJSON_AddStringToObject(j,"signature",b64);if(!send_text(j))atomic_store(&restart_client,true);}
static void pair_now(void){
 char url[300],code[25];xSemaphoreTake(lock,portMAX_DELAY);snprintf(url,sizeof(url),"%s/api/bridges/pair",pair_url);memcpy(code,pair_code,sizeof(code));pair_pending=false;memset(pair_code,0,sizeof(pair_code));xSemaphoreGive(lock);
 if(time(NULL)<1704067200){set_error("Clock not synchronized yet. Wait and retry pairing.");return;}
 if(load_key()!=ESP_OK){set_error("Could not create bridge identity");return;}
 char pem[256],mac[32];uint8_t address[6];esp_wifi_get_mac(WIFI_IF_STA,address);snprintf(mac,sizeof(mac),"%02x:%02x:%02x:%02x:%02x:%02x",address[0],address[1],address[2],address[3],address[4],address[5]);if(!public_pem(pem,sizeof(pem))){set_error("Could not export public identity");return;}
 cJSON*j=cJSON_CreateObject();cJSON_AddStringToObject(j,"code",code);cJSON_AddStringToObject(j,"public_key",pem);cJSON_AddStringToObject(j,"hardware_id",mac);char*body=cJSON_PrintUnformatted(j);cJSON_Delete(j);memset(code,0,sizeof(code));
 esp_http_client_config_t config={.url=url,.timeout_ms=10000,.crt_bundle_attach=esp_crt_bundle_attach,.disable_auto_redirect=true,.buffer_size=2048};esp_http_client_handle_t http=esp_http_client_init(&config);if(!http){cJSON_free(body);set_error("Pairing client unavailable");return;}esp_http_client_set_method(http,HTTP_METHOD_POST);esp_http_client_set_header(http,"Content-Type","application/json");esp_http_client_set_header(http,"Accept","application/json");esp_http_client_set_post_field(http,body,strlen(body));
 char response[1024]={0};bool ok=esp_http_client_open(http,strlen(body))==ESP_OK;
 if(ok)ok=esp_http_client_write(http,body,strlen(body))==(int)strlen(body);
 if(ok)esp_http_client_fetch_headers(http);
 int used=0;if(ok){while(used<(int)sizeof(response)-1){int n=esp_http_client_read(http,response+used,sizeof(response)-1-used);if(n<=0)break;used+=n;}ok=esp_http_client_get_status_code(http)==200;}
 esp_http_client_close(http);esp_http_client_cleanup(http);memset(body,0,strlen(body));cJSON_free(body);
 if(!ok){set_error("Pairing rejected or server unreachable. Check the code, server and network.");return;}
 j=cJSON_Parse(response);cJSON*id=j?cJSON_GetObjectItem(j,"bridge_id"):NULL,*ws=j?cJSON_GetObjectItem(j,"websocket_url"):NULL;
 if(!cJSON_IsString(id)||strlen(id->valuestring)!=36||!cJSON_IsString(ws)||strlen(ws->valuestring)>255||strncmp(ws->valuestring,"wss://",6)){cJSON_Delete(j);set_error("Invalid pairing response");return;}
 binding_t next={.magic=0x4d425232};snprintf(next.id,sizeof(next.id),"%s",id->valuestring);snprintf(next.url,sizeof(next.url),"%s",ws->valuestring);cJSON_Delete(j);
 if(nvs_set_blob(store,"config",&next,sizeof(next))!=ESP_OK||nvs_commit(store)!=ESP_OK){set_error("Identity was paired but local binding could not be saved. Retry the same code.");return;}
 xSemaphoreTake(lock,portMAX_DELAY);binding=next;xSemaphoreGive(lock);atomic_store(&restart_client,true);atomic_store(&waiting_approval,true);set_error("Request submitted. Approve this ESP in Biomatrix.");
}
static channel_t *find(uint32_t id){for(unsigned i=0;i<CHANNELS;i++)if(channels[i].fd>=0&&channels[i].id==id)return &channels[i];return NULL;}
static void open_channel(cJSON*j){cJSON*stream=cJSON_GetObjectItem(j,"stream"),*ip=cJSON_GetObjectItem(j,"ip"),*port=cJSON_GetObjectItem(j,"port");if(!cJSON_IsNumber(stream)||stream->valuedouble<1||stream->valuedouble>UINT32_MAX||!cJSON_IsString(ip)||!cJSON_IsNumber(port)||port->valueint!=80)return;uint32_t id=(uint32_t)stream->valuedouble;esp_ip4_addr_t target;if(esp_netif_str_to_ip4(ip->valuestring,&target)!=ESP_OK||!((unsigned char*)&target.addr)[3]||((unsigned char*)&target.addr)[3]==255||strncmp(ip->valuestring,"192.168.",8)||find(id)){send_control("error",id);return;}
 channel_t*c=NULL;for(unsigned i=0;i<CHANNELS;i++)if(channels[i].fd<0){c=&channels[i];break;}if(!c){send_control("error",id);return;}
 xSemaphoreTake(lock,portMAX_DELAY);esp_ip4_addr_t local=primary;if((primary.addr&PP_HTONL(0xffffff00))!=(target.addr&PP_HTONL(0xffffff00))){unsigned subnet=((unsigned char*)&target.addr)[2];if(subnet!=10&&subnet!=50){xSemaphoreGive(lock);send_control("error",id);return;}local=aliases[subnet==10?0:1];}xSemaphoreGive(lock);
 c->fd=socket(AF_INET,SOCK_STREAM,IPPROTO_TCP);if(c->fd<0){send_control("error",id);return;}c->id=id;c->credit=WINDOW;int flags=fcntl(c->fd,F_GETFL,0);fcntl(c->fd,F_SETFL,flags|O_NONBLOCK);int yes=1;setsockopt(c->fd,IPPROTO_TCP,TCP_NODELAY,&yes,sizeof(yes));struct sockaddr_in from={.sin_family=AF_INET,.sin_addr.s_addr=local.addr},to={.sin_family=AF_INET,.sin_port=htons(80),.sin_addr.s_addr=target.addr};if(bind(c->fd,(struct sockaddr*)&from,sizeof(from))!=0){send_control("error",id);close_channel(c);return;}int result=connect(c->fd,(struct sockaddr*)&to,sizeof(to));if(result!=0&&errno!=EINPROGRESS){send_control("error",id);close_channel(c);return;}c->connecting=result!=0;c->touched=esp_timer_get_time();c->deadline=c->touched+2000000;if(!c->connecting)send_control("opened",id);
}
static void handle(frame_t*f){
 if(f->type==1){cJSON*j=cJSON_Parse((char*)f->data);cJSON*op=j?cJSON_GetObjectItem(j,"op"):NULL;if(cJSON_IsString(op)){if(!strcmp(op->valuestring,"pending")){atomic_store(&waiting_approval,true);set_error("Waiting for Biomatrix approval");}else if(!strcmp(op->valuestring,"rejected")){xSemaphoreTake(lock,portMAX_DELAY);memset(&binding,0,sizeof(binding));xSemaphoreGive(lock);nvs_erase_key(store,"config");nvs_erase_key(store,"lastgood");nvs_commit(store);atomic_store(&waiting_approval,false);atomic_store(&restart_client,true);set_error("Request rejected, expired or revoked. Generate a new code to request again.");}else if(!strcmp(op->valuestring,"challenge")){cJSON*n=cJSON_GetObjectItem(j,"nonce");if(cJSON_IsString(n))authenticate(n->valuestring);}else if(!strcmp(op->valuestring,"ready")){atomic_store(&authenticated,true);atomic_store(&waiting_approval,false);set_error("Connected");nvs_set_blob(store,"lastgood",&binding,sizeof(binding));nvs_commit(store);}else if(!strcmp(op->valuestring,"heartbeat")){cJSON*answer=cJSON_CreateObject();cJSON_AddStringToObject(answer,"op","heartbeat");send_text(answer);}else if(!strcmp(op->valuestring,"open")&&atomic_load(&authenticated))open_channel(j);}cJSON_Delete(j);return;}
 if(!atomic_load(&authenticated)||f->len<5||f->len>CHUNK+5){atomic_store(&restart_client,true);return;}channel_t*c=find(read32(f->data+1));if(!c)return;size_t len=f->len-5;
 if(f->data[0]==DATA){if(len>WINDOW-c->size){send_binary(CLOSE,c->id,NULL,0);close_channel(c);return;}if(c->offset+c->size+len>WINDOW){memmove(c->buffer,c->buffer+c->offset,c->size);c->offset=0;}memcpy(c->buffer+c->offset+c->size,f->data+5,len);c->size+=len;}
 else if(f->data[0]==CREDIT&&len==4){uint32_t amount=read32(f->data+5);if(!amount||amount>WINDOW||c->credit>WINDOW-amount){close_channel(c);return;}c->credit+=amount;}
 else if(f->data[0]==HALF)c->peer_eof=true;
 else if(f->data[0]==CLOSE)close_channel(c);
 else atomic_store(&restart_client,true);
}
static void stop_client(void){atomic_store(&authenticated,false);for(unsigned i=0;i<CHANNELS;i++)close_channel(&channels[i]);if(client){esp_websocket_client_stop(client);esp_websocket_client_destroy(client);client=NULL;}frame_t*f;while(xQueueReceive(frames,&f,0)==pdTRUE)heap_caps_free(f);assembled_len=0;}
static void worker(void *unused){(void)unused;unsigned char packet[CHUNK];int64_t retry=0;unsigned failures=0;for(;;){
 frame_t*f;unsigned drained=0;while(drained++<16&&xQueueReceive(frames,&f,0)==pdTRUE){handle(f);heap_caps_free(f);}
 if (atomic_exchange(&reset_requested,false)) {
    stop_client(); xSemaphoreTake(lock,portMAX_DELAY); memset(&binding,0,sizeof(binding)); pair_pending=false; memset(pair_code,0,sizeof(pair_code)); xSemaphoreGive(lock);
    if(key){psa_destroy_key(key);key=0;} reset_result=nvs_erase_all(store); if(reset_result==ESP_OK)reset_result=nvs_commit(store); xSemaphoreGive(reset_done);
 }
 if(!atomic_load(&network)||atomic_exchange(&restart_client,false)){
    bool had_client=client!=NULL; stop_client();
    if(had_client){unsigned seconds=failures<5?(1U<<failures):30;retry=esp_timer_get_time()+seconds*1000000LL;if(binding.id[0])set_error(atomic_load(&waiting_approval)?"Waiting for Biomatrix approval; retrying":"Gateway disconnected; retrying");}
 }
 bool paired;xSemaphoreTake(lock,portMAX_DELAY);paired=binding.id[0];bool pending=pair_pending;xSemaphoreGive(lock);
 if(atomic_load(&network)&&pending)pair_now();
 if(atomic_load(&network)&&paired&&!client&&time(NULL)>=1704067200&&esp_timer_get_time()>=retry){esp_websocket_client_config_t config={.uri=binding.url,.buffer_size=8192,.task_stack=8192,.crt_bundle_attach=esp_crt_bundle_attach,.disable_auto_reconnect=true,.network_timeout_ms=10000};client=esp_websocket_client_init(&config);if(client){esp_websocket_register_events(client,WEBSOCKET_EVENT_ANY,websocket_event,NULL);if(esp_websocket_client_start(client)!=ESP_OK)atomic_store(&restart_client,true);}retry=esp_timer_get_time()+30000000;failures++;if(failures>=5&&!atomic_load(&authenticated)){binding_t good;size_t size=sizeof(good);if(nvs_get_blob(store,"lastgood",&good,&size)==ESP_OK&&size==sizeof(good)&&good.magic==binding.magic&&!strcmp(good.id,binding.id)&&strcmp(good.url,binding.url)){xSemaphoreTake(lock,portMAX_DELAY);binding=good;xSemaphoreGive(lock);set_error("Restored last working gateway");}failures=0;}}

 if(atomic_load(&authenticated)){failures=0;}
 if(atomic_load(&authenticated))for(unsigned i=0;i<CHANNELS;i++){channel_t*c=&channels[i];if(c->fd<0)continue;
  if(c->connecting){fd_set ready;FD_ZERO(&ready);FD_SET(c->fd,&ready);struct timeval poll={0};if(select(c->fd+1,NULL,&ready,NULL,&poll)>0){int e=0;socklen_t size=sizeof(e);if(getsockopt(c->fd,SOL_SOCKET,SO_ERROR,&e,&size)||e){send_control("error",c->id);close_channel(c);continue;}c->connecting=false;send_control("opened",c->id);}else if(esp_timer_get_time()>c->deadline){send_control("error",c->id);close_channel(c);continue;}else continue;}
  if(c->size){int n=send(c->fd,c->buffer+c->offset,c->size,0);if(n>0){c->offset+=n;c->size-=n;c->touched=esp_timer_get_time();unsigned char credit[4];put32(credit,n);if(!send_binary(CREDIT,c->id,credit,4))atomic_store(&restart_client,true);}else if(n<0&&errno!=EAGAIN&&errno!=EWOULDBLOCK){send_binary(CLOSE,c->id,NULL,0);close_channel(c);continue;}}
  if(c->peer_eof&&!c->size)shutdown(c->fd,SHUT_WR);
  if(!c->eof&&c->credit){int n=recv(c->fd,packet,c->credit<CHUNK?c->credit:CHUNK,0);if(n>0){if(send_binary(DATA,c->id,packet,n)){c->credit-=n;c->touched=esp_timer_get_time();}else atomic_store(&restart_client,true);}else if(n==0){c->eof=true;send_binary(HALF,c->id,NULL,0);}else if(errno!=EAGAIN&&errno!=EWOULDBLOCK){send_binary(CLOSE,c->id,NULL,0);close_channel(c);continue;}}
  if((c->eof&&c->peer_eof&&!c->size&&c->credit==WINDOW)||esp_timer_get_time()-c->touched>60000000){send_binary(CLOSE,c->id,NULL,0);close_channel(c);}
 }
 vTaskDelay(1);
}}
esp_err_t remote_bridge_init(void){lock=xSemaphoreCreateMutex();reset_done=xSemaphoreCreateBinary();frames=xQueueCreate(32,sizeof(frame_t*));pool=heap_caps_malloc(CHANNELS*WINDOW,MALLOC_CAP_SPIRAM|MALLOC_CAP_8BIT);if(!lock||!reset_done||!frames||!pool)return ESP_ERR_NO_MEM;ESP_ERROR_CHECK(nvs_open("bridgeagent",NVS_READWRITE,&store));size_t size=sizeof(binding);if(nvs_get_blob(store,"config",&binding,&size)!=ESP_OK||size!=sizeof(binding)||binding.magic!=0x4d425232)memset(&binding,0,sizeof(binding));for(unsigned i=0;i<CHANNELS;i++){channels[i].fd=-1;channels[i].buffer=pool+i*WINDOW;}return xTaskCreate(worker,"remote_bridge",12288,NULL,4,NULL)==pdPASS?ESP_OK:ESP_ERR_NO_MEM;}
void remote_bridge_network(bool linked,esp_ip4_addr_t address,const esp_ip4_addr_t sources[2]){xSemaphoreTake(lock,portMAX_DELAY);primary=address;memcpy(aliases,sources,sizeof(aliases));xSemaphoreGive(lock);atomic_store(&network,linked);atomic_store(&restart_client,true);if(linked&&!sntp_started){esp_sntp_config_t config=ESP_NETIF_SNTP_DEFAULT_CONFIG("pool.ntp.org");if(esp_netif_sntp_init(&config)==ESP_OK)sntp_started=true;}}
esp_err_t remote_bridge_pair(const char *url,const char *code){if(strncmp(url,"https://",8)||strlen(url)>240||strlen(code)!=24||strchr(url,'\n')||strchr(url,'?')||strchr(url,'#'))return ESP_ERR_INVALID_ARG;if(!atomic_load(&network))return ESP_ERR_INVALID_STATE;xSemaphoreTake(lock,portMAX_DELAY);if(binding.id[0]||pair_pending){xSemaphoreGive(lock);return ESP_ERR_INVALID_STATE;}snprintf(pair_url,sizeof(pair_url),"%s",url);size_t n=strlen(pair_url);if(n&&pair_url[n-1]=='/')pair_url[n-1]=0;snprintf(pair_code,sizeof(pair_code),"%s",code);pair_pending=true;snprintf(error_text,sizeof(error_text),"Pairing requested");xSemaphoreGive(lock);return ESP_OK;}
void remote_bridge_status(char*out,size_t size){xSemaphoreTake(lock,portMAX_DELAY);char id[80],url[512],error[260];json_quote(binding.id,id,sizeof(id));json_quote(binding.url,url,sizeof(url));json_quote(error_text,error,sizeof(error));snprintf(out,size,"{\"paired\":%s,\"connected\":%s,\"awaiting_approval\":%s,\"bridge_id\":%s,\"gateway\":%s,\"message\":%s,\"clock_ready\":%s}",binding.id[0]?"true":"false",atomic_load(&authenticated)?"true":"false",atomic_load(&waiting_approval)?"true":"false",id,url,error,time(NULL)>=1704067200?"true":"false");xSemaphoreGive(lock);}
esp_err_t remote_bridge_reset(void){
 atomic_store(&reset_requested,true);
 if(xSemaphoreTake(reset_done,pdMS_TO_TICKS(12000))!=pdTRUE)return ESP_ERR_TIMEOUT;
 return reset_result;
}
