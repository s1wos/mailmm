<?php

namespace App\Services;

use App\Exceptions\ForwardingFailure;
use App\Rules\ForwardingAddress;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMimePart;

class ForwardingTransport
{
    public function send(string $profile, RawMessage $message, string $destination, string $reversePath): void
    {
        $wire = $message->toString();
        if (strlen($wire) > (int) config('forwarding.max_bytes', 33554432)) {
            throw new ForwardingFailure('message_too_large');
        }
        if (preg_match('/\x00|(?<!\r)\n|\r(?!\n)|(?:\A|\n)[^\r\n]{999}/', $wire) || ! str_ends_with($wire, "\r\n")) {
            throw new ForwardingFailure('invalid_original');
        }
        if (! ForwardingAddress::valid($destination)) {
            throw new ForwardingFailure('invalid_recipient');
        }
        try {
            $dsn = Dsn::fromString((string) config('forwarding.'.$profile.'_dsn'));
            if (! in_array($dsn->getScheme(), ['smtp', 'smtps'], true)) {
                throw new ForwardingFailure('transport_unconfigured');
            }
            $envelope = new Envelope(new Address($reversePath), [new Address($destination)]);
            $smtp = new ForwardingSmtpTransport($dsn->getHost(), $dsn->getPort(0), $dsn->getScheme() === 'smtps');
            $loopback = app()->environment('testing', 'local') && in_array($dsn->getHost(), ['127.0.0.1', 'localhost', '[::1]'], true);
            $smtp->setRequireTls(! $loopback);
            $smtp->setUsername($dsn->getUser() ?? '')->setPassword($dsn->getPassword() ?? '');
            $smtp->getStream()->setTimeout(max(1, min(20, (int) config('forwarding.timeout', 15))));
        } catch (\Exception) {
            throw new ForwardingFailure('transport_unconfigured');
        }
        $smtp->wireSize = strlen($wire);
        $smtp->eightBit = (bool) preg_match('/[\x80-\xff]/', $wire);
        $smtp->utf8Headers = $smtp->eightBit && $this->usesUtf8Headers($wire);
        // Symfony adds CRLF with the final dot. Remove exactly that one line ending to preserve the body.
        try {
            $smtp->send(new RawMessage(substr($wire, 0, -2)), $envelope);
        } catch (TransportExceptionInterface $failure) {
            $code = $failure->getCode();
            if ($code >= 500 && $code < 600) {
                throw new ForwardingFailure('smtp_rejected');
            }
            if (($code < 400 || $code >= 500) && $smtp->terminatorSent) {
                throw new ForwardingFailure('submission_unknown', 'unknown');
            }
            throw new ForwardingFailure('smtp_temporary', 'retry');
        } catch (ForwardingFailure $failure) {
            throw $failure;
        } catch (\Exception) {
            throw new ForwardingFailure($smtp->terminatorSent ? 'submission_unknown' : 'transport_unconfigured',
                $smtp->terminatorSent ? 'unknown' : 'failed');
        }
    }

    private function usesUtf8Headers(string $wire): bool
    {
        $head = strstr($wire, "\r\n\r\n", true);
        if (preg_match('/[\x80-\xff]/', $head)) {
            return true;
        }
        if (! preg_match('~^Content-Type:\s*(?:multipart|message)/~im', $head)) {
            return false;
        }
        $mime = (new MailMimeParser)->parse($wire, false);

        return $mime->getPart(0, static function ($part) {
            if ($part instanceof IMimePart) {
                foreach ($part->getRawHeaderIterator() as [, $value]) {
                    if (preg_match('/[\x80-\xff]/', $value)) {
                        return true;
                    }
                }
            }

            return false;
        }) !== null;
    }
}
