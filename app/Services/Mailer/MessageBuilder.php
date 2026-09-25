<?php

/**
 * Plain-text conversion helpers for HTML message bodies.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

/**
 * Derives a plain-text alternative body from an HTML message.
 *
 * @since 1.0.0
 */
class MessageBuilder
{
    /**
     * Generate a plain-text version from HTML content.
     *
     * @since 1.0.0
     *
     * @param  string $html HTML message body.
     * @return string Plain-text rendering of the HTML body.
     */
    public static function htmlToPlainText(string $html): string
    {
        $text = $html;

        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);

        $text = preg_replace('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', "\n\n$1\n\n", $text);

        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $text);

        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/tr>/i', "\n", $text);
        $text = preg_replace('/<\/td>/i', "\t", $text);
        $text = preg_replace('/<li[^>]*>/i', '  - ', $text);
        $text = preg_replace('/<\/li>/i', "\n", $text);
        $text = preg_replace('/<hr[^>]*>/i', "\n---\n", $text);

        $text = preg_replace('/<img[^>]+alt=["\']([^"\']*)["\'][^>]*>/i', '[$1]', $text);

        $text = \wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/^ +/m', '', $text);

        return trim($text);
    }

    /**
     * Check if the content appears to be HTML.
     *
     * @since 1.0.0
     *
     * @param  string $content Message body to inspect.
     * @return bool True when an HTML tag, comment or doctype was found in the content.
     */
    public static function isHtml(string $content): bool
    {
        return \preg_match('#<(?:[a-z][a-z0-9-]*|/[a-z][a-z0-9-]*|!)[^>]*>#i', $content) === 1;
    }
}
