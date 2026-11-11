<?php

return [
    // Exact firmware/profile and binding capabilities still gate every access command.
    'access_groups_enabled' => (bool) env('DEVICE_ACCESS_GROUPS_ENABLED', true),
    // Expiration is independent from daily periods; exact firmware and binding checks apply.
    'user_validity_enabled' => (bool) env('DEVICE_USER_EXPIRATION_ENABLED', true),
    'expiration_date_pilot_serial' => env('DEVICE_EXPIRATION_DATE_PILOT_SERIAL'),
    'verified_date_expiration_serials' => array_values(array_filter(array_map('trim', explode(',', env('DEVICE_VERIFIED_DATE_EXPIRATION_SERIALS', ''))))),
    // History imports are preserved without sending old punch notifications.
    'live_punch_window_minutes' => 15,
    // Only gateway management origins from this operator-owned allowlist are callable.
    'allowed_origins' => array_filter(explode(',', env('DEVICE_GATEWAY_ALLOWED_ORIGINS', 'http://127.0.0.1:8081'))),
    'max_pending_receipts' => (int) env('DEVICE_GATEWAY_MAX_PENDING_RECEIPTS', 10000),
];
