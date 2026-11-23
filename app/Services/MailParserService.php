<?php

namespace App\Services;

use App\DTO\ParsedMail;
use Dom\HTMLDocument;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;
use ZBateson\MailMimeParser\Message\IMimePart;

class MailParserService
{
    public function parse(string $raw): ParsedMail
    {
        $message = (new MailMimeParser)->parse($raw, false);
        $this->applyDigestDefaults($message);
        $from = $message->getHeader('From');

        $attachments = [];
        $files = [
            ...$message->getAllAttachmentParts(),
            ...$this->namedInlineFiles($message, 'multipart/mixed'),
            ...$this->namedInlineFiles($message, 'multipart/related'),
        ];
        $signature = $message->getSignaturePart();
        if ($signature && $message->getContentDisposition() !== 'attachment' && ! in_array($signature, $files, true)) {
            $files[] = $signature;
        }
        foreach ($files as $part) {
            $attachments[] = [
                'filename' => AttachmentFilename::normalize($this->text($part->getFilename())),
                'mime' => $this->text($part->getContentType('application/octet-stream')),
                'content' => (string) ($part instanceof IMimePart && $part->isMultiPart()
                    ? $part->getStream() : $part->getBinaryContentStream()),
            ];
        }

        return new ParsedMail(
            from: $this->text($from instanceof AddressHeader ? $from->getEmail() : null) ?? '',
            subject: $this->text($message->getSubject()),
            bodyText: $this->bodyText($message),
            attachments: $attachments,
        );
    }

    private function text(?string $value): ?string
    {
        return $value === null ? null : str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    private function applyDigestDefaults(IMimePart $container): void
    {
        if (! $container->isMultiPart() || $container->getContentDisposition() === 'attachment') {
            return;
        }

        $digest = $container->getContentType() === 'multipart/digest';
        foreach ($container->getChildParts() as $part) {
            if (! $part instanceof IMimePart) {
                continue;
            }
            if ($digest && $part->getHeader('Content-Type') === null) {
                $part->setRawHeader('Content-Type', 'message/rfc822');
            } elseif ($part->isMultiPart()) {
                $this->applyDigestDefaults($part);
            }
        }
    }

    private function namedInlineFiles(IMimePart $container, string $parentType): iterable
    {
        if (! $container->isMultiPart() || $container->getContentDisposition() === 'attachment') {
            return;
        }
        $matchesParent = $container->getContentType() === $parentType;
        $root = $matchesParent && $parentType === 'multipart/related' ? $this->relatedRoot($container) : null;
        foreach ($container->getChildParts() as $part) {
            if ($matchesParent && $part !== $root && $part->getContentDisposition() === 'inline'
                && in_array($part->getContentType(), ['text/plain', 'text/html'], true)
                && $part->getFilename() !== null && $part->getFilename() !== '') {
                yield $part;
            } elseif ($part instanceof IMimePart) {
                yield from $this->namedInlineFiles($part, $parentType);
            }
        }
    }

    private function relatedRoot(IMimePart $container): ?IMessagePart
    {
        $start = trim($container->getHeaderParameter('Content-Type', 'start') ?? '', '<>');
        if ($start === '') {
            return $container->getChild(0);
        }

        return $container->getChild(0, fn (IMessagePart $child) => trim($child->getContentId() ?? '', '<>') === $start)
            ?? $container->getChild(0);
    }

    private function bodyText(IMessagePart $part): ?string
    {
        if ($part->getContentDisposition() === 'attachment') {
            return null;
        }
        $type = $part->getContentType();
        if ($type === 'text/plain') {
            return $this->text($part->getContent());
        }
        if ($type === 'text/html') {
            $html = $this->text($part->getContent());

            return $html === null || $html === '' ? null : self::readableHtml($html);
        }
        if (! $part instanceof IMimePart || ! $part->isMultiPart()) {
            return null;
        }

        if ($type === 'multipart/related') {
            $root = $this->relatedRoot($part);

            return $root ? $this->bodyText($root) : null;
        }

        $sections = [];
        foreach ($part->getChildParts() as $child) {
            $body = $this->bodyText($child);
            if (trim($body ?? '') === '') {
                continue;
            }
            if ($type === 'multipart/alternative' && $child->getContentType() === 'text/plain') {
                return $body;
            }
            $sections[] = $body;
        }
        if ($type === 'multipart/alternative') {
            return array_pop($sections);
        }

        return $sections === [] ? null : implode("\n", $sections);
    }

    public static function readableHtml(string $html): string
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        foreach ($document->querySelectorAll('head, script, style, template, noscript') as $node) {
            $node->remove();
        }
        foreach ($document->querySelectorAll('img[alt]') as $image) {
            $image->replaceWith($document->createTextNode($image->getAttribute('alt')));
        }
        foreach ($document->querySelectorAll('a[href]') as $link) {
            $url = trim($link->getAttribute('href'));
            if (! preg_match('~\Ahttps?://[^\s\\\\]+\z~iD', $url) || ! parse_url($url, PHP_URL_HOST)) {
                continue;
            }
            $label = trim($link->textContent);
            if (! str_contains($label, $url)) {
                $link->append($document->createTextNode($label === '' ? $url : ' ('.$url.')'));
            }
        }
        foreach ($document->querySelectorAll('td, th') as $cell) {
            $cell->after($document->createTextNode(' '));
        }
        foreach ($document->querySelectorAll('br, p, div, li, tr, h1, h2, h3, h4, h5, h6, blockquote') as $node) {
            $node->before($document->createTextNode("\n"));
            $node->after($document->createTextNode("\n"));
        }

        $text = preg_replace('/[ \t\x{a0}]+/u', ' ', $document->documentElement->textContent);

        return trim(preg_replace('/[ \t]*\R(?:[ \t]*\R)*[ \t]*/u', "\n", $text));
    }
}
