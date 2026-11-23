<?php

namespace App\Console\Commands;

use App\Console\Requests\ParseIncomingMailRequest;
use App\DTO\PostfixReceipt;
use App\Exceptions\InvalidMailInput;
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
            $checkLimit = $this->option('check-limit');
            $limit = $checkLimit || ! $fromSpool ? ParseIncomingMailRequest::validateIntakeLimit(config('mailbox.max_message_bytes')) : null;
            if ($checkLimit) {
                $this->line((string) $limit);

                return self::SUCCESS;
            }

            $recipient = strtolower((string) $this->option('recipient'));
            ParseIncomingMailRequest::validateRecipient($recipient);
            $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
            $raw = stream_get_contents($stream ?? STDIN, $limit === null ? null : $limit + 1);
            if ($raw === false) {
                throw new RuntimeException('Unable to read incoming mail.');
            }
            ParseIncomingMailRequest::validateMessage($raw, $limit);
            $receipt = $fromSpool
                ? PostfixReceipt::fromMessage($raw, config('mailbox.mx_host'), config('mailbox.previous_mx_hosts'))
                : null;
            $delivery->deliver($recipient, $raw, $receipt);
        } catch (InvalidMailInput $exception) {
            $this->error($exception->getMessage());

            return $exception->getCode();
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
