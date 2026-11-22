<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class MailboxSocketRead
{
    public function __construct(private MailboxSocketGrant $grants, private MailboxInbox $inbox) {}

    public function handle(array $job): array
    {
        $access = $this->grants->resolve($job['grant'], $job['socket'], $job['channel']);
        if ($job['command'] === 'mailbox.authorize') {
            return $access ? ['authorized' => true] : ['code' => 'access_revoked'];
        }
        $data = $job['data'];
        $response = ['requestId' => $data['requestId']];
        if (! $access) {
            return $response + ['code' => 'access_revoked'];
        }
        [$mailbox, $actor, $identity, $expiry] = $access;
        $cache = Cache::store(config('cache.limiter'));
        $response += ['mailboxId' => $mailbox->id, 'serverTime' => now()->toISOString(), 'grantExpiresAt' => $expiry];
        $cursor = null;
        if ($job['command'] === 'mailbox.page') {
            try {
                $continuation = json_decode(Crypt::decryptString($data['continuation']), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                return $response + ['code' => 'invalid_request'];
            }
            if (($continuation['grant'] ?? null) !== $job['grant'] || $continuation['expires'] <= now()->timestamp) {
                return $response + ['code' => 'invalid_request'];
            }
            if (! $cache->add(MailboxSocketGrant::CACHE_PREFIX.'page:'.hash('sha256', $data['continuation']), true, 61)) {
                return $response + ['code' => 'invalid_request'];
            }
            $snapshot = $continuation['snapshot'];
            $cursor = $continuation['cursor'];
        } else {
            if (! $actor?->is_admin && ! ($job['automatic'] ?? false)) {
                $key = MailboxSocketGrant::CACHE_PREFIX.'manual:'.$identity;
                $until = now()->getTimestampMs() + 5000;
                $lock = $cache->lock($key.':lock', 3);
                if (! $lock->get()) {
                    return $response + ['code' => 'rate_limited', 'retryAfter' => 5];
                }
                try {
                    $remaining = (int) $cache->get($key) - now()->getTimestampMs();
                    if ($remaining > 0) {
                        return $response + ['code' => 'rate_limited', 'retryAfter' => (int) ceil($remaining / 1000)];
                    }
                    $cache->put($key, $until, 6);
                } finally {
                    $lock->release();
                }
            }
            $snapshot = $this->inbox->snapshot($mailbox);
            if (($data['snapshot'] ?? null) === $snapshot) {
                return $response + ['snapshot' => $snapshot, 'unchanged' => true];
            }
        }
        $page = $this->inbox->page($mailbox, $cursor, socketPreview: true);
        if (! $this->grants->resolve($job['grant'], $job['socket'], $job['channel'])) {
            return ['requestId' => $data['requestId'], 'code' => 'access_revoked'];
        }
        $next = $page['meta']['next_cursor'];
        $token = $next ? Crypt::encryptString(json_encode([
            'grant' => $job['grant'], 'snapshot' => $snapshot, 'cursor' => $next, 'expires' => now()->addMinute()->timestamp,
        ], JSON_THROW_ON_ERROR)) : null;

        return $response + ['snapshot' => $snapshot, 'unchanged' => false, 'page' => $page, 'continuation' => $token];
    }
}
