<?php

return [
    // HTTP client timeout in seconds for delivery attempts
    'http_timeout' => env('NOTIFICATIONS_HTTP_TIMEOUT', 15),

    // Max delivery attempts per notification
    'max_attempts' => env('NOTIFICATIONS_MAX_ATTEMPTS', 8),

    // Base backoff seconds (exponential backoff uses base * 2^(n-1))
    'base_backoff_seconds' => env('NOTIFICATIONS_BASE_BACKOFF', 10),

    // Maximum backoff seconds
    'max_backoff_seconds' => env('NOTIFICATIONS_MAX_BACKOFF', 3600),

    // Max jitter seconds to add
    'backoff_jitter_seconds' => env('NOTIFICATIONS_BACKOFF_JITTER', 5),
];
