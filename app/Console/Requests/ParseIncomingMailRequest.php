<?php

namespace App\Console\Requests;

use App\Exceptions\InvalidMailInput;
use App\Models\MailboxDomain;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ParseIncomingMailRequest
{
    public static function validateRecipient(string $recipient): void
    {
        $separator = strrpos($recipient, '@');
        $domain = $separator && strlen($recipient) <= 254 && ! preg_match('/[\x00-\x1f\x7f]/', $recipient)
            ? substr($recipient, $separator + 1) : null;
        if (Validator::make(['domain' => $domain], [
            'domain' => ['bail', 'required', Rule::exists(MailboxDomain::class, 'name')],
        ])->fails()) {
            throw new InvalidMailInput('5.1.1 Invalid envelope recipient for this mail domain.', 67);
        }
    }

    public static function validateIntakeLimit(mixed $value): int
    {
        $digits = is_int($value) || is_string($value) ? (string) $value : '';
        $limit = ctype_digit($digits) ? filter_var(ltrim($digits, '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1],
        ]) : false;
        if ($limit === false) {
            throw new InvalidMailInput('Invalid MAILBOX_MAX_MESSAGE_BYTES. Set a positive decimal byte count.', 75);
        }

        return $limit;
    }

    public static function validateMessage(string $raw, ?int $limit): void
    {
        if ($raw === '' || ($limit !== null && strlen($raw) > $limit)) {
            throw new InvalidMailInput('5.3.4 Message is empty or exceeds the configured size limit.', 65);
        }
    }
}
