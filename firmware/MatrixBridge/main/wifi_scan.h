#pragma once
#include <stdbool.h>
#include "esp_err.h"
esp_err_t wifi_scan_init(void);
esp_err_t wifi_scan_begin(bool connected);
void wifi_scan_done(void);
bool wifi_scan_busy(void);
void wifi_scan_status(char *out, size_t size);
void json_quote(const char *text, char *out, size_t size);
