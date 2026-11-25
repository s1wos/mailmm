<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

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
        $response += ['mailboxId' => $mailbox->id, 'serverTime' => now()->toISOString(), 'grantExpiresAt' => $expiry];
        $cursor = null;
        $page = null;
        $deltaSince = null;
        if ($job['command'] === 'mailbox.page') {
            $continuation = MailboxSocketContinuation::consume($data['continuation'], $job['grant']);
            if ($continuation === null) {
                return $response + ['code' => 'invalid_request'];
            }
            $snapshot = $continuation['snapshot'];
            $cursor = $continuation['cursor'];
            $deltaSince = $continuation['deltaSince'];
            if ($deltaSince !== null) {
                $page = $this->inbox->delta($mailbox, $deltaSince, $snapshot, $cursor, socketPreview: true);
                if ($page === null) {
                    return $response + ['code' => 'invalid_request'];
                }
            }
        } else {
            if (! $actor?->is_admin && ! ($job['automatic'] ?? false)) {
                $cache = Cache::store(config('cache.limiter'));
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
            if (($data['delta'] ?? false) && isset($data['snapshot'])) {
                $page = $this->inbox->delta($mailbox, $data['snapshot'], $snapshot, socketPreview: true);
                $deltaSince = $page ? $data['snapshot'] : null;
            }
        }
        $page ??= $this->inbox->page($mailbox, $cursor, socketPreview: true);
        if (! $this->grants->resolve($job['grant'], $job['socket'], $job['channel'])) {
            return ['requestId' => $data['requestId'], 'code' => 'access_revoked'];
        }
        $next = $page['meta']['next_cursor'];
        $token = $next ? MailboxSocketContinuation::issue($job['grant'], $snapshot, $next, $deltaSince) : null;

        return $response + ['snapshot' => $snapshot, 'unchanged' => false, 'page' => $page, 'continuation' => $token];
    }
}
