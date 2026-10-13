#include "indicators.h"
#include "rgb_led.h"
#include "relay.h"
#include "matrix_alias.h"
#include "discovery.h"
#include <stdatomic.h>
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "esp_http_client.h"
#include "esp_log.h"
static atomic_bool wifi,configured;
static atomic_int internet=-1;
static void led_task(void *unused)
{
    (void)unused;unsigned phase=0;
    for(;;) {
        bool on=(phase++%10)<5;uint8_t r=0,g=0,b=0;
        esp_ip4_addr_t target;bool ready;unsigned clients;relay_status(&target,&ready,&clients);
        if(matrix_alias_conflict()) {if(phase%2){r=24;}}
        else if(!atomic_load(&wifi)) {b=atomic_load(&configured)?(on?20:0):16;}
        else if(!matrix_alias_ready(10)||!matrix_alias_ready(50)){if(on)r=20;}
        else if(discovery_running()){if(on){r=16;b=20;}}
        else if(clients){if(on){g=16;b=20;}}
        else if(atomic_load(&internet)==1)g=16;
        else if(atomic_load(&internet)==0){r=22;g=8;}
        else g=16;
        esp_err_t err=rgb_led_set(r,g,b);
        if(err!=ESP_OK)ESP_LOGW("status_led","LED update: %s",esp_err_to_name(err));
        vTaskDelay(pdMS_TO_TICKS(100));
    }
}
esp_err_t indicators_init(void)
{
    esp_err_t err=rgb_led_init();if(err!=ESP_OK)return err;
    // Internet probes are disabled at the user's request.
    return xTaskCreate(led_task,"status_led",3072,NULL,3,NULL)==pdPASS?ESP_OK:ESP_ERR_NO_MEM;
}
void indicators_wifi(bool linked,bool saved)
{
    atomic_store(&configured,saved);atomic_store(&wifi,linked);if(!linked)atomic_store(&internet,-1);
}
int indicators_internet(void){return atomic_load(&internet);}
