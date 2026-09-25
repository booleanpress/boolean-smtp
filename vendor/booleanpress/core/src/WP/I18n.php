<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\WP;

/**
 * i18n Wrapper
 *
 * Provides a clean interface for WordPress internationalization functions.
 */
class I18n
{
    /**
     * The text domain.
     */
    protected string $domain;

    /**
     * Create a new I18n instance.
     */
    public function __construct(string $domain)
    {
        $this->domain = $domain;
    }

    /**
     * Translate a string.
     */
    public function translate(string $text): string
    {
        return __($text, $this->domain);
    }

    /**
     * Display a translated string.
     */
    public function display(string $text): void
    {
        _e($text, $this->domain);
    }

    /**
     * Translate a string with context.
     */
    public function translateWithContext(string $text, string $context): string
    {
        return _x($text, $context, $this->domain);
    }

    /**
     * Translate plural strings.
     */
    public function translatePlural(string $single, string $plural, int $number): string
    {
        return _n($single, $plural, $number, $this->domain);
    }

    /**
     * Translate plural strings with context.
     */
    public function translatePluralWithContext(string $single, string $plural, int $number, string $context): string
    {
        return _nx($single, $plural, $number, $context, $this->domain);
    }

    /**
     * Escape and translate a string.
     */
    public function translateAndEscapeHtml(string $text): string
    {
        return esc_html__($text, $this->domain);
    }

    /**
     * Escape and translate a string for attribute.
     */
    public function translateAndEscapeAttribute(string $text): string
    {
        return esc_attr__($text, $this->domain);
    }
}
