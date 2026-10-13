#include <stdio.h>
#include <string.h>
#include <stdatomic.h>
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "esp_wifi.h"
#include "esp_event.h"
#include "esp_http_server.h"
#include "esp_log.h"
#include "esp_system.h"
#include "nvs_flash.h"
#include "mbedtls/base64.h"
#include "matrix_alias.h"
#include "discovery.h"
#include "relay.h"
#include "indicators.h"
#include "wifi_scan.h"
#include "esp_heap_caps.h"
#include "esp_psram.h"
#include "esp_flash.h"
#include "esp_chip_info.h"
#include "esp_timer.h"
#include "remote_bridge.h"
#include "esp_random.h"
#include "cJSON.h"

_Static_assert(sizeof(CONFIG_MATRIX_SETUP_PASSWORD) >= 9 && sizeof(CONFIG_MATRIX_SETUP_PASSWORD) <= 33, "Configure setup Wi-Fi password in menuconfig");
_Static_assert(sizeof(CONFIG_MATRIX_ADMIN_PASSWORD) >= 2 && sizeof(CONFIG_MATRIX_ADMIN_PASSWORD) <= 33, "Configure local admin password in menuconfig");

static const char *TAG = "matrix_bridge";
static nvs_handle_t store;
static esp_netif_t *sta;
static atomic_bool connected;
static esp_ip4_addr_t aliases[2];
static char authorization[96], setup_password[33];
typedef struct {
    char ssid[33], password[64];
    esp_ip4_addr_t primary, gateway, aliases[2];
} settings_t;
static settings_t settings;
static bool use_dhcp = true;
typedef struct { uint32_t magic; settings_t settings; uint32_t dhcp; } saved_config_t;
extern const char page_start[] asm("_binary_index_html_start");
extern const char page_end[] asm("_binary_index_html_end");

