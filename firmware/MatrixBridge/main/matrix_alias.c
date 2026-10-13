// Internal lwIP alias integration, pinned to ESP-IDF 6.1; physical validation required.
#include "matrix_alias.h"
#include <stdatomic.h>
#include <string.h>
#include "freertos/FreeRTOS.h"
#include "freertos/semphr.h"
#include "esp_netif_net_stack.h"
#include "lwip/etharp.h"
#include "lwip/netif.h"
#include "lwip/tcpip.h"

typedef struct {
    struct netif iface;
    uint8_t address[4];
    bool installed;
    atomic_bool ready;
} alias_t;
static alias_t aliases[2];
static struct netif *station;
static netif_input_fn original_input;
static atomic_bool conflict;
static SemaphoreHandle_t done;
static esp_ip4_addr_t requested[2];
static bool enabled[2];
static atomic_bool direct[2];

static err_t output(struct netif *n, struct pbuf *packet)
{
    (void)n;
    return station->linkoutput(station, packet);
}
static err_t input(struct pbuf *packet, struct netif *incoming)
{
    uint8_t h[42];
    u16_t copied = pbuf_copy_partial(packet, h, sizeof(h), 0);
    if (copied >= 34) {
        unsigned type = ((unsigned)h[12] << 8) | h[13];
        for (unsigned i = 0; i < 2; i++) {
            alias_t *a = &aliases[i];
            if (!atomic_load(&a->ready)) continue;
            if (type == 0x0806 && copied >= 42 && h[18] == 6 && h[19] == 4) {
                if (!memcmp(h + 28, a->address, 4) && memcmp(h + 22, station->hwaddr, 6)) {
                    atomic_store(&conflict, true);
                    atomic_store(&a->ready, false);
                }
                if (atomic_load(&a->ready) && !memcmp(h + 38, a->address, 4))
                    return original_input(packet, &a->iface);
            }
            if (type == 0x0800 && !memcmp(h + 30, a->address, 4))
                return original_input(packet, &a->iface);
        }
    }
    return original_input(packet, incoming);
}
static err_t init(struct netif *n)
{
    n->name[0] = 'm'; n->name[1] = 'x'; n->mtu = station->mtu;
    n->hwaddr_len = station->hwaddr_len;
    memcpy(n->hwaddr, station->hwaddr, n->hwaddr_len);
    n->flags = NETIF_FLAG_BROADCAST | NETIF_FLAG_ETHARP | NETIF_FLAG_ETHERNET;
    n->output = etharp_output; n->linkoutput = output;
#if LWIP_CHECKSUM_CTRL_PER_NETIF
    n->chksum_flags = NETIF_CHECKSUM_ENABLE_ALL;
#endif
    return ERR_OK;
}
static void apply(void *unused)
{
    (void)unused;
    if (!station) {
        esp_netif_t *esp = esp_netif_get_handle_from_ifkey("WIFI_STA_DEF");
        station = esp ? esp_netif_get_netif_impl(esp) : NULL;
    }
    if (station && station->input && station->linkoutput) {
        for (unsigned i = 0; i < 2; i++) {
            alias_t *a = &aliases[i];
            atomic_store(&a->ready, false);
            if (a->installed) { netif_set_link_down(&a->iface); netif_set_down(&a->iface); }
            if (!enabled[i] || atomic_load(&conflict)) continue;
            ip4_addr_t ip = {.addr = requested[i].addr}, mask, gateway;
            IP4_ADDR(&mask, 255, 255, 255, 0); ip4_addr_set_zero(&gateway);
            memcpy(a->address, &ip.addr, 4);
            if (!a->installed) {
                if (!netif_add(&a->iface, &ip, &mask, &gateway, NULL, init, tcpip_input)) continue;
                a->installed = true;
            } else netif_set_addr(&a->iface, &ip, &mask, &gateway);
            if (!original_input) { original_input = station->input; station->input = input; }
            atomic_store(&a->ready, true);
            netif_set_up(&a->iface); netif_set_link_up(&a->iface);
        }
    }
    xSemaphoreGive(done);
}
esp_err_t matrix_alias_configure(bool connected, esp_ip4_addr_t primary, const esp_ip4_addr_t addresses[2])
{
    // Called only by the serialized ESP event loop.
    if (!done) done = xSemaphoreCreateBinary();
    if (!done) return ESP_ERR_NO_MEM;
    for (unsigned i = 0; i < 2; i++) {
        requested[i] = addresses[i];
        atomic_store(&direct[i], connected && ((primary.addr & PP_HTONL(0xffffff00)) == (addresses[i].addr & PP_HTONL(0xffffff00))));
        enabled[i] = connected && !atomic_load(&direct[i]);
    }
    if (tcpip_callback(apply, NULL) != ERR_OK) return ESP_FAIL;
    xSemaphoreTake(done, portMAX_DELAY);
    return ((!enabled[0] || atomic_load(&aliases[0].ready)) && (!enabled[1] || atomic_load(&aliases[1].ready))) ? ESP_OK : ESP_FAIL;
}
bool matrix_alias_ready(unsigned subnet)
{
    unsigned i = subnet == 10 ? 0 : 1;
    return !atomic_load(&conflict) && (atomic_load(&direct[i]) || atomic_load(&aliases[i].ready));
}
bool matrix_alias_conflict(void) { return atomic_load(&conflict); }
