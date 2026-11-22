<?php

namespace App\Console\Commands;

use App\DTO\PostfixReceipt;
use App\Models\MailboxDomain;
use App\Services\MailDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

class ParseIncomingMail extends Command
{
    protected $signature = 'mail:parse {--recipient= : Actual envelope recipient supplied by the SMTP transport}
        {--postfix : Deliver from the size-checked local Postfix queue using its acceptance time}
        {--check-limit : Validate and print the configured SMTP intake limit in bytes}';

    protected $description = 'Deliver a single RFC 5322 message from STDIN';

    public function handle(MailDeliveryService $delivery): int
    {
        try {
            $fromSpool = $this->option('postfix');
            $limit = null;
            if (! $fromSpool || $this->option('check-limit')) {
                $value = config('mailbox.max_message_bytes');
                $digits = is_int($value) || is_string($value) ? (string) $value : '';
                $limit = ctype_digit($digits) ? filter_var(ltrim($digits, '0'), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1],
                ]) : false;
                if ($limit === false) {
                    $this->error('Invalid MAILBOX_MAX_MESSAGE_BYTES. Set a positive decimal byte count.');

                    return 75;
                }
                if ($this->option('check-limit')) {
                    $this->line((string) $limit);

                    return self::SUCCESS;
                }
            }

            $recipient = strtolower((string) $this->option('recipient'));
            $separator = strrpos($recipient, '@');
            if (! $separator || strlen($recipient) > 254 || preg_match('/[\x00-\x1f\x7f]/', $recipient)
                || ! MailboxDomain::where('name', substr($recipient, $separator + 1))->exists()) {
                $this->error('5.1.1 Invalid envelope recipient for this mail domain.');

                return 67;
            }

            $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
            $raw = stream_get_contents($stream ?? STDIN, $limit === null ? null : $limit + 1);
            if ($raw === false) {
                throw new RuntimeException('Unable to read incoming mail.');
            }
            if ($raw === '' || ($limit !== null && strlen($raw) > $limit)) {
                $this->error('5.3.4 Message is empty or exceeds the configured size limit.');

                return 65;
            }
            $receipt = $fromSpool
                ? PostfixReceipt::fromMessage($raw, config('mailbox.mx_host'), config('mailbox.previous_mx_hosts'))
                : null;
            $delivery->deliver($recipient, $raw, $receipt);
        } catch (Throwable $exception) {
            rescue(fn () => Log::error('Temporary inbound mail delivery failure.', [
                'exception_type' => $exception::class,
                'code' => $exception->getCode(),
            ]), report: false);
            $this->error('4.3.0 Temporary mail delivery failure.');

            return 75;
        }

        return self::SUCCESS;
    }
}
