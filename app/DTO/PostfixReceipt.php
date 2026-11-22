<?php

namespace App\DTO;

use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use TypeError;
use UnexpectedValueException;

readonly class PostfixReceipt
{
    private const ALPHABET = '0123456789BCDFGHJKLMNPQRSTVWXYZbcdfghjklmnpqrstvwxyz';

    private function __construct(public CarbonImmutable $receivedAt, public CarbonImmutable $latestPossible) {}

    /** @param list<string> $previousHosts */
    public static function fromMessage(string $raw, string $host, array $previousHosts = []): self
    {
        if ($host === '' || ! preg_match('/\AReceived:[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*/i', $raw, $fields)) {
            throw new UnexpectedValueException('Expected the local Postfix trace.');
        }
        $trace = $fields[0];
        $by = '/(?:\AReceived:[ \t]*|\n[ \t]+)by[ \t]+([a-z0-9.-]+)'
            .'[ \t]+\(Postfix\)(?:[ \t]+with[ \t]+[a-z0-9]+)?[ \t]+id[ \t]+([a-z0-9]+)(?=[\s;])/i';
        if (! preg_match($by, $trace, $matches) || ! in_array(strtolower($matches[1]), [strtolower($host), ...$previousHosts], true)) {
            throw new UnexpectedValueException('Expected a queue ID from the configured Postfix host.');
        }
        $id = $matches[2];
        $date = trim(substr($trace, (int) strrpos($trace, ';') + 1));
        if (! preg_match('/^([A-Za-z]{3}),\s+(\d{1,2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4})(?:\s+\([^()]*\))?$/D', $date, $parts)) {
            throw new UnexpectedValueException('Invalid Postfix trace date.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!j M Y H:i:s O', $parts[2]);
        if (! $parsed || DateTimeImmutable::getLastErrors() || $parsed->format('D') !== $parts[1]) {
            throw new UnexpectedValueException('Invalid Postfix trace date.');
        }
        $headerTime = CarbonImmutable::instance($parsed)->utc();
        if ($headerTime->greaterThan(now())) {
            throw new UnexpectedValueException('Postfix trace date is in the future.');
        }
        if (preg_match('/^[0-9A-F]{6,}$/D', $id)) {
            return new self($headerTime, $headerTime->endOfSecond());
        }

        $digits = self::ALPHABET;
        $inodeDigits = substr($digits, 0, -1);
        if (! preg_match("/^([$digits]{6,10})([$digits]{4})z[$inodeDigits]+$/D", $id, $parts)) {
            throw new UnexpectedValueException('Invalid Postfix queue ID.');
        }
        try {
            $seconds = BigInteger::fromArbitraryBase($parts[1], $digits)->toInt();
        } catch (IntegerOverflowException $exception) {
            throw new TypeError('Postfix queue timestamp exceeds the native integer range.', previous: $exception);
        }
        $micros = BigInteger::fromArbitraryBase($parts[2], $digits)->toInt();
        if ($seconds < $headerTime->timestamp || $seconds > now()->timestamp || $micros >= 1_000_000) {
            throw new UnexpectedValueException('Inconsistent Postfix queue timestamp.');
        }
        $accepted = CarbonImmutable::createFromTimestampUTC($seconds)->setMicrosecond($micros);
        if ($accepted->greaterThan(now())) {
            throw new UnexpectedValueException('Postfix queue timestamp is in the future.');
        }

        return new self($accepted, $accepted);
    }
}
