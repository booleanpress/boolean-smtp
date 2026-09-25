<?php
/**
 * WordPress implementation of the translator contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Core\WP\I18n;
use BooleanSmtp\Contracts\TranslatorContract;

/**
 * Translates strings using BooleanSmtp\Core\WP\I18n.
 *
 * The text domain is bound once at construction so every translation performed through this
 * adapter is consistently scoped to the plugin's text domain and cannot be misspelled at the call
 * site.
 *
 * @since 1.0.0
 */
final class TranslatorAdapter implements TranslatorContract {
    /**
     * Underlying translation helper, bound to this adapter's text domain.
     *
     * @since 1.0.0
     * @var I18n
     */
    private I18n $i18n;

    /**
     * Create a translator bound to a text domain.
     *
     * @since 1.0.0
     *
     * @param string $textDomain Text domain for translations (e.g., 'boolean-smtp')
     */
    public function __construct(string $textDomain = 'boolean-smtp') {
        $this->i18n = new I18n($textDomain);
    }

    /**
     * Translate a message string.
     *
     * @since 1.0.0
     *
     * @param string $text The translatable string
     * @param array<string, mixed> $replacements Variable replacements (optional)
     * @return string Translated (or original if no translation found)
     */
    public function translate(string $text, array $replacements = []): string {
        $translated = $this->i18n->translate($text);

        if (!empty($replacements)) {
            $translated = $this->applyReplacements($translated, $replacements);
        }

        return $translated;
    }

    /**
     * Translate a string and output it, escaped for safe HTML display.
     *
     * @since 1.0.0
     *
     * @param string $text The translatable string
     * @param array<string, mixed> $replacements Variable replacements (optional)
     */
    public function display(string $text, array $replacements = []): void {
        $translated = $this->translate($text, $replacements);
        echo wp_kses_post($translated);
    }

    /**
     * Translate a string using a disambiguation context.
     *
     * @since 1.0.0
     *
     * @param string $text The translatable string
     * @param string $context Context for disambiguation
     * @param array<string, mixed> $replacements Variable replacements (optional)
     * @return string Translated string
     */
    public function translateWithContext(string $text, string $context, array $replacements = []): string {
        $translated = $this->i18n->translateWithContext($text, $context);

        if (!empty($replacements)) {
            $translated = $this->applyReplacements($translated, $replacements);
        }

        return $translated;
    }

    /**
     * Translate a string with singular/plural forms.
     *
     * @since 1.0.0
     *
     * @param string $single Singular form
     * @param string $plural Plural form
     * @param int $number Number to determine form
     * @param array<string, mixed> $replacements Variable replacements (optional)
     * @return string Translated string (singular or plural)
     */
    public function translatePlural(string $single, string $plural, int $number, array $replacements = []): string {
        $translated = $this->i18n->translatePlural($single, $plural, $number);

        if (!empty($replacements)) {
            $translated = $this->applyReplacements($translated, $replacements);
        }

        return $translated;
    }

    /**
     * Translate a string and escape it for safe HTML output.
     *
     * @since 1.0.0
     *
     * @param string $text The translatable string
     * @param array<string, mixed> $replacements Variable replacements (optional)
     * @return string HTML-escaped translated string
     */
    public function translateAndEscapeHtml(string $text, array $replacements = []): string {
        $translated = $this->i18n->translateAndEscapeHtml($text);

        if (!empty($replacements)) {
            $translated = $this->applyReplacements($translated, $replacements);
        }

        return $translated;
    }

    /**
     * Translate a string and escape it for safe use in an HTML attribute.
     *
     * @since 1.0.0
     *
     * @param string $text The translatable string
     * @param array<string, mixed> $replacements Variable replacements (optional)
     * @return string Attribute-escaped translated string
     */
    public function translateAndEscapeAttribute(string $text, array $replacements = []): string {
        $translated = $this->i18n->translateAndEscapeAttribute($text);

        if (!empty($replacements)) {
            $translated = $this->applyReplacements($translated, $replacements);
        }

        return $translated;
    }

    /**
     * Apply placeholder replacements to a translated string.
     *
     * Replaces every occurrence of "{{key}}" with the corresponding value.
     *
     * @since 1.0.0
     *
     * @param string $string The string to replace in
     * @param array<string, mixed> $replacements Key-value pairs
     * @return string String with replacements applied
     */
    private function applyReplacements(string $string, array $replacements): string {
        foreach ($replacements as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $string      = str_replace($placeholder, (string) $value, $string);
        }

        return $string;
    }
}
