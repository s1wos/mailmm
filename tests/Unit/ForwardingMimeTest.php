<?php

namespace Tests\Unit;

use App\Exceptions\ForwardingFailure;
use App\Rules\ForwardingAddress;
use App\Services\ForwardingMarker;
use App\Services\ForwardingMime;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZBateson\MailMimeParser\MailMimeParser;

class ForwardingMimeTest extends TestCase
{
    private const ID = '61a842cc-7383-4b98-bd31-02e6f007e678';

    private function original(): string
    {
        return "Received: by mx.test\r\nFrom: Shop <shop@example.org>\r\nTo: old@example.org\r\nCc: accounts@example.org\r\n"
            ."Reply-To: refunds@example.org\r\nSubject: Receipt\r\nMessage-ID: <shop@example.org>\r\nDKIM-Signature: folded\r\n\tvalue\r\n"
            ."Content-Type: multipart/mixed;\r\n boundary=abc\r\n\r\n--abc\r\nContent-Type: text/html\r\n\r\n"
            ."<b>Receipt</b>\r\n--abc\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=x.pdf\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\nAQIDBA==\r\n--abc--\r\n";
    }

    public function test_auto_preserves_protected_fields_and_body_and_signs_only_its_own_copy(): void
    {
        $raw = $this->original();
        $out = ForwardingMime::automatic($raw, self::ID, 'mx.test; dkim=pass')->toString();
        $this->assertStringEndsWith($raw, $out);
        $this->assertStringContainsString("Authentication-Results: mx.test; dkim=pass\r\n", $out);
        $this->assertSame('auto', ForwardingMarker::type($out));
        $this->assertNull(ForwardingMarker::type(str_replace('Receipt', 'Other', $out)));
        $this->assertNull(ForwardingMarker::type("From: x@y.test\r\n\r\n".$out));
    }

    public function test_manual_forward_keeps_the_author_reply_address_and_original_mime(): void
    {
        config(['forwarding.service_address' => 'letters@mailmm.test']);
        $raw = $this->original();
        $out = ForwardingMime::manual($raw, self::ID, 'mx.test; dkim=pass')->toString();
        $this->assertSame('manual', ForwardingMarker::type($out));
        $this->assertStringEndsWith(substr($raw, strpos($raw, 'Content-Type:')), $out);
        $mail = (new MailMimeParser)->parse($out, false);
        $this->assertSame('shop@example.org', $mail->getHeader('From')->getEmail());
        $this->assertSame('Shop', $mail->getHeader('From')->getPersonName());
        $this->assertSame('old@example.org', $mail->getHeader('To')->getEmail());
        $this->assertSame('accounts@example.org', $mail->getHeader('Cc')->getEmail());
        $this->assertSame('refunds@example.org', $mail->getHeader('Reply-To')->getEmail());
        $this->assertSame('Receipt', $mail->getSubject());
        $this->assertSame('<b>Receipt</b>', $mail->getHtmlContent());
        $this->assertSame("\x01\x02\x03\x04", (string) $mail->getAttachmentPart(0)->getBinaryContentStream());
    }

    public function test_verification_remains_a_message_from_the_service(): void
    {
        config(['forwarding.service_address' => 'letters@mailmm.test']);
        $wire = ForwardingMime::verification('reader@example.net', '123456')->toString();
        $message = (new MailMimeParser)->parse($wire, false);
        $this->assertSame('verification', ForwardingMarker::type($wire));
        $this->assertSame('letters@mailmm.test', $message->getHeader('From')->getEmail());
        $this->assertSame('MailMM', $message->getHeader('From')->getPersonName());
        $this->assertSame('reader@example.net', $message->getHeader('To')->getEmail());
        $this->assertStringContainsString('123456', $message->getTextContent());
    }

    public function test_manual_forward_does_not_disclose_blind_recipients_from_headers_or_trace(): void
    {
        $private = "Bcc: Blind <blind@private.test>\r\nResent-Bcc:\r\n another@private.test\r\n"
            ."Return-Path: <trace@private.test>\r\nX-Original-To: archive@private.test\r\n"
            ."Delivered-To: archive@private.test\r\nReceived: by mx.test\r\n for <blind@private.test>;\r\n";
        $out = ForwardingMime::manual($private.$this->original(), self::ID, 'mx.test; none')->toString();
        $this->assertStringNotContainsString('private.test', $out);
        $this->assertSame('shop@example.org', (new MailMimeParser)->parse($out, false)->getHeader('From')->getEmail());
    }

    public static function privateDkimCopies(): array
    {
        return [
            'blind copy' => ['Bcc:secret@private.test'],
            'folded trace' => ["Deliv\r\n ered-To:secret@private.test"],
            'private extension' => ['X-Original-To:secret@private.test'],
            'earlier recipient' => ['To:secret@private.test'],
        ];
    }

