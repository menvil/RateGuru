<?php

namespace App\Exceptions\Translations;

use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use DomainException;

/**
 * No AI suggestion for this unit, and why, in words an administrator can act
 * on. Nothing is stored when one is thrown, and nothing a provider returned is
 * ever part of the message.
 *
 * The reasons before the translation engine is asked are the request's; the
 * rest are the engine's own error codes, carried as they are.
 */
final class CannotSuggestTranslationException extends DomainException
{
    public const REASON_NOT_ALLOWED = 'not_allowed';

    public const REASON_UNKNOWN_UNIT = 'unknown_unit';

    public const REASON_UNKNOWN_LOCALE = 'unknown_locale';

    public const REASON_REFERENCE_LOCALE = 'reference_locale';

    public const REASON_NOTHING_TO_TRANSLATE = 'nothing_to_translate';

    public const REASON_ALREADY_TRANSLATED = 'already_translated';

    public const REASON_CHANGED = 'changed';

    public const REASON_ENGINE_FAILED = 'engine_failed';

    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?TranslationErrorCode $errorCode = null,
    ) {
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

    public static function becauseItIsAlreadyTranslated(): self
    {
        return new self('This translation was saved by someone else. Reload the page to review it.', self::REASON_ALREADY_TRANSLATED);
    }

    public static function becauseItChanged(): self
    {
        return new self('This translation was changed by someone else. Reload the page to review it.', self::REASON_CHANGED);
    }

    /** The engine produced no usable translation; its code says why, in our words. */
    public static function becauseTheEngineFailed(TranslationErrorCode $code): self
    {
        return new self(self::messageFor($code), self::REASON_ENGINE_FAILED, $code);
    }

    /**
     * What an administrator is told about one of the engine's error codes —
     * for an interactive suggestion and a background one alike.
     */
    public static function messageFor(TranslationErrorCode $code): string
    {
        return match ($code) {
            TranslationErrorCode::NotConfigured => 'Machine translation is not configured.',
            TranslationErrorCode::ClassificationNotAllowed => 'The configured translation provider may not receive project content.',
            TranslationErrorCode::AuthenticationFailed => 'The translation provider rejected the credentials. Machine translation is unavailable until they are fixed.',
            TranslationErrorCode::RateLimited => 'The translation provider is busy. Try again in a moment.',
            TranslationErrorCode::ProviderUnavailable => 'The translation provider is unavailable. Try again.',
            TranslationErrorCode::ProviderTimeout => 'The translation provider did not answer in time. Try again.',
            TranslationErrorCode::ProviderRejectedRequest => 'The translation provider rejected the request. Translate this item manually.',
            TranslationErrorCode::ProviderRefused => 'The translation provider declined to translate this text. Translate it manually.',
            TranslationErrorCode::InvalidProviderResponse => 'The translation provider returned an answer that could not be used. Try again.',
            TranslationErrorCode::ConstraintViolation => 'The AI translation did not meet this field’s constraints. Try generating again or translate it manually.',
            TranslationErrorCode::RequestTooLarge => 'This text is too long to translate in one request. Translate it manually.',
        };
    }
}
