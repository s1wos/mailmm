<?php

namespace App\Rules;

use Symfony\Component\Mime\Address;

final class ForwardingAddress
{
    public static function valid(mixed $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** Accept exactly one SMTP mailbox. Keep the local-part bytes and canonicalize the DNS name for comparison. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 1024 || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value) || ($at = strrpos($value, '@')) === false) {
            return null;
        }
        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        if ($domain === '' || strlen($local) > 64) {
            return null;
        }
        $atom = "[a-zA-Z0-9!#\$%&'*+\\/=?^_\\x60{|}~\\-\\x{80}-\\x{10ffff}]+";
        $quoted = '"(?:[\x20-\x21\x23-\x5b\x5d-\x7e\x{80}-\x{10ffff}]|\\\\[\x20-\x7e])*"';
        if (! preg_match('/\A(?:'.$atom.'(?:\.'.$atom.')*|'.$quoted.')\z/u', $local)) {
            return null;
        }
        $domain = idn_to_ascii($domain, IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46);
        if ($domain === false || ! str_contains($domain, '.') || str_ends_with($domain, '.')
            || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }
        $address = $local.'@'.strtolower($domain);
        if (strlen($address) > 254) {
            return null;
        }
        try {
            return (new Address($address))->getAddress();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
