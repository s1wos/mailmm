<?php

namespace App\Services;

use App\Exceptions\ForwardingFailure;
use App\Models\Message;
use App\Rules\ForwardingAddress;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\TextPart;

/** Rebuild only the MIME content of a manual forward from the fields held by the archive. */
final class ForwardingArchive
{
    public static function mime(Message $message, string $id, string $destination): string
    {
        $limit = (int) config('forwarding.max_bytes');
        $html = $message->body_html ?? '';
        $text = $message->body_text ?? '';
        $remaining = $limit - strlen($html) - strlen($text);
        if ($remaining < 0) {
            throw new ForwardingFailure('message_too_large');
        }
        if ($text === '' && $html !== '') {
            $text = MailParserService::readableHtml($html);
        }
        // The archived recipient is the SMTP envelope recipient and may have been a blind copy.
        $author = ForwardingAddress::normalize($message->from);
        $authorLine = $author === null ? 'Исходный отправитель неизвестен' : 'От: '.$author;
        $summary = $authorLine."\r\n".'Получено: '.$message->received_at->toRfc2822String();
        $body = new TextPart($summary."\r\n\r\n".$text, 'utf-8', 'plain', 'base64');
        if ($html !== '') {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
            $byline = $document->createElement('p');
            $byline->textContent = $authorLine;
            $document->body->prepend($byline);
            $body = new AlternativePart($body, new TextPart($document->saveHtml(), 'utf-8', 'html', 'base64'));
        }

        $parts = [];
        foreach ($message->attachments ?? [] as $index => $file) {
            $size = $file['size'] ?? null;
            if (! is_int($size) || $size < 0) {
                throw new ForwardingFailure('archive_attachment_unavailable');
            }
            if ($size > $remaining) {
                throw new ForwardingFailure('message_too_large');
            }
            $remaining -= $size;
            $parts[] = self::attachment($message, $index, $file);
        }
        if ($parts !== []) {
            $body = new MixedPart($body, ...$parts);
        }
        $headers = ForwardingMime::serviceHeaders($id, $destination, (string) $message->subject);
        if ($author !== null) {
            $headers->addMailboxListHeader('Reply-To', [new Address($author)]);
        }
        $headers->addTextHeader('MIME-Version', '1.0');
        $wire = $headers->toString();
        foreach ($body->toIterable() as $chunk) {
            if (strlen($wire) + strlen($chunk) > $limit) {
                throw new ForwardingFailure('message_too_large');
            }
            $wire .= $chunk;
        }

        return str_ends_with($wire, "\r\n") ? $wire : $wire."\r\n";
    }

    private static function attachment(Message $message, int $index, array $file): DataPart
    {
        $path = str_replace('\\', '/', $file['path'] ?? '');
        $key = $message->delivery_key;
        if ($key !== null) {
            $owned = preg_match('/\A[a-f0-9]{64}\z/', $key) && $path === 'mail/'.substr($key, 0, 2).'/'.$key.'-'.$index;
        } else {
            $owned = str_starts_with($path, 'emails/'.$message->id.'/') && ! in_array('..', explode('/', $path), true);
        }
        if (! $owned || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new ForwardingFailure('archive_attachment_unavailable');
        }
        try {
            $stream = Storage::disk('local')->readStream($path);
        } catch (UnableToReadFile) {
            throw new ForwardingFailure('archive_attachment_unavailable');
        }
        if (! is_resource($stream)) {
            throw new ForwardingFailure('archive_attachment_unavailable');
        }
        try {
            $bytes = stream_get_contents($stream, $file['size'] + 1);
        } finally {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) !== $file['size']) {
            throw new ForwardingFailure('archive_attachment_unavailable');
        }
        $mime = $file['mime'] ?? '';
        if (! preg_match('~\A[a-z0-9][a-z0-9!#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\z~i', $mime)) {
            $mime = 'application/octet-stream';
        }

        return new DataPart($bytes, AttachmentFilename::normalize($file['filename'] ?? null), $mime);
    }
}
