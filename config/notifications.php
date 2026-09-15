<?php

return [
    'max_body_bytes' => (int) env('NOTIFICATION_MAX_BODY_BYTES', 1_048_576),
    'queue' => env('NOTIFICATION_QUEUE', 'notifications'),
    'ingress_requests_per_minute' => (int) env('NOTIFICATION_INGRESS_RPM', 600),
    'success_retention_days' => (int) env('NOTIFICATION_SUCCESS_RETENTION_DAYS', 30),
    'failure_retention_days' => (int) env('NOTIFICATION_FAILURE_RETENTION_DAYS', 90),
    'max_error_length' => 1000,
];
