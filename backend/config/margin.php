<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'clamav_command' => env('CLAMAV_COMMAND'),
    'file_scan_required' => env('FILE_SCAN_REQUIRED', false),
    'file_scan_timeout' => (int) env('FILE_SCAN_TIMEOUT', 30),
    'mail_notifications' => env('LMS_MAIL_NOTIFICATIONS', false),
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
];
