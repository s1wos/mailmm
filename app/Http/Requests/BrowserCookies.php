<?php

namespace App\Http\Requests;

final class BrowserCookies
{
    public static function validVisitorId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/', $id);
    }

    public static function validMailboxCookie(array $parts, mixed $key): bool
    {
        return is_string($key) && count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1]);
    }
}
