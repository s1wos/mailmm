<?php

namespace Tests\Unit;

use App\Services\MailHtmlPreview;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

class MailHtmlPreviewTest extends TestCase
{
    private const GIF = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function test_preserves_email_layout_styles_and_link_labels(): void
    {
        $html = <<<'HTML'
        <style>@media (max-width: 600px) { .receipt { width: 100% } }</style>
        <body bgcolor="#f0f0f0" dir="rtl">
        <table class="receipt" cellpadding="6" role="presentation"><tr>
        <td style="color: navy"><h2>Счёт</h2><a href="https://example.test/order?a=1&amp;b=2" target="_self">Открыть заказ</a></td>
        </tr></table></body>
        HTML;
        $preview = $this->document($html);

        self::assertStringContainsString('.receipt { width: 100% }', $preview->querySelector('style')->textContent);
        self::assertStringContainsString('#f0f0f0', $preview->querySelector('[dir="rtl"]')->getAttribute('style'));
        self::assertSame('6', $preview->querySelector('.receipt')->getAttribute('cellpadding'));
        self::assertSame('color: navy', $preview->querySelector('td')->getAttribute('style'));
        self::assertSame('Счёт', $preview->querySelector('td h2')->textContent);
        $link = $preview->querySelector('td a');
        self::assertSame('Открыть заказ', $link->textContent);
        self::assertSame('https://example.test/order?a=1&b=2', $link->getAttribute('href'));
        self::assertSame('_blank', $link->getAttribute('target'));
        self::assertSame('noopener noreferrer', $link->getAttribute('rel'));
    }

    public function test_only_copies_presentation_markup_and_explicit_web_destinations(): void
    {
        $html = '<title>Newsletter</title><link rel="stylesheet" href="https://example.test/theme.css">'
            .'<form><label>Delivery</label><input name="postcode"></form>'
            .'<svg><title>Logo</title></svg><iframe title="map"></iframe><script></script>'
            .'<a href="/relative" ping="https://example.test/log">Details</a>'
            .'<a href="https://">Incomplete</a><a href="tel:unknown">Telephone</a>'
            .'<a href="http://example.test/terms" referrerpolicy="unsafe-url">Terms</a>';
        $preview = $this->document($html);

        self::assertNull($preview->querySelector('link, form, input, svg, iframe, script, [ping], [referrerpolicy]'));
        self::assertStringContainsString('Delivery', $preview->body->textContent);
        self::assertSame(1, $preview->querySelectorAll('a[href]')->length);
        self::assertSame('http://example.test/terms', $preview->querySelector('a[href]')->getAttribute('href'));
        self::assertStringContainsString('Telephone', $preview->body->textContent);
    }

    public function test_remote_image_sources_use_local_proxy_urls_and_inline_images_keep_their_bytes(): void
    {
        $url = 'https://example.test/logo.png';
        $html = '<img alt="Remote" src="//example.test/logo.png" srcset="'.$url.' 2x">'
            .'<img alt="Logo" src="cid:logo%40example.test"><img alt="Direct" src="'.self::GIF.'">'
            .'<img alt="Missing" src="cid:missing"><img alt="Wrong type" src="cid:mismatch">'
            .'<img alt="Invalid" src="data:image/png;base64,aGVsbG8=">'
            .'<img alt="Untrusted" data-remote-src="'.$url.'"><img alt="Relative" src="/mail-images/other/message">';
        $preview = $this->document($html, [
            'logo@example.test' => self::GIF,
            'mismatch' => str_replace('image/gif', 'image/png', self::GIF),
        ], fn (string $image) => $image === $url ? '/mail-images/1/logo' : null);

        self::assertNull($preview->querySelector('img[data-remote-src]'));
        self::assertSame('/mail-images/1/logo', $preview->querySelector('[alt="Remote"]')->getAttribute('src'));
        self::assertNull($preview->querySelector('[srcset]'));
        self::assertSame(3, $preview->querySelectorAll('img[src]')->length);
        self::assertSame(self::GIF, $preview->querySelector('[alt="Logo"]')->getAttribute('src'));
        self::assertSame(self::GIF, $preview->querySelector('[alt="Direct"]')->getAttribute('src'));
        self::assertNotNull($preview->querySelector('[alt="Missing"]'));
    }

    public function test_empty_messages_allow_the_reader_to_fall_back_to_plain_text(): void
    {
        self::assertNull(MailHtmlPreview::render(''));
        self::assertNull(MailHtmlPreview::render('<style>body { color: navy }</style><p> </p>'));
        self::assertNull(MailHtmlPreview::render('<p>&nbsp;</p>'));
        $newsletter = '<img src="https://example.test/newsletter.jpg">';
        self::assertNull(MailHtmlPreview::render($newsletter));
        self::assertNotNull($this->document($newsletter, [], fn () => '/mail-images/1/page')->querySelector('img[src="/mail-images/1/page"]'));
    }

