<?php

namespace App\Services;

final class MailCssImages
{
    // Tokenize without reserializing selectors, declarations, comments or ordinary strings.
    private const TOKEN = <<<'REGEX'
    ~
        /\* [^*]*+ (?: \* (?!/) [^*]*+ )*+ (?: \*/ | \z )
      | " (?: [^"\\\r\n\f]++ | \\ (?: \r\n | . )? )*+ "?
      | ' (?: [^'\\\r\n\f]++ | \\ (?: \r\n | . )? )*+ '?
      | (?<name> (?: [\w\x80-\xff-]++ | \\ (?: [\da-fA-F]{1,6} (?: \r\n | [\t\n\f\r ])? | [^\r\n\f] ) )++ ) (?<open>\()?
      | [^/'"()\\\w\x80-\xff-]++
      | .
    ~Asx
    REGEX;

    private const URL_ARGUMENT = <<<'REGEX'
    ~ [\t\n\f\r ]*+
      (?:
          " (?<double> (?: [^"\\\r\n\f]++ | \\ (?: \r\n | . ) )*+ ) "
        | ' (?<single> (?: [^'\\\r\n\f]++ | \\ (?: \r\n | . ) )*+ ) '
        | (?<bare> (?: [^\x00-\x20"'()\\\x7f]++ | \\ (?: [\da-fA-F]{1,6} (?: \r\n | [\t\n\f\r ])? | [^\r\n\f] ) )*+ )
      ) (?: [\t\n\f\r ]++ | /\* [^*]*+ (?: \* (?!/) [^*]*+ )*+ \*/ )*+ \)
    ~Asx
    REGEX;

    /** @param callable(string): ?string $resolve */
    public static function rewrite(string $css, callable $resolve, bool &$hasImage): string
    {
        if (! str_contains($css, '(')) {
            return $css;
        }
        $result = '';
        $depth = 0;
        $contexts = '';
        $offset = 0;
        while ($offset < strlen($css)) {
            if (! preg_match(self::TOKEN, $css, $match, 0, $offset)) {
                return '';
            }
            $token = $match[0];
            $offset += strlen($token);
            if (isset($match['open']) || $token === '(') {
                $name = strtolower(self::unescape($match['name'] ?? ''));
                if ($name === 'url' && preg_match(self::URL_ARGUMENT, $css, $arg, PREG_UNMATCHED_AS_NULL, $offset)) {
                    $offset += strlen($arg[0]);
                    $result .= 'url('.self::imageString($arg['double'] ?? $arg['single'] ?? $arg['bare'], $resolve, $hasImage).')';

                    continue;
                }
                // Disable a malformed URL function while preserving the surrounding declarations.
                if ($name === 'url') {
                    $token = 'invalid-url(';
                }
                $contexts[$depth++] = in_array($name, ['image-set', '-webkit-image-set'], true) ? 'i' : '(';
            } elseif ($token === ')') {
                $depth = max(0, $depth - 1);
            } elseif (($token[0] === '"' || $token[0] === "'") && $depth && $contexts[$depth - 1] === 'i') {
                $token = self::imageString(substr($token, 1, -1), $resolve, $hasImage);
            }
            $result .= $token;
        }

        return $result;
    }

    private static function imageString(string $value, callable $resolve, bool &$hasImage): string
    {
        $url = $resolve(self::unescape($value));
        $hasImage = $hasImage || $url !== null;

        return '"'.addcslashes($url ?? 'data:,', '"\\').'"';
    }

    private static function unescape(string $value): string
    {
        if (! str_contains($value, '\\')) {
            return $value;
        }

        return preg_replace_callback('~\\\\(?:([\da-fA-F]{1,6})(?:\r\n|[\t\n\f\r ])?|(\r\n|[\r\n\f])|(.))~s', static function ($escape) {
            if ($escape[1] === '') {
                return $escape[3] ?? '';
            }
            $code = hexdec($escape[1]);

            return $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF) ? mb_chr($code, 'UTF-8') : "\u{fffd}";
        }, $value);
    }
}
