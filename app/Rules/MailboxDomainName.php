<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class MailboxDomainName implements ValidationRule
{
    public const INVALID_NAME = 'Введите домен длиной до 252 символов с двумя или более DNS-метками. Для кириллицы используйте Punycode.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::parse($value) === null) {
            $fail(self::INVALID_NAME);
        }
    }

    public static function parse(string $value): ?string
    {
        $name = strtolower(trim($value, " \t\n\r\v\f"));
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        }
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        return strlen($name) <= 252 && preg_match("/\\A{$label}(?:\\.{$label})+\\z/", $name) ? $name : null;
    }
}
