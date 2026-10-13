#pragma once
#include "esp_err.h"
#include "esp_netif.h"
#include <stdbool.h>
esp_err_t remote_bridge_init(void);
void remote_bridge_network(bool connected, esp_ip4_addr_t primary, const esp_ip4_addr_t aliases[2]);
esp_err_t remote_bridge_pair(const char *app_url, const char *code);
void remote_bridge_status(char *out, size_t size);
esp_err_t remote_bridge_reset(void);
