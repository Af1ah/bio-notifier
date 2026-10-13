#pragma once
#include <stdbool.h>
#include "esp_err.h"
#include "esp_netif.h"
esp_err_t matrix_alias_configure(bool connected, esp_ip4_addr_t primary, const esp_ip4_addr_t aliases[2]);
bool matrix_alias_ready(unsigned subnet);
bool matrix_alias_conflict(void);
