<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'clamav_command' => env('CLAMAV_COMMAND'),

    // Password for the demonstration accounts created by the local seeder.
    'seed_admin_password' => env('MARGIN_SEED_ADMIN_PASSWORD'),

    // When set, every outgoing email is delivered to this one address instead
    // of its real recipient. For demonstrations where accounts use made-up
    // addresses. Leave empty in a real deployment.
    'mail_always_to' => env('MAIL_ALWAYS_TO'),

    // Behind a proxy or tunnel the request host is an internal name. When true,
    // every generated link uses APP_URL, the address users actually see.
    'force_root_url' => (bool) env('FORCE_ROOT_URL', false),
    'file_scan_required' => env('FILE_SCAN_REQUIRED', false),
    'file_scan_timeout' => (int) env('FILE_SCAN_TIMEOUT', 30),

    // Decompression-bomb limits for ZIP uploads (.zip, .docx, .xlsx). Checked
    // from the archive's table of contents, without unpacking it.
    'archive_max_entries' => (int) env('ARCHIVE_MAX_ENTRIES', 2000),
    'archive_max_unpacked_mb' => (int) env('ARCHIVE_MAX_UNPACKED_MB', 256),
    'archive_max_ratio' => (int) env('ARCHIVE_MAX_RATIO', 100),

    // Private disk for uploaded files (materials, submissions, avatars). In
    // production this is the encrypted private S3 bucket.
    'files_disk' => env('FILES_DISK', env('FILESYSTEM_DISK', 'local')),
    'mail_notifications' => env('LMS_MAIL_NOTIFICATIONS', false),
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    // Refuse cookie-session API requests that do not come from a browser page of
    // our own app (checks the Sec-Fetch-Site header). Leave on in production.
    'require_browser_context' => (bool) env('REQUIRE_BROWSER_CONTEXT', true),

    // Hard limit on a browser session, in minutes, however active it is. 0 disables.
    'session_absolute_minutes' => (int) env('SESSION_ABSOLUTE_MINUTES', 720),

    // Reject passwords that appear in public breach lists (Have I Been Pwned).
    'check_breached_passwords' => (bool) env('CHECK_BREACHED_PASSWORDS', true),

    // Lifetime of a mobile API token. After it expires the user signs in again.
    'mobile_token_hours' => (int) env('MOBILE_TOKEN_HOURS', 8),

    // Shown in the public privacy notice. Set PRIVACY_CONTACT_EMAIL in production.
    'privacy' => [
        'controller' => env('PRIVACY_CONTROLLER_NAME', 'The institution operating this Margin LMS instance'),
        'contact_email' => env('PRIVACY_CONTACT_EMAIL', 'privacy@example.edu'),
        'updated_at' => '2026-11-23',
    ],
];
