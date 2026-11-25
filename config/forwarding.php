<?php

use App\Rules\MailboxDomainName;

return [
    'manual_enabled' => env('FORWARD_MANUAL_ENABLED', false),
    'auto_enabled' => env('FORWARD_AUTO_ENABLED', false),
    'manual_enabled_at' => env('FORWARD_MANUAL_ENABLED_AT'),
    'auto_enabled_at' => env('FORWARD_AUTO_ENABLED_AT'),
    'service_address' => env('FORWARD_SERVICE_ADDRESS'),
    'bounce_domain' => MailboxDomainName::parse((string) env('FORWARD_BOUNCE_DOMAIN', '')) ?? '',
    'manual_dsn' => env('FORWARD_MANUAL_DSN'),
    'auto_dsn' => env('FORWARD_AUTO_DSN'),
    'webhook_secret' => env('FORWARD_WEBHOOK_SECRET'),
    'authserv_id' => strtolower((string) env('MX_HOST', 'mx.mailmm.test')),
    'scanner_url' => env('FORWARD_SCANNER_URL', 'http://forward-filter:11333/checkv2'),
    'timeout' => (int) env('FORWARD_SMTP_TIMEOUT', 15),
    'max_bytes' => (int) env('FORWARD_MAX_BYTES', 33554432),
    'limits' => [
        'codes_per_mailbox' => (int) env('FORWARD_CODES_PER_MAILBOX', 5),
        'codes_per_destination' => (int) env('FORWARD_CODES_PER_DESTINATION', 5),
        'manual_per_user' => (int) env('FORWARD_MANUAL_PER_USER', 20),
        'auto_per_mailbox' => (int) env('FORWARD_AUTO_PER_MAILBOX', 100),
        'per_destination' => (int) env('FORWARD_PER_DESTINATION', 100),
        'total' => (int) env('FORWARD_TOTAL', 1000),
        'bytes' => (int) env('FORWARD_HOURLY_BYTES', 104857600),
    ],
];
