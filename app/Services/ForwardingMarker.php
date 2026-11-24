<?php

namespace App\Services;

final class ForwardingMarker
{
    public const HEADER = 'X-MailMM-Forward';

    public static function value(string $type, string $id, string $body): string
    {
        return self::sign($type, $id, hash('sha256', $body));
    }

    private static function sign(string $type, string $id, string $digest): string
    {
        $payload = 'v1 '.$type.' '.$id;
        $signature = hash_hmac('sha256', "forward-marker\0".$payload."\0".$digest, config('app.key'));

        return $payload.' '.$signature;
    }

    public static function type(string $raw): ?string
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $pattern = '/^'.self::HEADER.':[ \t]*(v1 (manual|auto|verification) ([a-f0-9-]{36}) [a-f0-9]{64})[ \t]*\r?$/mi';
        preg_match_all($pattern, preg_replace('/\r\n[ \t]+/', ' ', $parts[0]), $matches, PREG_SET_ORDER);
        $digest = null;
        $found = null;
        foreach ($matches as [, $value, $type, $id]) {
            $digest ??= hash('sha256', $parts[1]);
            if (hash_equals(self::sign($type, $id, $digest), $value)) {
                if ($type !== 'manual') {
                    return $type;
                }
                $found = $type;
            }
        }

        return $found;
    }
}
