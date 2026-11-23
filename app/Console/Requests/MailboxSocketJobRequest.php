<?php

namespace App\Console\Requests;

use App\Http\Requests\MailboxSocketRequest;

final class MailboxSocketJobRequest
{
    public const MAX_BYTES = 8192;

    public static function isValid(mixed $job, int $bytes): bool
    {
        if ($bytes > self::MAX_BYTES || ! is_array($job) || ! is_string($job['grant'] ?? null)
            || ! is_string($job['socket'] ?? null) || ! is_string($job['channel'] ?? null)) {
            return false;
        }
        $command = $job['command'] ?? null;
        if ($command === 'mailbox.authorize') {
            return true;
        }

        $data = $job['data'] ?? null;

        return MailboxSocketRequest::hasValidEnvelope($command, $job['channel'], $data['requestId'] ?? null)
            && MailboxSocketRequest::hasValidPayload($command, $data)
            && (! array_key_exists('automatic', $job) || is_bool($job['automatic']));
    }
}
