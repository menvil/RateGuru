<?php

namespace App\Exceptions\Translations;

use DomainException;

/**
 * A translation that was not written, with what the administrator can do
 * about it. Nothing is stored when one is thrown.
 *
 * A reason about the text itself — too long, a placeholder lost, a line break
 * in a single-line field — belongs next to the field; the others are about
 * the request and are reported as such.
 */
final class CannotSaveTranslationException extends DomainException
{
    public const REASON_NOT_ALLOWED = 'not_allowed';

    public const REASON_UNKNOWN_UNIT = 'unknown_unit';

    public const REASON_UNKNOWN_LOCALE = 'unknown_locale';

    public const REASON_REFERENCE_LOCALE = 'reference_locale';

    public const REASON_NOTHING_TO_TRANSLATE = 'nothing_to_translate';

    public const REASON_NOT_TEXT = 'not_text';

    public const REASON_TOO_LONG = 'too_long';

    public const REASON_PLACEHOLDER_MISSING = 'placeholder_missing';

    public const REASON_LINE_BREAK = 'line_break';

    private const ABOUT_THE_TEXT = [self::REASON_TOO_LONG, self::REASON_PLACEHOLDER_MISSING, self::REASON_LINE_BREAK];

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('You may not edit project translations.', self::REASON_NOT_ALLOWED);
    }

    public static function becauseUnitIsUnknown(): self
    {
        return new self('This item is no longer translated here. Reload the page to see the current content.', self::REASON_UNKNOWN_UNIT);
    }

    public static function becauseLocaleIsNotInstalled(): self
    {
        return new self('That language is not installed.', self::REASON_UNKNOWN_LOCALE);
    }

    public static function becauseLocaleIsTheReference(): self
    {
        return new self('English is the reference language. Change it in the content’s own editor.', self::REASON_REFERENCE_LOCALE);
    }

    public static function becauseThereIsNothingToTranslate(): self
    {
        return new self('The English text is empty, so there is nothing to translate.', self::REASON_NOTHING_TO_TRANSLATE);
    }

    public static function becauseItIsNotText(): self
    {
        return new self('A translation has to be text.', self::REASON_NOT_TEXT);
    }

    public static function becauseItIsTooLong(int $over): self
    {
        return new self("{$over} over the limit", self::REASON_TOO_LONG);
    }

    /** @param  non-empty-list<string>  $placeholders */
    public static function becausePlaceholdersAreMissing(array $placeholders): self
    {
        return new self('Keep '.implode(', ', $placeholders), self::REASON_PLACEHOLDER_MISSING);
    }

    public static function becauseItSpansLines(): self
    {
        return new self('Remove the line break: this text is a single line', self::REASON_LINE_BREAK);
    }

    /** Whether the reason is the text itself, to be shown next to the field. */
    public function isAboutTheText(): bool
    {
        return in_array($this->reason, self::ABOUT_THE_TEXT, true);
    }
}