static bool authenticated(httpd_req_t *req)
{
    char supplied[96];
    if (httpd_req_get_hdr_value_str(req, "Authorization", supplied, sizeof(supplied)) == ESP_OK &&
        strlen(supplied) == strlen(authorization)) {
        unsigned difference = 0;
        for (size_t i = 0; i < strlen(authorization); i++) difference |= supplied[i] ^ authorization[i];
        if (!difference) return true;
    }
    httpd_resp_set_status(req, "401 Unauthorized");
    httpd_resp_set_hdr(req, "WWW-Authenticate", "Basic realm=\"Matrix Bridge\"");
    httpd_resp_sendstr(req, "Use admin and the locally configured password.");
    return false;
}
static bool permitted_write(httpd_req_t *req)
{
    if (!authenticated(req)) return false;
    char type[40];
    if (httpd_req_get_hdr_value_str(req, "Content-Type", type, sizeof(type)) != ESP_OK || strcmp(type, "application/octet-stream")) {
        httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Invalid content type"); return false;
    }
    return true;
}
static esp_err_t root(httpd_req_t *req)
{
    if (!authenticated(req)) return ESP_OK;
    httpd_resp_set_type(req, "text/html; charset=utf-8");
    httpd_resp_set_hdr(req, "Cache-Control", "no-store");
    httpd_resp_set_hdr(req, "X-Content-Type-Options", "nosniff");
    httpd_resp_set_hdr(req, "Content-Security-Policy", "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
    return httpd_resp_send(req, page_start, page_end - page_start - 1);
}
static esp_err_t status(httpd_req_t *req)
{
    if (!authenticated(req)) return ESP_OK;
    esp_netif_ip_info_t info = {0};
    esp_netif_get_ip_info(sta, &info);
    char body[4608], results[3500], ssid[224];
    json_quote(settings.ssid, ssid, sizeof(ssid));
    wifi_ap_record_t ap_info = {0};
    int rssi = esp_wifi_sta_get_ap_info(&ap_info) == ESP_OK ? ap_info.rssi : -127;
    discovery_status(results, sizeof(results));
    esp_ip4_addr_t target; bool relay_ready; unsigned clients;
    relay_status(&target, &relay_ready, &clients);
    snprintf(body, sizeof(body), "{\"ssid\":%s,\"dhcp\":%s,\"rssi\":%d,\"gateway\":\"" IPSTR "\",\"connected\":%s,\"ip\":\"" IPSTR "\",\"alias10\":\"" IPSTR "\",\"alias50\":\"" IPSTR "\",\"ready10\":%s,\"ready50\":%s,\"conflict\":%s,\"internet\":%d,\"relay_target\":\"" IPSTR "\",\"relay_ready\":%s,\"relay_clients\":%u,\"scan\":%s}",
        ssid, use_dhcp ? "true" : "false", rssi, IP2STR(&info.gw), atomic_load(&connected) ? "true" : "false", IP2STR(&info.ip), IP2STR(&aliases[0]), IP2STR(&aliases[1]),
        matrix_alias_ready(10) ? "true" : "false", matrix_alias_ready(50) ? "true" : "false", matrix_alias_conflict() ? "true" : "false", indicators_internet(), IP2STR(&target), relay_ready ? "true" : "false", clients, results);
    httpd_resp_set_type(req, "application/json"); httpd_resp_set_hdr(req, "Cache-Control", "no-store");
    return httpd_resp_sendstr(req, body);
}
static esp_err_t scan(httpd_req_t *req)
{
    if (!permitted_write(req)) return ESP_OK;
    if (req->content_len != 0) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Scan takes no body");
    if (!atomic_load(&connected) || !matrix_alias_ready(10) || !matrix_alias_ready(50)) {
        httpd_resp_set_status(req, "503 Service Unavailable"); return httpd_resp_sendstr(req, "Wi-Fi or Matrix subnet interfaces are unavailable.");
    }
    esp_netif_ip_info_t info = {0}; esp_netif_get_ip_info(sta, &info);
    esp_err_t err = discovery_start(info.ip, aliases);
    if (err != ESP_OK) {
        httpd_resp_set_status(req, err == ESP_ERR_INVALID_STATE ? "409 Conflict" : "503 Service Unavailable");
        return httpd_resp_sendstr(req, err == ESP_ERR_INVALID_STATE ? "A scan is already running." : "Scan could not start.");
    }
    return httpd_resp_sendstr(req, "Scan started.");
}
static esp_err_t wifi_scan_api(httpd_req_t *req)
{
    if (req->method == HTTP_POST) {
        if (!permitted_write(req)) return ESP_OK;
        if (req->content_len) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Wi-Fi scan takes no body");
        if (wifi_scan_begin(atomic_load(&connected)) != ESP_OK) {
            httpd_resp_set_status(req, "409 Conflict"); return httpd_resp_sendstr(req, "A Wi-Fi scan is already running.");
        }
        return httpd_resp_sendstr(req, "Wi-Fi scan started.");
    }
    if (!authenticated(req)) return ESP_OK;
    char body[8192]; wifi_scan_status(body, sizeof(body));
    httpd_resp_set_type(req, "application/json"); httpd_resp_set_hdr(req, "Cache-Control", "no-store");
    return httpd_resp_sendstr(req, body);
}
static esp_err_t resources(httpd_req_t *req)
{
    if (!authenticated(req)) return ESP_OK;
    multi_heap_info_t internal, external;
    heap_caps_get_info(&internal, MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT);
    heap_caps_get_info(&external, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    esp_chip_info_t chip; esp_chip_info(&chip); uint32_t flash = 0; esp_flash_get_size(NULL, &flash);
    char body[1024];
    snprintf(body, sizeof(body), "{\"uptime_s\":%lld,\"cores\":%d,\"cpu_mhz\":%d,\"tasks\":%u,\"flash_bytes\":%lu,\"psram_enabled\":%s,\"psram_bytes\":%u,\"internal_total\":%u,\"internal_free\":%u,\"internal_min_free\":%u,\"internal_largest\":%u,\"psram_total\":%u,\"psram_free\":%u,\"psram_largest\":%u}",
        (long long)(esp_timer_get_time()/1000000), chip.cores, CONFIG_ESP_DEFAULT_CPU_FREQ_MHZ, (unsigned)uxTaskGetNumberOfTasks(), (unsigned long)flash,
        esp_psram_is_initialized()?"true":"false", (unsigned)esp_psram_get_size(), (unsigned)(internal.total_free_bytes + internal.total_allocated_bytes),
        (unsigned)internal.total_free_bytes, (unsigned)internal.minimum_free_bytes, (unsigned)internal.largest_free_block,
        (unsigned)(external.total_free_bytes + external.total_allocated_bytes), (unsigned)external.total_free_bytes, (unsigned)external.largest_free_block);
    httpd_resp_set_type(req, "application/json"); httpd_resp_set_hdr(req, "Cache-Control", "no-store"); return httpd_resp_sendstr(req, body);
}
static void restart(void *unused);
static esp_err_t remote_api(httpd_req_t *req)
{
    if (req->method == HTTP_GET) {
        if (!authenticated(req)) return ESP_OK;
        char body[1024]; remote_bridge_status(body,sizeof(body));
        httpd_resp_set_type(req,"application/json"); httpd_resp_set_hdr(req,"Cache-Control","no-store"); return httpd_resp_sendstr(req,body);
    }
    if (!permitted_write(req)) return ESP_OK;
    if (req->content_len < 1 || req->content_len > 768) return httpd_resp_send_err(req,HTTPD_400_BAD_REQUEST,"Invalid pairing request");
    char body[769]={0}; size_t got=0;
    while(got<req->content_len){int n=httpd_req_recv(req,body+got,req->content_len-got);if(n<=0)return httpd_resp_send_err(req,HTTPD_400_BAD_REQUEST,"Incomplete pairing request");got+=n;}
    cJSON *j=cJSON_Parse(body),*url=j?cJSON_GetObjectItem(j,"app_url"):NULL,*code=j?cJSON_GetObjectItem(j,"code"):NULL;
    esp_err_t err=cJSON_IsString(url)&&cJSON_IsString(code)?remote_bridge_pair(url->valuestring,code->valuestring):ESP_ERR_INVALID_ARG;
    cJSON_Delete(j);memset(body,0,sizeof(body));
    if(err!=ESP_OK)return httpd_resp_send_err(req,HTTPD_400_BAD_REQUEST,"Connect Wi-Fi first. Use a valid HTTPS server and 24-character pairing code. An already paired bridge must be revoked and reset before pairing again.");
    httpd_resp_set_status(req,"202 Accepted");return httpd_resp_sendstr(req,"Pairing requested. Check bridge status below.");
}
static esp_err_t reset_api(httpd_req_t *req)
{
    if(!permitted_write(req))return ESP_OK;
    char body[16]={0};if(req->content_len<1||req->content_len>=sizeof(body))return httpd_resp_send_err(req,HTTPD_400_BAD_REQUEST,"Select network or factory reset");
    size_t got=0;while(got<req->content_len){int n=httpd_req_recv(req,body+got,req->content_len-got);if(n<=0)return ESP_FAIL;got+=n;}
    bool factory=!strcmp(body,"factory");if(!factory&&strcmp(body,"network"))return httpd_resp_send_err(req,HTTPD_400_BAD_REQUEST,"Invalid reset");
    esp_err_t err=ESP_OK;
    if(factory){err=remote_bridge_reset();if(err==ESP_OK)err=nvs_erase_all(store);}
    else {nvs_erase_key(store,"config");nvs_erase_key(store,"configv2");}
    if(err==ESP_OK)err=nvs_commit(store);
    if(err!=ESP_OK)return httpd_resp_send_err(req,HTTPD_500_INTERNAL_SERVER_ERROR,"Reset failed");
    httpd_resp_sendstr(req,"Reset saved. Restarting. Factory reset requires a new pairing identity; revoke the old bridge in biomatrix.");
    if(xTaskCreate(restart,"restart",2048,NULL,3,NULL)!=pdPASS)esp_restart();
    return ESP_OK;
}
static esp_err_t favicon(httpd_req_t *req)
{
    httpd_resp_set_status(req, "204 No Content"); return httpd_resp_send(req, NULL, 0);
}
static bool private_ip(const char *text, esp_ip4_addr_t *ip, unsigned subnet)
{
    if (!esp_netif_str_to_ip4(text, ip)) {
        unsigned char *bytes = (unsigned char *)&ip->addr;
        return bytes[0] == 192 && bytes[1] == 168 && (!subnet || bytes[2] == subnet) && bytes[3] > 0 && bytes[3] < 255;
    }
    return false;
}
static esp_err_t select_relay(httpd_req_t *req)
{
    if (!permitted_write(req)) return ESP_OK;
    char body[16] = {0};
    if (req->content_len < 1 || req->content_len >= sizeof(body)) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Invalid device IP");
    size_t received = 0;
    while (received < req->content_len) {
        int n = httpd_req_recv(req, body + received, req->content_len - received);
        if (n <= 0) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Incomplete IP");
        received += n;
    }
    esp_ip4_addr_t target;
    if (strlen(body) != received || !private_ip(body, &target, 0) ||
        (((unsigned char *)&target.addr)[2] != 10 && ((unsigned char *)&target.addr)[2] != 50) ||
        ((unsigned char *)&target.addr)[3] > 50 || target.addr == aliases[0].addr || target.addr == aliases[1].addr || target.addr == settings.primary.addr)
        return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Select a device in 192.168.10.1–50 or 192.168.50.1–50");
    if (!atomic_load(&connected) || !matrix_alias_ready(((unsigned char *)&target.addr)[2])) {
        httpd_resp_set_status(req, "503 Service Unavailable"); return httpd_resp_sendstr(req, "Matrix network is unavailable.");
    }
    if (!relay_available()) {
        httpd_resp_set_status(req, "503 Service Unavailable"); return httpd_resp_sendstr(req, "Relay listener is unavailable.");
    }
    if (relay_check(target) != ESP_OK) {
        httpd_resp_set_status(req, "503 Service Unavailable");
        return httpd_resp_sendstr(req, "Matrix port 80 is unreachable at this IP. Connect its LAN or Wi-Fi to the shared router; use 192.168.10.1 for its Wi-Fi interface or 192.168.50.1 for LAN.");
    }
    relay_select(target);
    return httpd_resp_sendstr(req, "Relay enabled on port 8080. Use Matrix credentials on its login page.");
}
static void restart(void *unused)
{
    (void)unused; vTaskDelay(pdMS_TO_TICKS(1200)); esp_restart();
}
static esp_err_t configure(httpd_req_t *req)
{
    if (!permitted_write(req)) return ESP_OK;
    char body[256] = {0};
    if (req->content_len < 1 || req->content_len >= sizeof(body))
        return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Configuration too large");
    size_t received = 0;
    while (received < req->content_len) {
        int n = httpd_req_recv(req, body + received, req->content_len - received);
        if (n <= 0) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Incomplete configuration");
        received += n;
    }
    if (strlen(body) != received || strchr(body, '\r')) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Invalid configuration");
    char *values[7] = {body}; unsigned fields = 1;
    for (char *q = body; *q; q++) if (*q == '\n') {
        if (fields == 7) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Extra setting");
        *q = 0; values[fields++] = q + 1;
    }
    if (fields != 6 && fields != 7) return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Missing setting");
    unsigned shift = fields == 7 ? 1 : 0;
    bool dhcp = fields == 7 && !strcmp(values[2], "dhcp");
    if (fields == 7 && strcmp(values[2], "dhcp") && strcmp(values[2], "static"))
        return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Invalid address mode");
    settings_t next = {0};
    size_t ssid_length = strlen(values[0]), pass_length = strlen(values[1]);
    if (!ssid_length || ssid_length > 32 || pass_length > 63 || (pass_length && pass_length < 8) ||
        !private_ip(values[4 + shift], &next.aliases[0], 10) || !private_ip(values[5 + shift], &next.aliases[1], 50))
        return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Check the Wi-Fi name, password and Matrix interface addresses.");
    if (!dhcp && (!private_ip(values[2 + shift], &next.primary, 0) ||
        ((unsigned char *)&next.primary.addr)[2] == 4 || !private_ip(values[3 + shift], &next.gateway, 0) ||
        next.primary.addr == next.gateway.addr ||
        (next.primary.addr & PP_HTONL(0xffffff00)) != (next.gateway.addr & PP_HTONL(0xffffff00))))
        return httpd_resp_send_err(req, HTTPD_400_BAD_REQUEST, "Static ESP32 IP and router gateway must share a /24. Choose automatic DHCP to connect without static settings.");
    memcpy(next.ssid, values[0], ssid_length);
    if (pass_length) memcpy(next.password, values[1], pass_length);
    else if (!strcmp(next.ssid, settings.ssid)) memcpy(next.password, settings.password, sizeof(next.password));
    saved_config_t saved = {.magic = 0x4d425232, .settings = next, .dhcp = dhcp};
    esp_err_t err = nvs_set_blob(store, "configv2", &saved, sizeof(saved));
    if (err == ESP_OK) err = nvs_commit(store);
    memset(saved.settings.password, 0, sizeof(saved.settings.password));
    memset(next.password, 0, sizeof(next.password)); memset(body, 0, sizeof(body));
    if (err != ESP_OK) return httpd_resp_send_err(req, HTTPD_500_INTERNAL_SERVER_ERROR, "Could not save configuration");
    discovery_cancel();
    esp_err_t response = httpd_resp_sendstr(req, "Saved. Reconnecting to Wi-Fi. Keep using the setup page at 192.168.4.1 or check serial for the router address. Restarting.");
    if (xTaskCreate(restart, "restart", 2048, NULL, 3, NULL) != pdPASS) ESP_LOGE(TAG, "Saved; reset the board to apply configuration");
    return response;
}
static void network_event(void *unused, esp_event_base_t base, int32_t id, void *data)
{
    (void)unused;
    if (base == WIFI_EVENT && id == WIFI_EVENT_SCAN_DONE) {
        wifi_scan_done();
        if (!atomic_load(&connected) && settings.ssid[0]) esp_wifi_connect();
    }
    if (base == WIFI_EVENT && id == WIFI_EVENT_STA_START && settings.ssid[0]) esp_wifi_connect();
    if (base == WIFI_EVENT && id == WIFI_EVENT_STA_DISCONNECTED) {
        atomic_store(&connected, false); discovery_cancel();
        indicators_wifi(false, settings.ssid[0] != 0);
        relay_network(false, settings.primary, aliases);
        remote_bridge_network(false, settings.primary, aliases);
        matrix_alias_configure(false, settings.primary, aliases);
        if (settings.ssid[0] && !wifi_scan_busy()) esp_wifi_connect();
    }
    if (base == IP_EVENT && id == IP_EVENT_STA_GOT_IP) {
        ip_event_got_ip_t *event = data;
        esp_err_t err = matrix_alias_configure(true, event->ip_info.ip, aliases);
        atomic_store(&connected, true);
        relay_network(true, event->ip_info.ip, aliases);
        remote_bridge_network(true, event->ip_info.ip, aliases);
        indicators_wifi(true, settings.ssid[0] != 0);
        ESP_LOGI(TAG, "Router address: http://" IPSTR "; Matrix interfaces: %s", IP2STR(&event->ip_info.ip), esp_err_to_name(err));
    }
}
void app_main(void)
{
    ESP_ERROR_CHECK(nvs_flash_init()); // Preserve NVS; never erase on initialization errors.
    ESP_ERROR_CHECK(nvs_open("matrixbridge", NVS_READWRITE, &store));
    strcpy(setup_password, CONFIG_MATRIX_SETUP_PASSWORD);
    size_t config_size = sizeof(settings);
    saved_config_t saved = {0}; size_t saved_size = sizeof(saved);
    if (nvs_get_blob(store, "configv2", &saved, &saved_size) == ESP_OK && saved_size == sizeof(saved) && saved.magic == 0x4d425232) {
        settings = saved.settings; use_dhcp = saved.dhcp != 0;
    } else if (nvs_get_blob(store, "config", &settings, &config_size) == ESP_OK && config_size == sizeof(settings)) {
        // Preserve legacy Wi-Fi credentials; use DHCP to remove the old subnet trap.
        use_dhcp = true;
    } else {
        memset(&settings, 0, sizeof(settings));
        esp_netif_str_to_ip4("192.168.29.20", &settings.primary);
        esp_netif_str_to_ip4("192.168.29.1", &settings.gateway);
        esp_netif_str_to_ip4("192.168.10.20", &settings.aliases[0]);
        esp_netif_str_to_ip4("192.168.50.20", &settings.aliases[1]);
    }
    memcpy(aliases, settings.aliases, sizeof(aliases));
    ESP_ERROR_CHECK(esp_netif_init()); ESP_ERROR_CHECK(esp_event_loop_create_default());
    sta = esp_netif_create_default_wifi_sta(); esp_netif_create_default_wifi_ap();
    if (!use_dhcp) {
        ESP_ERROR_CHECK(esp_netif_dhcpc_stop(sta));
        esp_netif_ip_info_t ip = {.ip = settings.primary, .gw = settings.gateway}; IP4_ADDR(&ip.netmask, 255, 255, 255, 0);
        ESP_ERROR_CHECK(esp_netif_set_ip_info(sta, &ip));
        esp_netif_dns_info_t dns = {.ip.type = ESP_IPADDR_TYPE_V4, .ip.u_addr.ip4 = settings.gateway};
        ESP_ERROR_CHECK(esp_netif_set_dns_info(sta, ESP_NETIF_DNS_MAIN, &dns));
    }
    ESP_ERROR_CHECK(wifi_scan_init());
    ESP_ERROR_CHECK(remote_bridge_init());
    ESP_ERROR_CHECK(discovery_init());
    ESP_ERROR_CHECK(relay_init());
    esp_ip4_addr_t default_target; esp_netif_str_to_ip4("192.168.50.1", &default_target); relay_select(default_target);
    ESP_ERROR_CHECK(indicators_init());
    indicators_wifi(false, settings.ssid[0] != 0);
    wifi_init_config_t init = WIFI_INIT_CONFIG_DEFAULT(); ESP_ERROR_CHECK(esp_wifi_init(&init));
    ESP_ERROR_CHECK(esp_wifi_set_storage(WIFI_STORAGE_RAM));
    ESP_ERROR_CHECK(esp_wifi_set_ps(WIFI_PS_NONE));
    ESP_ERROR_CHECK(esp_event_handler_register(WIFI_EVENT, ESP_EVENT_ANY_ID, network_event, NULL));
    ESP_ERROR_CHECK(esp_event_handler_register(IP_EVENT, IP_EVENT_STA_GOT_IP, network_event, NULL));
    uint8_t mac[6]; ESP_ERROR_CHECK(esp_wifi_get_mac(WIFI_IF_STA, mac));
    wifi_config_t ap = {.ap = {.channel = 1, .authmode = WIFI_AUTH_WPA2_PSK, .max_connection = 2}};
    snprintf((char *)ap.ap.ssid, sizeof(ap.ap.ssid), "MatrixBridge-%02x%02x%02x", mac[3], mac[4], mac[5]);
    ap.ap.ssid_len = strlen((char *)ap.ap.ssid); memcpy(ap.ap.password, setup_password, strlen(setup_password));
    wifi_config_t wifi = {0}; memcpy(wifi.sta.ssid, settings.ssid, strlen(settings.ssid)); memcpy(wifi.sta.password, settings.password, strlen(settings.password));
    ESP_ERROR_CHECK(esp_wifi_set_mode(WIFI_MODE_APSTA));
    ESP_ERROR_CHECK(esp_wifi_set_config(WIFI_IF_AP, &ap));
    ESP_ERROR_CHECK(esp_wifi_set_config(WIFI_IF_STA, &wifi));
    ESP_ERROR_CHECK(esp_wifi_start());
    char login[40]; snprintf(login,sizeof(login),"admin:%s",CONFIG_MATRIX_ADMIN_PASSWORD);
    size_t encoded=0; memcpy(authorization,"Basic ",6);
    ESP_ERROR_CHECK(mbedtls_base64_encode((unsigned char*)authorization+6,sizeof(authorization)-6,&encoded,(unsigned char*)login,strlen(login))==0?ESP_OK:ESP_FAIL);
    authorization[encoded+6]=0; memset(login,0,sizeof(login));
    httpd_config_t http = HTTPD_DEFAULT_CONFIG(); http.stack_size = 16384; http.max_uri_handlers = 16; http.recv_wait_timeout = 3; http.send_wait_timeout = 3;
    httpd_handle_t server; ESP_ERROR_CHECK(httpd_start(&server, &http));
    const httpd_uri_t routes[] = {
        {.uri="/api/remote",.method=HTTP_GET,.handler=remote_api},
        {.uri="/api/remote/pair",.method=HTTP_POST,.handler=remote_api},
        {.uri="/api/reset",.method=HTTP_POST,.handler=reset_api},
        {.uri = "/favicon.ico", .method = HTTP_GET, .handler = favicon},
        {.uri = "/api/wifi/scan", .method = HTTP_GET, .handler = wifi_scan_api},
        {.uri = "/api/wifi/scan", .method = HTTP_POST, .handler = wifi_scan_api},
        {.uri = "/api/resources", .method = HTTP_GET, .handler = resources},
        {.uri = "/", .method = HTTP_GET, .handler = root}, {.uri = "/api/status", .method = HTTP_GET, .handler = status},
        {.uri = "/api/relay", .method = HTTP_POST, .handler = select_relay},
        {.uri = "/api/config", .method = HTTP_POST, .handler = configure}, {.uri = "/api/scan", .method = HTTP_POST, .handler = scan},
    };
    for (unsigned i = 0; i < sizeof(routes) / sizeof(routes[0]); i++) ESP_ERROR_CHECK(httpd_register_uri_handler(server, &routes[i]));
    ESP_LOGI(TAG, "Setup Wi-Fi: %s; http://192.168.4.1; login admin; admin password: %s; Wi-Fi password: %s", ap.ap.ssid, CONFIG_MATRIX_ADMIN_PASSWORD, setup_password);
}
