<?php

namespace Awove\Core\Imports;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

/** Ordinary history only. No credentials, source fetches, HTML rendering or payload logging. */
final class LegacyPayload
{
    public const MAX_BODY_BYTES = 1048576;

    public const MAX_TEXT_BYTES = 1048576;

    public const KINDS = ['update', 'reply', 'source-created'];

    public static function validate(string $kind, array $input): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            self::invalid();
        }
        self::keys($input, ['body_html', 'author', 'source_created_at', 'source_updated_at']);
        if (! is_array($input['author'])) {
            self::invalid();
        }
        self::keys($input['author'], ['source_id', 'name']);
        $authorId = $input['author']['source_id'];
        if ($authorId !== null) {
            Metadata::opaque($authorId);
        }
        $name = $input['author']['name'];
        if ($name !== null && (! self::text($name) || mb_strlen($name) > 255 || strlen($name) > 1024)) {
            self::invalid();
        }
        foreach (['source_created_at', 'source_updated_at'] as $key) {
            self::timestamp($input[$key]);
        }
        $html = $input['body_html'];
        if ($kind === 'source-created' ? $html !== null : ! self::text($html)) {
            self::invalid();
        }
        if ($html !== null && strlen($html) > self::MAX_BODY_BYTES) {
            self::invalid();
        }
        $media = false;
        $text = $html === null ? '' : self::plainText($html, $media);
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            self::invalid();
        }

        // Fixed structure/order is also the versioned fingerprint representation.
        return ['body_html' => $html, 'text' => $text, 'source_media_present' => $media, 'author' => ['source_id' => $authorId, 'name' => $name],
            'source_created_at' => $input['source_created_at'], 'source_updated_at' => $input['source_updated_at']];
    }

    private static function timestamp(mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (! is_string($value) || ! preg_match('/^(?!0000)([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,9})?(?:Z|([+-])([0-9]{2}):([0-9]{2}))$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (isset($parts[8]) && (int) $parts[8] > 23) || (isset($parts[9]) && (int) $parts[9] > 59)) {
            self::invalid();
        }
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    private static function plainText(string $html, bool &$media): string
    {
        if ($html === '') {
            return '';
        }
        // Source fragments cannot introduce document/entity declarations or processing instructions.
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b|<\?/i', $html)) {
            self::invalid();
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            if (! $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                self::invalid();
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level === LIBXML_ERR_FATAL || stripos($error->message, 'excessive depth') !== false || stripos($error->message, 'resource limit') !== false) {
                    self::invalid();
                }
            }
            $body = $document->getElementsByTagName('body')->item(0);
            $remaining = 100000;
            $text = $body ? self::nodeText($body, 0, $remaining, $media) : '';

            return trim(preg_replace('/\n{3,}/', "\n\n", str_replace(["\r\n", "\r"], "\n", $text)), "\n");
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function nodeText(DOMNode $node, int $depth, int &$remaining, bool &$media): string
    {
        if ($depth > 128 || --$remaining < 0) {
            self::invalid();
        }
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return $node->nodeValue ?? '';
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['img', 'video', 'audio', 'iframe', 'object', 'embed', 'svg'], true)) {
            $media = true;
        }
        if ($tag === 'img') {
            return $node->getAttribute('alt');
        }
        if (in_array($tag, ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'applet', 'svg', 'math', 'head'], true)) {
            return '';
        }
        if (in_array($tag, ['br', 'hr'], true)) {
            return $tag === 'br' ? "\n" : "\n\n";
        }
        $text = '';
        $cells = [];
        foreach ($node->childNodes as $child) {
            $part = self::nodeText($child, $depth + 1, $remaining, $media);
            $text .= $part;
            if ($tag === 'tr' && $child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                $cells[] = $part;
            }
        }
        if ($cells !== []) {
            $text = implode("\t", $cells);
        }
        if ($tag === 'a' && ($href = self::safeHref($node->getAttribute('href'))) !== null) {
            $text = $text === '' ? $href : (trim($text) === $href ? $text : $text.' ('.$href.')');
        }
        $block = in_array($tag, ['p', 'div', 'li', 'ul', 'ol', 'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'table', 'section', 'article'], true);

        return $block ? "\n\n".$text."\n\n" : $text;
    }

    /** Display-only destinations. Never fetch, redirect to or render these as active HTML. */
    private static function safeHref(string $href): ?string
    {
        if ($href === '' || strlen($href) > 2048 || ! mb_check_encoding($href, 'UTF-8') || preg_match('/[\x00-\x20\x7F\\\\]/', $href)
            || preg_match('/[\x00-\x1F\x7F]/', rawurldecode($href))) {
            return null;
        }
        $parts = parse_url($href);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (in_array($scheme, ['http', 'https'], true) && ! empty($parts['host'])) {
            return $href;
        }
        if (isset($parts['host']) || isset($parts['fragment'])) {
            return null;
        }
        $value = rawurldecode($parts['path'] ?? '');
        if ($scheme === 'mailto' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return $href;
        }
        if ($scheme === 'tel' && ! isset($parts['query']) && preg_match('/^\+?[0-9() .-]+(?:;ext=[0-9]+)?$/D', $value)) {
            return $href;
        }

        return null;
    }

    private static function keys(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== [] || array_diff($keys, array_keys($input)) !== []) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['history' => __('The legacy history payload is invalid or exceeds its supported limits.')]);
    }
}
