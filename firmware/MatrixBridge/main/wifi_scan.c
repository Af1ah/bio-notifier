#include "wifi_scan.h"
#include <stdio.h>
#include <string.h>
#include <stdatomic.h>
#include "esp_wifi.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "freertos/semphr.h"
static SemaphoreHandle_t lock;
static atomic_bool busy;
static wifi_ap_record_t records[24];
static uint16_t count;
static esp_err_t error;
void json_quote(const char *text,char *out,size_t size)
{
    size_t used=0;
    if(size<3)return;
    out[used++]='"';
    for(const unsigned char *p=(const unsigned char *)text;*p && used+7<size;p++) {
        if(*p=='"'||*p=='\\'){out[used++]='\\';out[used++]=*p;}
        else if(*p<32)used+=snprintf(out+used,size-used,"\\u%04x",*p);
        else out[used++]=*p;
    }
    out[used++]='"';out[used]=0;
}
static void begin_worker(void *arg)
{
    bool linked=(bool)(intptr_t)arg;
    if(!linked){esp_wifi_disconnect();vTaskDelay(pdMS_TO_TICKS(200));}
    wifi_scan_config_t config={.show_hidden=false,.scan_type=WIFI_SCAN_TYPE_ACTIVE,.scan_time.active={.min=40,.max=100}};
    esp_err_t err=esp_wifi_scan_start(&config,false);
    if(err!=ESP_OK) {
        xSemaphoreTake(lock,portMAX_DELAY);error=err;xSemaphoreGive(lock);
        atomic_store(&busy,false);esp_wifi_connect();
    }
    vTaskDelete(NULL);
}
esp_err_t wifi_scan_init(void){lock=xSemaphoreCreateMutex();return lock?ESP_OK:ESP_ERR_NO_MEM;}
esp_err_t wifi_scan_begin(bool linked)
{
    bool expected=false;if(!atomic_compare_exchange_strong(&busy,&expected,true))return ESP_ERR_INVALID_STATE;
    xSemaphoreTake(lock,portMAX_DELAY);error=ESP_OK;count=0;xSemaphoreGive(lock);
    if(xTaskCreate(begin_worker,"wifi_scan",3072,(void*)(intptr_t)linked,3,NULL)!=pdPASS){atomic_store(&busy,false);return ESP_ERR_NO_MEM;}
    return ESP_OK;
}
void wifi_scan_done(void)
{
    xSemaphoreTake(lock,portMAX_DELAY);count=24;error=esp_wifi_scan_get_ap_records(&count,records);xSemaphoreGive(lock);
    atomic_store(&busy,false);
}
bool wifi_scan_busy(void){return atomic_load(&busy);}
void wifi_scan_status(char *out,size_t size)
{
    xSemaphoreTake(lock,portMAX_DELAY);
    size_t used=snprintf(out,size,"{\"running\":%s,\"error\":%d,\"networks\":[",wifi_scan_busy()?"true":"false",error);
    for(unsigned i=0;i<count && used+256<size;i++) {
        records[i].ssid[32]=0;char ssid[224];json_quote((char*)records[i].ssid,ssid,sizeof(ssid));
        used+=snprintf(out+used,size-used,"%s{\"ssid\":%s,\"rssi\":%d,\"channel\":%u,\"secured\":%s}",i?",":"",ssid,records[i].rssi,records[i].primary,records[i].authmode==WIFI_AUTH_OPEN?"false":"true");
    }
    if(used+3<=size)memcpy(out+used,"]}",3);else snprintf(out,size,"{}");
    xSemaphoreGive(lock);
}