    #[DataProvider('privateDkimCopies')]
    public function test_manual_drops_only_the_dkim_signature_that_copies_private_metadata(string $copy): void
    {
        $safe = "DKIM-Signature: v=1; d=example.org; s=public;\r\n z=From:Shop=20<shop@example.org>|Subject:Receipt; b=abc";
        $unsafe = "DKIM-Signature: v=1; d=example.org; s=private;\r\n z=Subject:Receipt|".$copy."; b=def";
        $raw = $safe."\r\n".$unsafe."\r\n".$this->original();
        $out = ForwardingMime::manual($raw, self::ID, 'mx.test; none')->toString();
        $this->assertStringNotContainsString('secret@private.test', $out);
        $this->assertStringNotContainsString('s=private', $out);
        $this->assertStringContainsString($safe."\r\n", $out);
        $this->assertStringContainsString("DKIM-Signature: folded\r\n\tvalue\r\n", $out);
    }

    public static function arcHeaderCopies(): array
    {
        return ['public copy' => ['Subject:Receipt', true], 'private copy' => ['Bcc:blind@private.test', false]];
    }

    #[DataProvider('arcHeaderCopies')]
    public function test_manual_keeps_a_public_arc_chain_and_removes_the_entire_chain_with_a_private_copy(string $copy, bool $keep): void
    {
        $chain = "ARC-Seal: i=2; cv=pass; b=two\r\nARC-Message-Signature: i=2; b=two\r\n"
            ."ARC-Authentication-Results: i=2; second.test; arc=pass\r\n"
            ."ARC-Seal: i=1; cv=none; b=one\r\nARC-Message-Signature: i=1; z=".$copy."; b=one\r\n"
            ."ARC-Authentication-Results: i=1; first.test; dkim=pass\r\n";
        $wire = ForwardingMime::manual($chain.$this->original(), self::ID, 'mx.test; none')->toString();
        if ($keep) {
            $this->assertStringContainsString($chain, $wire);
        } else {
            $this->assertDoesNotMatchRegularExpression('/^ARC-/im', $wire);
            $this->assertStringNotContainsString('blind@private.test', $wire);
        }
        $this->assertStringContainsString("DKIM-Signature: folded\r\n\tvalue\r\n", $wire);
    }

    public function test_invalid_originals_are_refused_before_smtp(): void
    {
        foreach (['Bcc: hidden@private.test', 'Resent-Bcc: hidden@private.test', 'From: another@example.org'] as $bad) {
            try {
                ForwardingMime::automatic($bad."\r\n".$this->original(), self::ID, 'mx.test; none');
                $this->fail('Expected refusal');
            } catch (ForwardingFailure $failure) {
                $this->assertContains($failure->getMessage(), ['hidden_recipient', 'invalid_from']);
            }
        }
    }

    public static function mailboxes(): array
    {
        return [
            ['"bill@home"@EXAMPLE.org', '"bill@home"@example.org'], ['"one two"@EXAMPLE.org', '"one two"@example.org'],
            ['оплата@пример.рф', 'оплата@xn--e1afmkfd.xn--p1ai'], ['abc+tag@example.org', 'abc+tag@example.org'],
            ["one@example.org\r\nBcc: x@y.test", null], ['Name <one@example.org>', null],
            ['one@example.org,two@example.org', null], ['one(comment)@example.org', null], ['one..two@example.org', null],
            ['one@[127.0.0.1]', null], ['a@', null], [str_repeat('a', 65).'@example.org', null],
        ];
    }

