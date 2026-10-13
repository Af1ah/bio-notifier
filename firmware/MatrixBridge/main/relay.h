#pragma once
#include "esp_err.h"
#include "esp_netif.h"
#include <stdbool.h>
esp_err_t relay_init(void);
void relay_network(bool connected, esp_ip4_addr_t primary, const esp_ip4_addr_t aliases[2]);
void relay_select(esp_ip4_addr_t target);
void relay_status(esp_ip4_addr_t *target, bool *ready, unsigned *clients);

bool relay_available(void);

esp_err_t relay_check(esp_ip4_addr_t target);
