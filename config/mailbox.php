<?php

$history = env('MX_PREVIOUS_HOSTS', '').','.env('MAILMM_SPOOL_HOSTS', '');

return [
    'domain' => strtolower(env('MAILBOX_DOMAIN', 'mailmm.test')),
    'mx_host' => strtolower(env('MX_HOST', 'mx.mailmm.test')),
    'previous_mx_hosts' => preg_split('/[\s,]+/', strtolower($history), flags: PREG_SPLIT_NO_EMPTY),
    'max_message_bytes' => env('MAILBOX_MAX_MESSAGE_BYTES', 10 * 1024 * 1024),
];
