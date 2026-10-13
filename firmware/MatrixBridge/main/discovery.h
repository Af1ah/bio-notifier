#pragma once
#include <stdbool.h>
#include "esp_err.h"
#include "esp_netif.h"
esp_err_t discovery_start(esp_ip4_addr_t primary, const esp_ip4_addr_t aliases[2]);
void discovery_cancel(void);
void discovery_status(char *out, size_t size);

bool discovery_running(void);

esp_err_t discovery_init(void);
