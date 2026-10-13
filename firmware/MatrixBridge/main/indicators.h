#pragma once
#include "esp_err.h"
#include <stdbool.h>
esp_err_t indicators_init(void);
void indicators_wifi(bool connected,bool configured);
// -1 = not checked; 0 = no successful external HTTP check; 1 = HTTP 204 received.
int indicators_internet(void);
