#include "rgb_led.h"
#include "driver/rmt_tx.h"
static rmt_channel_handle_t channel;
static rmt_encoder_handle_t encoder;

esp_err_t rgb_led_init(void)
{
    rmt_tx_channel_config_t config = {
        .gpio_num = RGB_LED_GPIO, .clk_src = RMT_CLK_SRC_DEFAULT,
        .resolution_hz = 10000000, .mem_block_symbols = 64, .trans_queue_depth = 1,
    };
    esp_err_t err = rmt_new_tx_channel(&config, &channel);
    if (err != ESP_OK) return err;
    rmt_copy_encoder_config_t copy = {};
    err = rmt_new_copy_encoder(&copy, &encoder);
    if (err != ESP_OK) return err;
    return rmt_enable(channel);
}

esp_err_t rgb_led_set(uint8_t r, uint8_t g, uint8_t b)
{
    // WS2812 uses GRB, most significant bit first. Brightness is applied by light_control.
    uint8_t data[] = {g, r, b};
    rmt_symbol_word_t symbols[25];
    for (int i = 0; i < 24; i++) {
        bool one = data[i / 8] & (1 << (7 - i % 8));
        symbols[i] = (rmt_symbol_word_t){.level0 = 1, .duration0 = one ? 9 : 3,
                                       .level1 = 0, .duration1 = one ? 3 : 9};
    }
    // 300us low latch accommodates WS2812 variants.
    symbols[24] = (rmt_symbol_word_t){.duration0 = 1500, .duration1 = 1500};
    rmt_transmit_config_t tx = {.loop_count = 0};
    esp_err_t err = rmt_transmit(channel, encoder, symbols, sizeof(symbols), &tx);
    if (err != ESP_OK) return err;
    // Keep the stack buffer alive until RMT finishes, including a timeout path.
    err = rmt_tx_wait_all_done(channel, 100);
    if (err != ESP_OK) { rmt_disable(channel); rmt_enable(channel); }
    return err;
}
