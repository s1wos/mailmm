<?php

namespace App\Services;

use App\Requests\Rules\MailboxCursor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

final class MailboxSocketContinuation
{
    public static function issue(string $grant, string $snapshot, string $cursor): string
    {
        return Crypt::encryptString(json_encode([
            'grant' => $grant, 'snapshot' => $snapshot, 'cursor' => $cursor, 'expires' => now()->addMinute()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{snapshot: string, cursor: Cursor}|null */
    public static function consume(string $token, string $grant): ?array
    {
        try {
            $page = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if (($page['grant'] ?? null) !== $grant || ! is_int($page['expires'] ?? null)
                || $page['expires'] <= now()->timestamp || ! is_string($page['snapshot'] ?? null)
                || ! is_string($page['cursor'] ?? null)) {
                return null;
            }
            $cursor = Cursor::fromEncoded($page['cursor']);
            Validator::validate($cursor?->toArray() ?? [], MailboxCursor::rules());
        } catch (DecryptException|JsonException|ValidationException) {
            return null;
        }

        $key = MailboxSocketGrant::CACHE_PREFIX.'page:'.hash('sha256', $token);
        if (! Cache::store(config('cache.limiter'))->add($key, true, 61)) {
            return null;
        }

        return ['snapshot' => $page['snapshot'], 'cursor' => $cursor];
    }
}