    #[DataProvider('mailboxes')]
    public function test_mailbox_normalization_accepts_one_routable_mailbox(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, ForwardingAddress::normalize($input));
    }

    public function test_only_local_authentication_results_are_folded_to_fit_smtp(): void
    {
        $value = 'mx.test; '.str_repeat('dkim=pass header.d=example.org; ', 55).'spf=pass';
        $raw = ForwardingMime::automatic($this->original(), self::ID, $value)->toString();
        $unfolded = preg_replace('/\r\n[ \t]+/', ' ', $raw);
        $this->assertStringContainsString('Authentication-Results: '.$value."\r\n", $unfolded);
        foreach (explode("\r\n", $raw) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line));
        }
    }

    public static function forwardingModes(): array
    {
        return ['automatic' => ['auto'], 'manual' => ['manual']];
    }

    #[DataProvider('forwardingModes')]
    public function test_original_dkim_signature_verifies_after_forwarding(string $mode): void
    {
        // A fixed test DNS public key and an independently generated simple/simple signature.
        $public = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAp08SfmZX4gSsCChWO0Hy
QfZ6WgcMYJ6C6NkQ69kYmVTAwcbUZwxQ+aGeCJYKmIpO1e9CUX5LNjcBKKAUJUwd
NYvVU1Sq9htEBnlDA5AFNTM5IK/HloltbiH/l8tTTR4i/ROgxCHgPD0W6HCLlWdY
Z0/gABWg8YpLPNAaakRcIoVr2Fn7bG42TqneCJuKiBnHd50P3IE8maBy1/o2dbS8
O6tcDDPSIaXEenGB8FXMWRgscqCWc7qV42Ol0WpVomgepzQo4Is6oLR2GNR2ja1h
zAVNCBgRwzZc5vmiCCt5d9dYwYFtnEeO5784KEBnb4Pv/UqEZ5+jx3cmmTzyxmCT
pwIDAQAB
-----END PUBLIC KEY-----
PEM;
        $signature = 'a2k2x7VAvtT23y/tajyYul7VRid91joWmuIY3+nfudUWC9g0iIZrxgPn32WmhAW2RWUhnzSW3mY1nn4x6Xr9Ysy1a1hPZl36NUasGrTS3Ae5GTP7qT7q6vxrcHqVXbrIXnqhapS8JvuzOGE8iX+uxgukf8zj6dSICu83GCs4sC7lTJtEwSiN6z/8fuHQ8m+Z0PY3wuoIKMbeXb83Kr0pSEe3y/8xp7dj2/Xivd2gSC6Z5fSv4c25sW/Lap63XoXAkEXOQRbQLtn1hI6VUUqBtVCQbwWZLJqhasDHFhtI1ejuTYSSIOPyYrG29M2g/LiEPFwQAmpZlS5zrvjSCTUG0A==';
        $raw = "From: Receipt Desk <shop@sender.test>\r\nTo: hidden-copy@mailmm.test\r\nSubject: Receipt #1001\r\nMessage-ID: <1001@sender.test>\r\n"
            ."DKIM-Signature: v=1; a=rsa-sha256; c=simple/simple; d=sender.test; s=mail;\r\n"
            ." h=from:to:subject:message-id; bh=7IKYA2T+5/TPU8nZPIKQgkjNCeZJoaddkwvTfOp9ob0=;\r\n b=".$signature."\r\n\r\n<p>Paid</p>\r\n";
        config(['forwarding.service_address' => 'letters@mailmm.test']);
        $out = $mode === 'auto' ? ForwardingMime::automatic($raw, self::ID, 'mx.test; dkim=pass')
            : ForwardingMime::manual($raw, self::ID, 'mx.test; dkim=pass');
        [$head, $body] = explode("\r\n\r\n", $out->toString(), 2);
        $signed = '';
        $dkim = '';
        foreach (preg_split('/\r\n(?![ \t])/', $head) as $field) {
            if (preg_match('/^(?:From|To|Subject|Message-ID):/', $field)) {
                $signed .= $field."\r\n";
            }
            if (str_starts_with($field, 'DKIM-Signature:')) {
                $dkim = $field;
            }
        }
        $this->assertSame(1, preg_match('/\bbh=([^;]+);.*\bb=(.+)$/s', $dkim, $values));
        $this->assertSame($values[1], base64_encode(hash('sha256', $body, true)));
        $this->assertSame(1, openssl_verify($signed.substr($dkim, 0, -strlen($values[2])), base64_decode($values[2]), $public, OPENSSL_ALGO_SHA256));
    }

    public static function opaqueBodies(): array
    {
        return [
            ['text/html; charset=utf-8', "<img src=\"cid:receipt\">\r\n"],
            ['text/plain', "just the receipt\r\n"],
            ['application/pkcs7-mime; smime-type=enveloped-data', "MIIByA==\r\n"],
            ['multipart/signed; boundary=sgn; protocol="application/pgp-signature"',
                "--sgn\r\nContent-Type: text/plain\r\n\r\na signed line  \r\n--sgn\r\nContent-Type: application/pgp-signature\r\n\r\nopaque\r\n--sgn--\r\n"],
            ['multipart/related; boundary=rel', "--rel\r\nContent-Type: multipart/alternative; boundary=alt\r\n\r\n"
                ."--alt\r\nContent-Type: text/plain\r\n\r\nreceipt\r\n--alt\r\nContent-Type: text/html\r\n\r\n<img src=\"cid:img\">\r\n--alt--\r\n"
                ."--rel\r\nContent-Type: image/png\r\nContent-ID: <img>\r\nContent-Transfer-Encoding: base64\r\n\r\nAQID\r\n--rel--\r\n"],
            ['message/rfc822', "From: вложение <nested@example.org>\r\nTo: inner@example.org\r\n\r\nnested body\r\n"],
        ];
    }

    #[DataProvider('opaqueBodies')]
    public function test_each_mode_preserves_opaque_bodies_byte_for_byte(string $type, string $body): void
    {
        config(['forwarding.service_address' => 'letters@mailmm.test']);
        $raw = "From: Shop <shop@example.org>\r\nMIME-Version: 1.0\r\nContent-Type: ".$type."\r\n\r\n".$body;
        $manual = ForwardingMime::manual($raw, self::ID, 'mx.test; none')->toString();
        $auto = ForwardingMime::automatic($raw, self::ID, 'mx.test; none')->toString();
        foreach ([$manual, $auto] as $wire) {
            $this->assertSame($body, explode("\r\n\r\n", $wire, 2)[1]);
            $this->assertStringContainsString('Content-Type: '.$type."\r\n", $wire);
        }
    }
}
