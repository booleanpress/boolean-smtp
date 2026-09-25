<?php
/**
 * Contract for translating and localizing strings.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts;

/**
 * Guarantees a consistent way to translate strings, supporting plain text, HTML-escaped,
 * attribute-escaped, contextual, and plural forms, independent of the underlying host
 * application's translation mechanism.
 *
 * @since 1.0.0
 */
interface TranslatorContract {
    /**
     * Translate a string.
     *
     * @since 1.0.0
     *
     * @param  string               $text         The translatable string.
     * @param  array<string, mixed> $replacements Variable replacements applied after translation.
     * @return string The translated string, or the original when no translation is found.
     */
    public function translate(string $text, array $replacements = []): string;

    /**
     * Translate a string and output it.
     *
     * @since 1.0.0
     *
     * @param  string               $text         The translatable string.
     * @param  array<string, mixed> $replacements Variable replacements applied after translation.
     */
    public function display(string $text, array $replacements = []): void;

    /**
     * Translate a string using a disambiguation context.
     *
     * @since 1.0.0
     *
     * @param  string               $text         The translatable string.
     * @param  string               $context      Context used to disambiguate identical source strings.
     * @param  array<string, mixed> $replacements Variable replacements applied after translation.
     * @return string The translated string.
     */
    public function translateWithContext(string $text, string $context, array $replacements = []): string;

    /**
     * Translate a string with singular and plural forms.
     *
     * @since 1.0.0
     *
     * @param  string               $single       Singular form of the source string.
     * @param  string               $plural       Plural form of the source string.
     * @param  int                  $number       Count used to determine which form to use.
     * @param  array<string, mixed> $replacements Variable replacements applied after translation.
     * @return string The translated string, in the singular or plural form.
     */
    public function translatePlural(string $single, string $plural, int $number, array $replacements = []): string;

    /**
     * Translate a string and escape it for safe HTML output.
     *
     * @since 1.0.0
     *
     * @param  string               $text         The translatable string.
     * @param  array<string, mixed> $replacements Variable replacements applied after translation.
     * @return string The translated string, HTML-escaped.
     */
    public function translateAndEscapeHtml(string $text, array $replacements = []): string;

    /**
     * Translate a string and escape it for safe use in an HTML attribute.
     *
     * @since 1.0.0
     *
     * @param  string               $text         The translatable string.
     * @param  array<string, mixed> $replacements Variable replacements applied after translation.
     * @return string The translated string, attribute-escaped.
     */
    public function translateAndEscapeAttribute(string $text, array $replacements = []): string;
}
