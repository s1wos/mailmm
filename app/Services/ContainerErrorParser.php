<?php

namespace App\Services;

class ContainerErrorParser
{
    /** @return array{level: string, message: string}|null */
    public static function parse(string $service, string $line): ?array
    {
        $line = trim(substr($line, 0, strcspn($line, "\r\n")));
        $match = match ($service) {
            'nginx' => self::nginx($line),
            'smtp' => self::postfix($line) ?? self::php($line),
            'app', 'reverb', 'worker', 'scheduler', 'migrate' => self::php($line),
            default => null,
        };
        if ($match === null) {
            return null;
        }

        [$level, $message] = $match;

        return ['level' => $level, 'message' => self::displayMessage($message)];
    }

    private static function nginx(string $line): ?array
    {
        if (! preg_match('~^(?:nginx: |\d{4}/\d{2}/\d{2} [\d:]+ )\[(warn|error|crit|alert|emerg)\] (.+)$~', $line, $match)) {
            return null;
        }
        $levels = ['warn' => 'warning', 'error' => 'error', 'crit' => 'critical', 'alert' => 'alert', 'emerg' => 'emergency'];

        return [$levels[$match[1]], $match[2]];
    }

    private static function postfix(string $line): ?array
    {
        if (! preg_match('~^[A-Z][a-z]{2}\s+\d{1,2} [\d:]+ \S+ (postfix/[\w/-]+)\[\d+\]: (.+)$~', $line, $record)) {
            return null;
        }
        [, $daemon, $body] = $record;
        if (preg_match('/^(warning|error|fatal|panic): /i', $body, $priority)) {
            $level = match (strtolower($priority[1])) {
                'panic' => 'emergency',
                'fatal' => 'critical',
                default => strtolower($priority[1]),
            };
        } elseif (preg_match('/^[A-Za-z0-9]+: (?:reject|defer): /', $body)) {
            $level = 'warning';
        } elseif (preg_match('/^[A-Za-z0-9]+: to=<.*>, .*dsn=[245]\.\d+\.\d+, status=(\w+)\b/', $body, $delivery)) {
            $level = match ($delivery[1]) {
                'bounced', 'expired' => 'error',
                'deferred' => 'warning',
                default => null,
            };
        } else {
            return null;
        }

        return $level === null ? null : [$level, $daemon.': '.$body];
    }

    private static function php(string $line): ?array
    {
        if (preg_match('/^\[[\w: -]+\] \w+: \[pool [^]]+\] child \d+ said into std(?:out|err): "(.*)"$/', $line, $child)) {
            $line = $child[1];
        }
        if (preg_match('/^\[\d{4}-\d{2}-\d{2}[ T][\d:.+Z-]+\] [\w.-]+\.(WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): (.+)$/', $line, $record)) {
            $message = preg_split('/\s+(?:\{["}]|\[\](?: |$)|\(Connection: |DETAIL: )/', $record[2], 2)[0];

            return [strtolower($record[1]), $message];
        }
        if (preg_match('/^(?:PHP message: )?(?:\[\d{2}-[A-Za-z]{3}-\d{4} [\d:]+ [^]]+\] )?PHP (Warning|Fatal error|Parse error):\s*(.+)$/i', $line, $record)) {
            return [strtolower($record[1]) === 'warning' ? 'warning' : 'critical', $record[2]];
        }
        if (preg_match('/^\[\d{2}-[A-Za-z]{3}-\d{4} [\d:]+\] (WARNING|ERROR|ALERT): (.+)$/', $line, $record)) {
            return [strtolower($record[1]), $record[2]];
        }

        return null;
    }

    private static function displayMessage(string $message): string
    {
        $message = preg_replace(
            [
                '/[\x00-\x08\x0B-\x1F\x7F]/',
                '~(://)[^/\s@]+@~',
                '~\?[^\s"\'<>]+~',
                '/\b(authorization|cookie):.*/i',
                '/\b((?:\w+_)?(?:password|secret|token|api_key))\s*[:=]\s*(?:"[^"]*(?:"|$)|\'[^\']*(?:\'|$)|[^\s,;]+)/i',
            ],
            ['', '$1[redacted]@', '?…', '$1: [redacted]', '$1=[redacted]'],
            $message,
        );

        return mb_strcut(mb_scrub(trim($message), 'UTF-8'), 0, 8192, 'UTF-8');
    }
}
