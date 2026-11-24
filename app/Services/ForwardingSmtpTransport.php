<?php

namespace App\Services;

use App\Exceptions\ForwardingFailure;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Track the DATA terminator so a lost final reply cannot cause a duplicate delivery. */
final class ForwardingSmtpTransport extends EsmtpTransport
{
    private bool $ehloAccepted = false;

    public bool $terminatorSent = false;

    public bool $eightBit = false;

    public bool $utf8Headers = false;

    public int $wireSize = 0;

    protected function serverSupportsSmtpUtf8(): bool
    {
        if (! $this->ehloAccepted || ! parent::serverSupportsSmtpUtf8()) {
            throw new ForwardingFailure('smtp_unsupported');
        }

        return true;
    }

    public function executeCommand(string $command, array $codes): string
    {
        if (str_starts_with($command, 'MAIL FROM:')) {
            // HELO fallback in Symfony skips capability negotiation. Enforce the relay contract here too.
            $caps = $this->ehloAccepted ? $this->getCapabilities() : [];
            if (($this->isTlsRequired() && ! $this->ehloAccepted && ! $this->getStream()->isTLS())
                || ($this->getUsername() !== '' && ! isset($caps['AUTH']))) {
                throw new ForwardingFailure('transport_unconfigured');
            }
            if (($this->eightBit && ! isset($caps['8BITMIME'])) || ($this->utf8Headers && ! isset($caps['SMTPUTF8']))) {
                throw new ForwardingFailure('smtp_unsupported');
            }
            if ((int) ($caps['SIZE'][0] ?? 0) > 0 && $this->wireSize > (int) $caps['SIZE'][0]) {
                throw new ForwardingFailure('message_too_large');
            }
            $command = rtrim($command, "\r\n");
            if ($this->eightBit) {
                $command .= ' BODY=8BITMIME';
            }
            if ($this->utf8Headers && ! str_contains($command, ' SMTPUTF8')) {
                $command .= ' SMTPUTF8';
            }
            $command .= "\r\n";
        }
        if ($command === "\r\n.\r\n") {
            $this->terminatorSent = true;
        }
        // Read structured SMTP reply lines directly, without examining or logging exception text.
        if (str_starts_with($command, 'RCPT TO:') || $command === "\r\n.\r\n") {
            $response = parent::executeCommand($command, array_merge($codes, range(500, 599)));
            if ((int) $response >= 500) {
                foreach (explode("\r\n", rtrim($response, "\r\n")) as $line) {
                    if (! preg_match('/^5\d\d[ -](5\.\d{1,3}\.\d{1,3})(?:[ \t]|$)/D', $line, $status)
                        || ! ForwardingFeedback::isPermanentDestinationFailure($status[1])) {
                        throw new ForwardingFailure('smtp_rejected');
                    }
                }
                throw new ForwardingFailure('recipient_rejected');
            }

            return $response;
        }
        $response = parent::executeCommand($command, $codes);
        if (str_starts_with($command, 'EHLO ')) {
            $this->ehloAccepted = true;
        }

        return $response;
    }
}
