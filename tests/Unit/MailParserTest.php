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

    public function test_html_preview_selects_related_roots_and_keeps_content_ids_local_to_each_section(): void
    {
        $image = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
        $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=outer\r\n\r\n"
            ."--outer\r\nContent-Type: text/plain\r\n\r\nFirst <note>\r\n"
            ."--outer\r\nContent-Type: multipart/related; boundary=one; start=\"<page>\"\r\n\r\n"
            ."--one\r\nContent-Type: image/gif; name=logo.gif\r\nContent-ID: <logo>\r\nContent-Transfer-Encoding: base64\r\n\r\n$image\r\n"
            ."--one\r\nContent-Type: text/html; name=index.html\r\nContent-ID: <page>\r\n\r\n<p>Page one<img src=\"cid:logo\"></p>\r\n--one--\r\n"
            ."--outer\r\nContent-Type: multipart/related; boundary=two\r\n\r\n"
            ."--two\r\nContent-Type: text/html\r\n\r\n<p>Page two<img src=\"cid:logo\" alt=\"missing\"></p>\r\n--two--\r\n"
            ."--outer\r\nContent-Type: text/html; name=file.html\r\n\r\n<p>Named file contents</p>\r\n"
            ."--outer\r\nContent-Type: multipart/digest; boundary=digest\r\n\r\n--digest\r\nContent-Type: text/html\r\n\r\nDigest contents\r\n--digest--\r\n"
            ."--outer\r\nContent-Type: text/html\r\nContent-Disposition: attachment\r\n\r\nAttached HTML\r\n"
            ."--outer\r\nContent-Type: text/plain\r\n\r\nLast remark\r\n--outer--\r\n";

        $preview = (new MailParserService)->htmlPreview($raw);
        self::assertIsString($preview);
        self::assertMatchesRegularExpression('~First &lt;note&gt;.*Page one.*Page two.*Last remark~s', $preview);
        self::assertSame(1, substr_count($preview, 'data:image/gif;base64,'.$image));
        self::assertStringNotContainsString('Named file contents', $preview);
        self::assertStringNotContainsString('Attached HTML', $preview);
        self::assertStringNotContainsString('Digest contents', $preview);
    }

    public function test_plain_only_message_has_no_html_preview(): void
    {
        self::assertNull((new MailParserService)->htmlPreview("Content-Type: text/plain\r\n\r\n<a>text</a>"));
    }

    public function test_nested_remote_image_newsletter_uses_the_message_proxy(): void
    {
        $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=a\r\n\r\n"
            ."--a\r\nContent-Type: text/plain\r\n\r\nNewsletter\r\n"
            ."--a\r\nContent-Type: multipart/related; boundary=b\r\n\r\n"
            ."--b\r\nContent-Type: text/html\r\n\r\n<img src=\"//cdn.example.test/news.png\">\r\n--b--\r\n--a--\r\n";
        $parser = new MailParserService;

        self::assertNull($parser->htmlPreview($raw));
        $preview = $parser->htmlPreview($raw, fn () => '/mail-images/9/news');
        self::assertStringContainsString('src="/mail-images/9/news"', $preview ?? '');
    }

    public function test_standalone_raster_is_visible_unless_invalid_or_marked_as_an_attachment(): void
    {
        $gif = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
        $headers = "MIME-Version: 1.0\r\nContent-Type: image/gif; name=receipt.gif\r\nContent-Transfer-Encoding: base64\r\n";
        $parser = new MailParserService;
        $preview = $parser->htmlPreview($headers."\r\n".$gif);
        self::assertIsString($preview);
        self::assertStringContainsString('src="data:image/gif;base64,'.$gif.'"', $preview);
        self::assertNull($parser->htmlPreview($headers."\r\n".base64_encode('broken image')));
        self::assertNull($parser->htmlPreview($headers."Content-Disposition: attachment; filename=receipt.gif\r\n\r\n".$gif));
    }

    public function test_mixed_mail_displays_its_inline_picture_between_text_sections_and_keeps_attached_images_separate(): void
    {
        $gif = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
        $imagePart = "Content-Type: image/gif; name=picture.gif\r\nContent-Transfer-Encoding: base64\r\n";
        $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n"
            ."--b\r\nContent-Type: text/plain\r\n\r\nYour receipt\r\n"
            ."--b\r\n".$imagePart."Content-Disposition: inline; filename=picture.gif\r\n\r\n$gif\r\n"
            ."--b\r\nContent-Type: text/plain\r\n\r\nThank you\r\n"
            ."--b\r\n".$imagePart."Content-Disposition: attachment; filename=receipt-copy.gif\r\n\r\n$gif\r\n--b--\r\n";
        $parser = new MailParserService;
        $preview = $parser->htmlPreview($raw);
        self::assertIsString($preview);
        self::assertMatchesRegularExpression('~Your receipt.*<img.*Thank you~s', $preview);
        self::assertSame(1, substr_count($preview, 'data:image/gif;base64,'.$gif));
        self::assertContains('receipt-copy.gif', array_column($parser->parse($raw)->attachments, 'filename'));
    }

    public function test_signed_image_only_body_keeps_its_link_and_unnamed_inline_image(): void
    {
        $image = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
        $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/signed; boundary=signed; protocol=\"application/pgp-signature\"\r\n\r\n"
            ."--signed\r\nContent-Type: multipart/related; boundary=body\r\n\r\n"
            ."--body\r\nContent-Type: text/html\r\n\r\n<a href=\"https://example.test/open\"><img src=\"cid:logo\"></a>\r\n"
            ."--body\r\nContent-Type: image/gif\r\nContent-ID: <logo>\r\nContent-Transfer-Encoding: base64\r\n\r\n$image\r\n--body--\r\n"
            ."--signed\r\nContent-Type: application/pgp-signature\r\n\r\nDetached signature\r\n--signed--\r\n";
        $preview = (new MailParserService)->htmlPreview($raw);
        self::assertIsString($preview);
        self::assertStringContainsString('href="https://example.test/open"', $preview);
        self::assertStringContainsString('data:image/gif;base64,'.$image, $preview);
        self::assertStringNotContainsString('Detached signature', $preview);
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
