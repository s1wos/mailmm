<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class GuestMailboxCookie
{
    private static function prefix(): string
    {
        return config('session.cookie').'_mailbox_';
    }

    /** @return array{id: int, guest_session_key: string}|null */
    public static function mailbox(Request $request): ?array
    {
        $latest = null;
        foreach (self::cookies($request) as [$id, $deadline, $key]) {
            if ($deadline > now()->timestamp && $id > ($latest['id'] ?? 0)) {
                $latest = ['id' => $id, 'guest_session_key' => $key];
            }
        }

        return $latest;
    }

    private static function cookies(Request $request): iterable
    {
        $prefix = self::prefix();
        foreach ($request->cookies as $name => $key) {
            if (is_string($key) && str_starts_with($name, $prefix)) {
                $parts = explode('_', substr($name, strlen($prefix)));
                if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                    yield $name => [(int) $parts[0], (int) $parts[1], $key];
                }
            }
        }
    }

    public static function renew(Request $request, Response $response): void
    {
        $email = $request->attributes->get(self::class);
        if ($email === null) {
            return;
        }

        $deadline = MailboxSocketGrant::expiresAt($email)->ceilSecond()->timestamp;
        $name = self::prefix().$email->id.'_'.$deadline;
        foreach (self::cookies($request) as $previous => [$id, $expires]) {
            if ($id < $email->id || ($id === $email->id && $expires < $deadline)) {
                self::setCookie($response, $previous, null, 1);
            }
        }
        if ($request->cookie($name) !== $email->guest_session_key) {
            self::setCookie($response, $name, $email->guest_session_key, config('session.expire_on_close') ? 0 : $deadline);
        }
    }

    private static function setCookie(Response $response, string $name, ?string $value, int $expires): void
    {
        $response->headers->setCookie(new Cookie(
            $name, $value, $expires,
            config('session.path'), config('session.domain'), config('session.secure'), true,
            false, config('session.same_site'), config('session.partitioned'),
        ));
    }
}
