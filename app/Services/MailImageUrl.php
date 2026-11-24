<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class MailImageUrl
{
    /** Issue opaque URLs only while rendering a specific archived message. */
    public static function forMessage(int $id): Closure
    {
        $issued = [];

        return static function (string $url) use ($id, &$issued): ?string {
            if (isset($issued[$url])) {
                return $issued[$url];
            }
            if (count($issued) >= 128 || strlen($url) > 3072) {
                return null;
            }
            $token = rtrim(strtr(Crypt::encryptString("mail-image/$id\n".$url), '+/', '-_'), '=');

            return $issued[$url] = "/mail-images/$id/$token";
        };
    }

    public static function destination(int $id, string $token): ?string
    {
        if (strlen($token) > 6500) {
            return null;
        }
        try {
            $payload = Crypt::decryptString(strtr($token, '-_', '+/'));
        } catch (DecryptException) {
            return null;
        }
        $prefix = "mail-image/$id\n";

        return str_starts_with($payload, $prefix) ? substr($payload, strlen($prefix)) : null;
    }
}
