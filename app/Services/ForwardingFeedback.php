<?php

namespace App\Services;

use App\Exceptions\ForwardingFailure;
use App\Models\MailboxDomain;
use App\Models\MailboxForwardingRule;
use App\Models\MailForward;
use App\Rules\ForwardingAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\MailMimeParser;

final class ForwardingFeedback
{
    public static function isPermanentDestinationFailure(string $status): bool
    {
        // Enhanced status codes for invalid destination addresses, moved/disabled mailboxes and null MX.
        return in_array($status, ['5.1.1', '5.1.2', '5.1.3', '5.1.6', '5.1.10', '5.2.1'], true);
    }

    public static function address(string $id): string
    {
        $domain = (string) config('forwarding.bounce_domain');
        if ($domain === '' || ! Str::isUuid($id)) {
            throw new ForwardingFailure('transport_unconfigured');
        }
        if (MailboxDomain::where('name', $domain)->exists()) {
            throw new ForwardingFailure('bounce_domain_conflict');
        }

        return self::signedAddress($id, $domain);
    }

    private static function signedAddress(string $id, string $domain): string
    {
        $mac = substr(hash_hmac('sha256', "forward-bounce\0".$id, config('app.key')), 0, 24);

        return str_replace('-', '', $id).'.'.$mac.'@'.$domain;
    }

    /** Called only for the actual envelope RCPT and an empty SMTP reverse path. */
    public static function receiveDsn(string $recipient, string $raw): bool
    {
        $domain = (string) config('forwarding.bounce_domain');
        if ($domain === '' || strcasecmp(Str::afterLast($recipient, '@'), $domain) !== 0) {
            return false;
        }
        if (! preg_match('/^([a-f0-9]{8})([a-f0-9]{4})([a-f0-9]{4})([a-f0-9]{4})([a-f0-9]{12})\.[a-f0-9]{24}@/i', $recipient, $parts)) {
            return false;
        }
        $id = strtolower(implode('-', array_slice($parts, 1)));
        if (! Str::isUuid($id) || ! hash_equals(self::signedAddress($id, $domain), strtolower($recipient))) {
            return false;
        }
        $op = MailForward::find($id);
        if (! $op) {
            return false;
        }
        $report = (new MailMimeParser)->parse($raw, false);
        $status = $report->getChild(1);
        if ($report->getContentType() !== 'multipart/report'
            || ! in_array(strtolower((string) $report->getHeaderParameter('Content-Type', 'report-type')), ['delivery-status', 'global-delivery-status'], true)
            || ! in_array($status?->getContentType(), ['message/delivery-status', 'message/global-delivery-status'], true)) {
            return false;
        }
        $blocks = preg_split('/\r?\n\r?\n/', (string) $status->getBinaryContentStream());
        $reason = null;
        foreach (array_slice($blocks, 1) as $block) {
            $fields = preg_replace('/\r?\n[ \t]+/', ' ', $block);
            if (! preg_match('/^Action:[ \t]*failed[ \t]*\r?$/mi', $fields)
                || ! preg_match('/^Status:[ \t]*(5\.\d{1,3}\.\d{1,3})[ \t]*\r?$/mi', $fields, $statusCode)) {
                continue;
            }
            $final = self::reportRecipient($fields, 'Final-Recipient');
            if ($final === $op->destination || self::reportRecipient($fields, 'Original-Recipient') === $op->destination) {
                $reason = 'smtp_rejected';
                if ($final === $op->destination && self::isPermanentDestinationFailure($statusCode[1])) {
                    $reason = 'permanent_bounce';
                    break;
                }
            }
        }
        if ($reason === null) {
            return false;
        }
        self::record($id, $reason);

        return true;
    }

    private static function reportRecipient(string $block, string $field): ?string
    {
        if (preg_match('/^'.$field.':[ \t]*(?:rfc822|utf-8);[ \t]*([^\r\n]+)\r?$/mi', $block, $match)) {
            return ForwardingAddress::normalize(trim($match[1]));
        }

        return null;
    }

    public static function record(string $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $hint = MailForward::find($id);
            if (! $hint) {
                return;
            }
            $rule = $hint->rule_id ? MailboxForwardingRule::lockForUpdate()->find($hint->rule_id) : null;
            // Feedback may arrive before the worker gets its final 250 or after the worker disappears.
            $op = MailForward::lockForUpdate()->find($id);
            $hard = in_array($reason, ['permanent_bounce', 'complaint'], true);
            $upgrade = $hard && $op?->status === 'bounced' && $op->error_code === 'smtp_rejected';
            if (! $op || (! $upgrade && ! in_array($op->status, ['submitting', 'accepted', 'unknown'], true))) {
                return;
            }
            $op->update(['status' => 'bounced', 'error_code' => $reason]);
            $op->logOutcome();
            if ($rule && $rule->version === $op->rule_version && $rule->status === 'active' && $rule->destination === $op->destination) {
                if ($hard) {
                    ForwardingRequests::disableRule($rule, $reason);
                } else {
                    $rule->update(['last_error_code' => $reason]);
                }
            }
        }, 3);
    }
}
