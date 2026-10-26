<?php

return [
    // Keep validity writes unavailable until physical acceptance and reset semantics are resolved.
    'user_validity_enabled' => false,
    // History imports are preserved without sending old punch notifications.
    'live_punch_window_minutes' => 15,
    // Only gateway management origins from this operator-owned allowlist are callable.
    'allowed_origins' => array_filter(explode(',', env('DEVICE_GATEWAY_ALLOWED_ORIGINS', 'http://127.0.0.1:8081'))),
    'max_pending_receipts' => (int) env('DEVICE_GATEWAY_MAX_PENDING_RECEIPTS', 10000),
];
