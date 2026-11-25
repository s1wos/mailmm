<?php

namespace App\Http\Requests;

final class MailboxSocketRequest
{
    public static function hasValidEnvelope(mixed $command, mixed $channel, mixed $requestId): bool
    {
        return in_array($command, ['mailbox.check', 'mailbox.page'], true) && is_string($channel)
            && is_string($requestId) && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $requestId);
    }

    public static function hasValidPayload(string $command, array $data): bool
    {
        if ($command === 'mailbox.page') {
            return is_string($data['continuation'] ?? null) && strlen($data['continuation']) <= 2500;
        }

        return in_array($data['reason'] ?? null, ['manual', 'auto', 'subscribed', 'delivery', 'resume'], true)
            && (! isset($data['snapshot']) || (is_string($data['snapshot']) && strlen($data['snapshot']) <= 100))
            && (! array_key_exists('delta', $data) || is_bool($data['delta']))
            && (! isset($data['deliveryId']) || (is_int($data['deliveryId']) && $data['deliveryId'] > 0));
    }
}
