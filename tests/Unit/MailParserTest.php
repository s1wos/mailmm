<?php

namespace Tests\Unit;

use App\Services\MailParserService;
use PHPUnit\Framework\TestCase;

class MailParserTest extends TestCase
{
    public function test_a_digest_preserves_the_nested_message_with_explicit_or_default_content_type(): void
    {
        $nested = "From: author@example.test\r\nSubject: Entry\r\nContent-Type: text/plain\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode("Original entry\r\n");
        foreach (["Content-Type: message/rfc822\r\n", ''] as $typeHeader) {
            $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=outer\r\n\r\n"
                ."--outer\r\nContent-Type: multipart/digest; boundary=entries\r\n\r\n"
                ."--entries\r\nContent-Type: text/plain\r\n\r\nIntroduction\r\n"
                ."--entries\r\n".$typeHeader."\r\n".$nested."\r\n--entries--\r\n--outer--\r\n";
            $mail = (new MailParserService)->parse($raw);

            self::assertSame('Introduction', $mail->bodyText);
            self::assertSame([['filename' => 'attachment', 'mime' => 'message/rfc822', 'content' => $nested]], $mail->attachments);
        }
    }

    public function test_named_text_file_in_a_mixed_message_is_available_with_its_original_bytes(): void
    {
        $bytes = "Line one\r\nLine two\r\n";
        $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=parts\r\n\r\n"
            ."--parts\r\nContent-Type: text/plain\r\n\r\nSee notes.\r\n"
            ."--parts\r\nContent-Type: text/plain; name=\"notes.txt\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode($bytes)."\r\n--parts--\r\n";

        $mail = (new MailParserService)->parse($raw);
        self::assertStringContainsString('See notes.', $mail->bodyText);
        self::assertSame([['filename' => 'notes.txt', 'mime' => 'text/plain', 'content' => $bytes]], $mail->attachments);
    }

    public function test_html_layout_preserves_table_cells_block_boundaries_and_image_alt_text(): void
    {
        $html = 'Receipt<div>Order details</div>'
            .'<table><tr><th>Item</th><th>Amount</th></tr><tr><td>Delivery</td><td>12 EUR</td></tr></table>'
            .'<p><img alt="Code: 012345" src="cid:code"></p>';
        $mail = (new MailParserService)->parse("Content-Type: text/html; charset=UTF-8\r\n\r\n".$html);

        self::assertSame("Receipt\nOrder details\nItem Amount\nDelivery 12 EUR\nCode: 012345", $mail->bodyText);
    }

    public function test_related_saves_named_text_resources_and_excludes_the_named_root(): void
    {
        $bytes = "Line one\r\nLine two\r\n";
        $notes = "--parts\r\nContent-Type: text/plain; name=notes.txt\r\n"
            ."Content-ID: <notes>\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode($bytes)."\r\n";
        $html = "--parts\r\nContent-Type: text/html; name=index.html\r\nContent-ID: <main>\r\n\r\n<p>See notes.</p>\r\n";
        foreach (['; start="<main>"' => $notes.$html, '' => $html.$notes, '; start="<missing>"' => $html.$notes] as $start => $parts) {
            $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=parts".$start."\r\n\r\n".$parts."--parts--\r\n";
            $mail = (new MailParserService)->parse($raw);
            self::assertSame('See notes.', $mail->bodyText);
            self::assertSame([['filename' => 'notes.txt', 'mime' => 'text/plain', 'content' => $bytes]], $mail->attachments);
        }
    }

    public function test_mixed_preserves_inline_order_selects_one_alternative_and_excludes_the_attached_message(): void
    {
        $raw = <<<'MAIL'
From: sender@example.test
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary=outer

--outer
Content-Type: text/plain

Date: Monday
--outer
Content-Type: multipart/alternative; boundary=inner

--inner
Content-Type: text/plain

Location: room 3
--inner
Content-Type: text/html

<p>Duplicate location</p>
--inner--
--outer
Content-Type: text/html

<p>End.</p>
--outer
Content-Type: message/rfc822
Content-Disposition: attachment; filename="forwarded.eml"

From: colleague@example.test
Subject: Attachment
Content-Type: text/plain

Attached body
--outer--
MAIL;
        $mail = (new MailParserService)->parse(str_replace("\n", "\r\n", $raw));
        self::assertSame("Date: Monday\nLocation: room 3\nEnd.", $mail->bodyText);
        self::assertCount(1, $mail->attachments);
        self::assertSame('forwarded.eml', $mail->attachments[0]['filename']);
        self::assertStringContainsString('Attached body', $mail->attachments[0]['content']);
    }

    public function test_html_buttons_keep_safe_web_destinations_in_readable_text(): void
    {
        $url = 'https://example.test/confirm?a=1&b=2';
        $html = '<p><a href="https://example.test/confirm?a=1&amp;b=2">Confirm</a></p>'
            .'<p><a href="http://example.test/code">http://example.test/code</a></p>'
            .'<p><a href="mailto:person@example.test">Contact</a></p>';
        $mail = (new MailParserService)->parse("Content-Type: text/html; charset=UTF-8\r\n\r\n".$html);
        self::assertSame("Confirm ($url)\nhttp://example.test/code\nContact", $mail->bodyText);
    }

    public function test_related_root_with_a_content_id_is_used_when_the_plain_alternative_is_empty(): void
    {
        $raw = <<<'MAIL'
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary=outer

--outer
Content-Type: text/plain


--outer
Content-Type: multipart/related; boundary=resources; start="<main@example.test>"

--resources
Content-Type: text/plain
Content-ID: <asset@example.test>

Supporting asset
--resources
Content-Type: text/html
Content-ID: <main@example.test>

<p>Main document</p>
--resources--
--outer--
MAIL;
        $mail = (new MailParserService)->parse(str_replace("\n", "\r\n", $raw));
        self::assertSame('Main document', $mail->bodyText);
    }
}
