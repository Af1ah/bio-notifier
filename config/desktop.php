<?php

return [
    'enabled' => (bool) env('BIO_DESKTOP', false),
    'gateway_management_url' => env('BIO_GATEWAY_MANAGEMENT_URL'),
    'gateway_device_url' => env('BIO_GATEWAY_DEVICE_URL'),
    'gateway_management_token' => env('BIO_GATEWAY_MANAGEMENT_TOKEN'),
    'gateway_events_token' => env('BIO_GATEWAY_EVENTS_TOKEN'),
];
