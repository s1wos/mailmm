<?php

namespace App\Services;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use League\Uri\Http;

final class MailHtmlPreview
{
    private const ELEMENTS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br',
        'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'div', 'dl', 'dt',
        'em', 'figcaption', 'figure', 'font', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hr', 'i', 'img', 'ins', 'kbd', 'label', 'li', 'main', 'mark', 'ol', 'p', 'pre', 'q', 'rp',
        'rt', 'ruby', 's', 'samp', 'section', 'small', 'span', 'strike', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'tt', 'u', 'ul', 'var', 'wbr',
    ];

    private const DROP_SUBTREE = [
        'audio', 'canvas', 'embed', 'frame', 'frameset', 'iframe', 'noembed', 'noframes', 'noscript',
        'object', 'script', 'select', 'template', 'textarea', 'title', 'video', 'xmp',
    ];

    private const ATTRIBUTES = [
        'abbr', 'align', 'alt', 'aria-hidden', 'aria-label', 'bgcolor', 'border', 'cellpadding',
        'cellspacing', 'class', 'color', 'colspan', 'dir', 'face', 'headers', 'height', 'hspace',
        'id', 'lang', 'nowrap', 'reversed', 'role', 'rowspan', 'scope', 'size', 'span', 'start',
        'style', 'title', 'type', 'valign', 'vspace', 'width',
    ];

    // Shared by repeated inline images and signed URLs across all HTML sections of the message.
    private const IMAGE_BUDGET = 16 * 1024 * 1024;

    /**
     * Sender CSS is retained for a script-disabled, sandboxed srcdoc with a restrictive CSP.
     *
     * @param  array<string, string>  $inlineImages  Data URLs keyed by MIME Content-ID.
     * @param  (callable(string): ?string)|null  $proxy  Resolves an absolute web URL to a local image URL.
     */
    public static function render(string $html, array $inlineImages = [], ?int &$remaining = null, ?callable $proxy = null): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $source = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $output = HTMLDocument::createEmpty();
        $fragment = $output->createElement('div');
        $remaining ??= self::IMAGE_BUDGET;
        $validatedImages = [];
        $imageUrl = static function (string $src) use ($inlineImages, &$validatedImages, &$remaining, $proxy): ?string {
            return self::imageUrl($src, $inlineImages, $validatedImages, $remaining, $proxy);
        };
        $hasContent = false;
        $stylesheetImage = false;
        $hasBodyElement = false;
        $pending = [[$source->documentElement, $fragment]];
        while ($pending !== []) {
            [$node, $parent] = array_pop($pending);
            if ($node->nextSibling !== null) {
                $pending[] = [$node->nextSibling, $parent];
            }
            if ($node instanceof Text) {
                $parent->append($output->createTextNode($node->data));
                $hasContent = $hasContent || preg_match('/[^\s\p{Cf}]/u', $node->data) === 1;

                continue;
            }
            if (! $node instanceof Element || $node->namespaceURI !== 'http://www.w3.org/1999/xhtml'
                || in_array($node->localName, self::DROP_SUBTREE, true)) {
                continue;
            }
            $tag = $node->localName;
            if ($tag === 'style') {
                // Style is serialized as raw text. Only copy CSS that can stay inside this element.
                $css = $node->textContent;
                if (stripos($css, '</style') === false && in_array(strtolower(trim($node->getAttribute('type') ?? '')), ['', 'text/css'], true)) {
                    $style = $output->createElement('style');
                    $style->textContent = MailCssImages::rewrite($css, $imageUrl, $stylesheetImage);
                    if ($node->hasAttribute('media')) {
                        $style->setAttribute('media', $node->getAttribute('media'));
                    }
                    $parent->append($style);
                }

                continue;
            }
            if ($tag === 'body' || in_array($tag, self::ELEMENTS, true)) {
                $copy = $output->createElement($tag === 'body' ? 'div' : $tag);
                foreach ($node->attributes as $attribute) {
                    if ($attribute->namespaceURI === null && in_array($attribute->name, self::ATTRIBUTES, true)) {
                        $value = $attribute->name === 'style' ? MailCssImages::rewrite($attribute->value, $imageUrl, $hasContent) : $attribute->value;
                        $copy->setAttribute($attribute->name, $value);
                    }
                }
                $hasBodyElement = $hasBodyElement || $tag !== 'body' || $copy->hasAttributes();
                if (in_array($tag, ['body', 'table', 'td', 'th'], true) && $node->hasAttribute('background')) {
                    $background = $imageUrl($node->getAttribute('background'));
                    if ($background !== null) {
                        $cssUrl = addcslashes($background, '"\\');
                        $copy->setAttribute('style', 'background-image:url("'.$cssUrl.'");'.($copy->getAttribute('style') ?? ''));
                        $hasContent = true;
                    }
                }
                if ($tag === 'body') {
                    self::bodyColors($node, $copy);
                } elseif ($tag === 'a') {
                    $href = trim($node->getAttribute('href') ?? '');
                    if (self::isWebUrl($href) || self::isContactUrl($href)) {
                        $copy->setAttribute('href', $href);
                        $copy->setAttribute('target', '_blank');
                        $copy->setAttribute('rel', 'noopener noreferrer');
                    }
                } elseif ($tag === 'img') {
                    $url = $imageUrl($node->getAttribute('src') ?? '') ?? self::responsiveImage($node, $imageUrl);
                    if ($url !== null) {
                        $copy->setAttribute('src', $url);
                    }
                    $hasContent = $hasContent || $url !== null || trim($copy->getAttribute('alt') ?? '') !== '';
                }
                $parent->append($copy);
                $parent = $copy;
            }
            if ($node->firstChild !== null) {
                $pending[] = [$node->firstChild, $parent];
            }
        }

        return $hasContent || ($stylesheetImage && $hasBodyElement) ? $fragment->innerHTML : null;
    }

    private static function bodyColors(Element $body, Element $wrapper): void
    {
        $styles = '';
        foreach (['bgcolor' => 'background-color', 'text' => 'color'] as $attribute => $property) {
            $color = trim($body->getAttribute($attribute) ?? '');
            if (preg_match('/\A(?:#[\da-f]{3,8}|[a-z]+)\z/i', $color)) {
                $styles .= $property.':'.$color.';';
            }
        }
        if ($styles !== '') {
            $wrapper->setAttribute('style', $styles.($wrapper->getAttribute('style') ?? ''));
        }
    }

    private static function responsiveImage(Element $image, callable $resolve): ?string
    {
        $url = self::srcsetImage($image->getAttribute('srcset') ?? '', $resolve);
        if ($url !== null || $image->parentElement?->localName !== 'picture') {
            return $url;
        }

        // A picture's sources belong to its following img. Keep their declared order.
        $first = $image;
        while (($previous = $first->previousElementSibling) !== null && $previous->localName !== 'img') {
            $first = $previous;
        }
        for ($source = $first; $source !== $image; $source = $source->nextElementSibling) {
            if ($source->localName !== 'source') {
                continue;
            }
            $type = strtolower(trim(explode(';', $source->getAttribute('type') ?? '', 2)[0]));
            $media = strtolower(trim($source->getAttribute('media') ?? ''));
            if (! in_array($type, ['', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)
                || ! in_array($media, ['', 'screen', 'all'], true)) {
                continue;
            }
            $url = self::srcsetImage($source->getAttribute('srcset') ?? '', $resolve);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private static function srcsetImage(string $set, callable $resolve): ?string
    {
        $offset = 0;
        // Commas inside a URL (including a data URL) are part of the candidate.
        while (preg_match('/[,\t\n\f\r ]*+([^\t\n\f\r ]++)/A', $set, $match, 0, $offset)) {
            $url = $match[1];
            $offset += strlen($match[0]);
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $length = strcspn($set, ',', $offset);
                $descriptor = trim(substr($set, $offset, $length));
                $offset += $length;
                if ($descriptor !== '' && ! preg_match('/\A(?:[1-9]\d*w|(?:\d+(?:\.\d+)?|\.\d+)x)\z/', $descriptor)) {
                    continue;
                }
            }
            $image = $resolve($url);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    private static function isWebUrl(string $url): bool
    {
        return preg_match('~\Ahttps?://[^\s\\\\]+\z~iu', $url) === 1
            && filter_var((string) Http::tryNew($url), FILTER_VALIDATE_URL) !== false;
    }

    private static function isContactUrl(string $url): bool
    {
        if (preg_match('/\Atel:\+?[0-9][0-9().-]*(?:;ext=[0-9]+)?\z/i', $url)) {
            return true;
        }
        if (! preg_match('/\Amailto:([^?#\s]+)(?:\?(?:subject|body)=[^&#\s]*(?:&(?:subject|body)=[^&#\s]*)*)?\z/i', $url, $parts)
            || preg_match('/[\x00-\x1f\x7f]/', rawurldecode($url))) {
            return false;
        }

        return filter_var(rawurldecode($parts[1]), FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
    }

    private static function imageUrl(string $src, array $inlineImages, array &$validatedImages, int &$remaining, ?callable $proxy): ?string
    {
        $src = trim($src);
        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }
        if ($remaining <= 0) {
            return null;
        }
        if (preg_match('~\Ahttps?://~i', $src)) {
            $url = $proxy !== null && self::isWebUrl($src) ? $proxy($src) : null;
        } elseif (strncasecmp($src, 'cid:', 4) === 0) {
            $id = rawurldecode(substr($src, 4));
            $src = $inlineImages[$id] ?? '';
            if ($src === '' || strlen($src) > $remaining) {
                return null;
            }
            if (! array_key_exists($id, $validatedImages)) {
                $validatedImages[$id] = self::bitmapDataUrl($src);
            }
            $url = $validatedImages[$id];
        } else {
            $url = strlen($src) <= $remaining ? self::bitmapDataUrl($src) : null;
        }
        if ($url === null || strlen($url) > $remaining) {
            return null;
        }
        $remaining -= strlen($url);

        return $url;
    }

    private static function bitmapDataUrl(string $src): ?string
    {
        if (! preg_match('~\Adata:(image/(?:png|jpeg|gif|webp));base64,~i', $src, $match)) {
            return null;
        }
        $bytes = base64_decode(substr($src, strlen($match[0])), true);
        $image = $bytes === false || $bytes === '' ? false : @getimagesizefromstring($bytes);

        return $image && $image['mime'] === strtolower($match[1]) ? 'data:'.$image['mime'].';base64,'.base64_encode($bytes) : null;
    }
}
