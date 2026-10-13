#pragma once
#include <stdint.h>
#include "esp_err.h"
#define RGB_LED_GPIO 48
esp_err_t rgb_led_init(void);
esp_err_t rgb_led_set(uint8_t r, uint8_t g, uint8_t b);
