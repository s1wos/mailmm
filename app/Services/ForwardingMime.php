<?php

namespace App\Services;

use App\Exceptions\ForwardingFailure;
use App\Rules\ForwardingAddress;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\RawMessage;
use ZBateson\MailMimeParser\Header\AddressHeader;

final class ForwardingMime
{
    /** Split only the outer fields. Preserve their exact order and folding and all nested MIME bytes. */
    private static function split(string $raw): array
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        if (count($parts) !== 2 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|(?<!\r)\n|\r(?!\n)/', $parts[0])) {
            throw new ForwardingFailure('invalid_original');
        }
        $fields = [];
        foreach (preg_split('/\r\n(?![ \t])/', $parts[0]) as $field) {
            if (! preg_match('/\A([!-9;-~]+):/', $field, $name)) {
                throw new ForwardingFailure('invalid_original');
            }
            $fields[] = [strtolower($name[1]), $field];
        }

        return [$fields, $parts[1]];
    }

    public static function automatic(string $raw, string $id, string $authentication): RawMessage
    {
        return self::redirect($raw, $id, $authentication, 'auto');
    }

    public static function manual(string $raw, string $id, string $authentication): RawMessage
    {
        return self::redirect($raw, $id, $authentication, 'manual');
    }

    private static function redirect(string $raw, string $id, string $authentication, string $mode): RawMessage
    {
        [$fields, $body] = self::split($raw);
        if ($mode === 'manual' && ! self::hasAuthor($fields)) {
            $service = config('forwarding.service_address');
            if (! ForwardingAddress::valid($service)) {
                throw new ForwardingFailure('transport_unconfigured');
            }
            // Ambiguous authors cannot be reconstructed. Keep the MIME tree but discard the unusable author's claims.
            $fields = array_filter($fields, static fn ($field) => ! in_array($field[0],
                ['from', 'sender', 'reply-to', 'dkim-signature', 'arc-seal', 'arc-message-signature', 'arc-authentication-results'], true));
            $replacement = new Headers;
            $replacement->addMailboxListHeader('From', [new Address($service, 'Исходный отправитель неизвестен')]);
            $fields[] = ['from', rtrim($replacement->toString(), "\r\n")];
            $authentication = config('forwarding.authserv_id').'; none';
        }
        $from = 0;
        $preserved = [];
        $publicCopies = null;
        $discardArc = false;
        foreach ($fields as [$name, $field]) {
            $value = trim(substr($field, strpos($field, ':') + 1));
            if ($name === 'from' && ($value === '' || ++$from > 1)) {
                throw new ForwardingFailure('invalid_from');
            }
            // A manually selected recipient may read the public letter, never the delivery trace or blind copies.
            if ($mode === 'manual' && ! self::publicMessageField($name)) {
                if (! in_array($name, ['dkim-signature', 'arc-seal', 'arc-message-signature', 'arc-authentication-results'], true)) {
                    continue;
                }
                if (in_array($name, ['dkim-signature', 'arc-message-signature'], true) && preg_match_all('/(?:^|;)\s*z\s*=([^;]*)/i', $value, $tags)) {
                    $publicCopies ??= self::publicHeaderCopies($fields);
                    if (! self::visibleDkimCopies($tags[1], $publicCopies)) {
                        if ($name === 'arc-message-signature') {
                            $discardArc = true;
                        }
                        continue;
                    }
                }
            }
            if (in_array($name, ['bcc', 'resent-bcc'], true) && $value !== '') {
                throw new ForwardingFailure('hidden_recipient');
            }
            if ($name !== 'return-path') {
                $preserved[] = $field;
            }
        }
        if ($discardArc) {
            // A partial upstream ARC chain cannot be validated. The outgoing sealer starts a new chain.
            $preserved = array_filter($preserved, static fn ($field) => strncasecmp($field, 'ARC-', 4) !== 0);
        }
        if ($from !== 1) {
            throw new ForwardingFailure('invalid_from');
        }
        if (preg_match('/[\r\n\x00]/', $authentication)) {
            throw new ForwardingFailure('invalid_authentication_results');
        }
        $marker = ForwardingMarker::value($mode, $id, $body);
        $result = wordwrap('Authentication-Results: '.$authentication, 78, "\r\n ", false);
        if (preg_match('/(?:\A|\n)[^\r\n]{999}/', $result)) {
            throw new ForwardingFailure('invalid_authentication_results');
        }

        return new RawMessage(ForwardingMarker::HEADER.': '.$marker."\r\n".$result."\r\n"
            .implode("\r\n", $preserved)."\r\n\r\n".$body);
    }

    private static function publicMessageField(string $name): bool
    {
        return str_starts_with($name, 'content-') || in_array($name, [
            'from', 'sender', 'reply-to', 'to', 'cc', 'subject', 'date', 'message-id',
            'references', 'in-reply-to', 'mime-version',
        ], true);
    }

    private static function publicHeaderCopies(array $fields): array
    {
        $public = [];
        foreach ($fields as [$name, $field]) {
            if (self::publicMessageField($name)) {
                $public[self::copyKey($field)] = true;
            }
        }

        return $public;
    }

    private static function copyKey(string $field): string
    {
        [$name, $value] = explode(':', $field, 2);

        return strtolower($name).':'.trim(preg_replace('/\r\n[ \t]+/', ' ', $value), " \t");
    }

    /** RFC 6376 z= may copy headers such as Bcc or an earlier value of To. */
    private static function visibleDkimCopies(array $tags, array $public): bool
    {
        foreach ($tags as $tag) {
            // Folding whitespace can occur anywhere. A literal pipe in a copied value is encoded as =7C.
            foreach (explode('|', preg_replace('/\s+/', '', $tag)) as $copy) {
                $field = quoted_printable_decode($copy);
                if (! str_contains($field, ':') || ! isset($public[self::copyKey($field)])) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function hasAuthor(array $fields): bool
    {
        $authors = array_values(array_filter($fields, static fn ($field) => $field[0] === 'from'));
        if (count($authors) !== 1) {
            return false;
        }
        $value = preg_replace('/\r\n[ \t]+/', ' ', substr($authors[0][1], strlen('From:')));
        $addresses = (new AddressHeader('From', $value))->getAddresses();
        foreach ($addresses as $address) {
            if (! ForwardingAddress::valid($address->getEmail())) {
                return false;
            }
        }

        return $addresses !== [];
    }

    public static function verification(string $to, string $code): RawMessage
    {
        $id = (string) Str::uuid();
        $body = 'Код подтверждения пересылки MailMM: '.$code."\r\n";
        $headers = self::newHeaders($id, $to, null, 'Код подтверждения пересылки MailMM', 'MailMM');
        $headers->addMailboxListHeader('Reply-To', [new Address(config('forwarding.service_address'))]);
        $headers->addTextHeader('MIME-Version', '1.0');
        $headers->addTextHeader('Content-Type', 'text/plain; charset=UTF-8');
        $headers->addTextHeader('Content-Transfer-Encoding', '8bit');
        $headers->addTextHeader(ForwardingMarker::HEADER, ForwardingMarker::value('verification', $id, $body));

        return new RawMessage($headers->toString()."\r\n".$body);
    }

    public static function newHeaders(string $id, string $to, ?string $author, string $subject, string $fallbackName): Headers
    {
        $service = config('forwarding.service_address');
        if (! ForwardingAddress::valid($service)) {
            throw new ForwardingFailure('transport_unconfigured');
        }
        if (! ForwardingAddress::valid($to)) {
            throw new ForwardingFailure('invalid_recipient');
        }
        $headers = new Headers;
        $address = ForwardingAddress::normalize($author);
        $headers->addMailboxListHeader('From', [$address === null ? new Address($service, $fallbackName) : new Address($address)]);
        $headers->addMailboxListHeader('To', [new Address($to)]);
        $headers->addDateHeader('Date', now());
        $headers->addIdHeader('Message-ID', $id.'@'.Str::afterLast($service, '@'));
        $headers->addTextHeader('Subject', trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $subject)));

        return $headers;
    }
}