    public function test_responsive_images_keep_src_precedence_and_select_one_valid_candidate(): void
    {
        $html = '<img src="https://cdn.test/plain.jpg" srcset="https://unused.test/large.jpg 2x">'
            .'<img src="data:image/svg+xml,invalid" srcset="/local 400w, https://cdn.test/rejected 600w, //cdn.test/photo.jpg?size=800,600 800w, https://unused.test/large.jpg 1000w" alt="Portrait">'
            .'<img srcset="'.self::GIF.' 1x, http://unused.test/icon.gif 2x">';
        $proxy = fn (string $url) => match ($url) {
            'https://cdn.test/plain.jpg' => '/mail-images/1/plain',
            'https://cdn.test/rejected' => null,
            'https://cdn.test/photo.jpg?size=800,600' => '/mail-images/1/photo',
        };
        $preview = $this->document($html, [], $proxy);
        $images = $preview->querySelectorAll('img');
        self::assertSame('/mail-images/1/plain', $images->item(0)->getAttribute('src'));
        self::assertSame('/mail-images/1/photo', $images->item(1)->getAttribute('src'));
        self::assertSame('Portrait', $images->item(1)->getAttribute('alt'));
        self::assertSame(self::GIF, $images->item(2)->getAttribute('src'));
        self::assertNull($preview->querySelector('[srcset]'));
    }

    public function test_picture_uses_a_supported_source_when_its_image_has_no_usable_candidate(): void
    {
        $html = '<picture><source type="image/svg+xml" srcset="https://unused.test/photo.svg">'
            .'<source media="print" srcset="https://unused.test/print.jpg">'
            .'<source type="image/webp" srcset="cid:absent, http://cdn.test/photo.webp 2x">'
            .'<source type="image/png" srcset="https://unused.test/photo.png">'
            .'<img alt="Newsletter" srcset="data:image/svg+xml,invalid 1x"></picture>'
            .'<img alt="Unrelated">';
        $preview = $this->document($html, [], fn (string $url) => match ($url) {
            'http://cdn.test/photo.webp' => '/mail-images/1/photo',
        });
        self::assertSame('/mail-images/1/photo', $preview->querySelector('[alt="Newsletter"]')->getAttribute('src'));
        self::assertNull($preview->querySelector('[alt="Unrelated"]')->getAttribute('src'));
        self::assertNull($preview->querySelector('source, picture, [srcset]'));
    }

    public function test_legacy_banners_preserve_the_image_and_inline_style_precedence(): void
    {
        $html = '<body background="cid:logo"><table><tr><td width="480" height="320" '
            .'background="https://example.test/&quot;banner&quot;.jpg" style="background-size: cover"></td></tr></table>';
        $preview = $this->document($html, ['logo' => self::GIF], fn (string $url) => $url === 'https://example.test/"banner".jpg' ? '/mail-images/1/banner' : null);

        self::assertNull($preview->querySelector('[background], img[src]'));
        self::assertStringContainsString('url("'.self::GIF.'")', $preview->querySelector('body div')->getAttribute('style'));
        self::assertSame('background-image:url("/mail-images/1/banner");background-size: cover', $preview->querySelector('td')->getAttribute('style'));
        self::assertNull(MailHtmlPreview::render('<table background="cid:absent"></table>'));
    }

    public function test_image_only_css_banners_keep_the_formatted_reader(): void
    {
        $css = '<style>.banner { background: url("https://example.test/banner.png") }</style>';
        self::assertNull(MailHtmlPreview::render($css));
        $proxy = fn () => '/mail-images/1/banner';
        self::assertNull(MailHtmlPreview::render($css.'<div class="banner"></div>'));
        self::assertNotNull(MailHtmlPreview::render($css.'<div class="banner"></div>', [], $budget, $proxy));
        self::assertNotNull(MailHtmlPreview::render($css.'<body class="banner"></body>', [], $budget, $proxy));
        self::assertNotNull(MailHtmlPreview::render('<body style="background: image-set(url(https://example.test/bg.png) 1x)">', [], $budget, $proxy));
    }

    public function test_css_rewrites_image_tokens_in_context_and_preserves_layout_and_literal_text(): void
    {
        $html = <<<'HTML'
        <style>
        /* url(https://unused.test/comment.png) */
        @media screen { .banner { background: linear-gradient(red, blue), image-set("//cdn.test/banner(1).png" type("image/png") 1x, url("https://cdn.test/banner2.png" /* retina */) 2x) } }
        .label::after { content: "url(https://unused.test/label.png)" }
        </style>
        <div class="banner" style="background-image: -webkit-image-set('http://cdn.test/tile.png' 1x, url(cid:logo) 2x); color: navy">Banner</div>
        <a href="https://cdn.test/banner2.png">Visit site</a>
        HTML;
        $proxy = fn (string $url) => match ($url) {
            'https://cdn.test/banner(1).png' => '/mail-images/1/standard',
            'https://cdn.test/banner2.png' => '/mail-images/1/retina',
            'http://cdn.test/tile.png' => '/mail-images/1/tile',
        };
        $preview = $this->document($html, ['logo' => self::GIF], $proxy);
        $css = $preview->querySelector('style')->textContent;
        self::assertStringContainsString('/mail-images/1/standard', $css);
        self::assertStringContainsString('/mail-images/1/retina', $css);
        self::assertStringNotContainsString('cdn.test', $css);
        self::assertStringContainsString('type("image/png") 1x', $css);
        self::assertStringContainsString('linear-gradient(red, blue)', $css);
        self::assertStringContainsString('content: "url(https://unused.test/label.png)"', $css);
        $inline = $preview->querySelector('.banner')->getAttribute('style');
        self::assertStringContainsString('/mail-images/1/tile', $inline);
        self::assertStringContainsString(self::GIF, $inline);
        self::assertStringContainsString('color: navy', $inline);
        self::assertSame('https://cdn.test/banner2.png', $preview->querySelector('a')->getAttribute('href'));
    }

    public function test_rejected_css_image_urls_cannot_resolve_relative_to_the_reader(): void
    {
        $html = '<style>.logo{ background: image-set("/mail-images/1/other" 1x, url(https://cdn.test/rejected.png) 2x) }</style>'
            .'<div class="logo" style="width: 10px; background: url(../account)">Logo</div>';
        $preview = $this->document($html, [], fn () => null);
        self::assertStringNotContainsString('/mail-images/', $preview->querySelector('style')->textContent);
        self::assertStringNotContainsString('cdn.test', $preview->querySelector('style')->textContent);
        self::assertStringNotContainsString('../account', $preview->querySelector('.logo')->getAttribute('style'));
    }

    public function test_css_escapes_do_not_change_the_image_destination_or_function_boundaries(): void
    {
        $html = <<<'HTML'
        <style>.a { background: \75 rl(https://cdn.test/b\(1\).gif) }
        .b { background: image-set("//cdn.test/\62 .gif" 1x) }</style><div>Images</div>
        HTML;
        $proxy = fn (string $url) => match ($url) {
            'https://cdn.test/b(1).gif' => '/mail-images/1/a',
            'https://cdn.test/b.gif' => '/mail-images/1/b',
        };
        $css = $this->document($html, [], $proxy)->querySelector('style')->textContent;
        self::assertStringContainsString('/mail-images/1/a', $css);
        self::assertStringContainsString('/mail-images/1/b', $css);
        self::assertStringNotContainsString('cdn.test', $css);
    }

    public function test_proxy_urls_share_the_expanded_byte_budget_with_inline_images(): void
    {
        $budget = strlen('/mail-images/1/first');
        $html = '<img alt="First" src="https://example.test/banner"><div style="background:url(https://example.test/footer)">Footer</div>';
        $preview = MailHtmlPreview::render($html, [], $budget, fn () => '/mail-images/1/first');
        self::assertSame(1, substr_count($preview, '/mail-images/'));
        self::assertSame(0, $budget);
    }

    public function test_preserves_internationalized_web_links(): void
    {
        $url = 'https://пример.рф/счёт';
        $preview = $this->document('<a href="'.$url.'">Счёт</a>');

        self::assertSame($url, $preview->querySelector('a')->getAttribute('href'));
    }

    public function test_contact_links_can_open_contacts_and_do_not_become_image_sources(): void
    {
        $mail = 'mailto:office@example.test?subject=Hello%20there';
        $phone = 'tel:+7-123-456-7890;ext=12';
        $preview = $this->document('<a href="'.$mail.'">Email</a><a href="'.$phone.'">Call</a>'
            .'<a href="mailto:missing-domain">Unavailable</a><img alt="Contact" src="'.$mail.'">');

        self::assertSame(2, $preview->querySelectorAll('a[href]')->length);
        self::assertSame($mail, $preview->querySelectorAll('a')->item(0)->getAttribute('href'));
        self::assertSame($phone, $preview->querySelectorAll('a')->item(1)->getAttribute('href'));
        self::assertNull($preview->querySelector('img[src], img[data-remote-src]'));
    }

    public function test_repeated_mime_images_share_the_budget_across_html_sections(): void
    {
        $budget = strlen(self::GIF);
        $html = '<img alt="Logo" src="cid:logo">';
        $images = ['logo' => self::GIF];
        $first = MailHtmlPreview::render($html, $images, $budget);
        $second = MailHtmlPreview::render($html, $images, $budget);

        self::assertStringContainsString(self::GIF, $first);
        self::assertStringNotContainsString(self::GIF, $second);
        self::assertSame(0, $budget);
    }

    private function document(string $html, array $images = [], ?callable $proxy = null): HTMLDocument
    {
        return HTMLDocument::createFromString('<!doctype html>'.MailHtmlPreview::render($html, $images, $budget, $proxy), overrideEncoding: 'UTF-8');
    }
}
