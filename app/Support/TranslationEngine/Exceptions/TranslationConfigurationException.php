<?php

namespace App\Support\TranslationEngine\Exceptions;

use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use RuntimeException;

/**
 * The engine cannot route this batch: no provider is configured, the
 * configured one is not registered or lacks a setting, or it may not receive
 * the batch's data classification.
 *
 * Always thrown before a request leaves the application. A missing setting is
 * named; its value never is.
 */
final class TranslationConfigurationException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly TranslationErrorCode $errorCode,
        public readonly ?string $provider = null,
    ) {
        parent::__construct($message);
    }

    public static function noDefaultProvider(): self
    {
        return new self('No translation provider is configured (translation.default).', TranslationErrorCode::NotConfigured);
    }

    public static function unknownProvider(string $provider): self
    {
        return new self("Translation provider [{$provider}] is not registered in translation.providers.", TranslationErrorCode::NotConfigured, $provider);
    }

    public static function invalidDriver(string $provider): self
    {
        return new self("Translation provider [{$provider}] does not name a driver that implements the translation provider contract.", TranslationErrorCode::NotConfigured, $provider);
    }

    public static function missingSetting(string $provider, string $setting): self
    {
        return new self("Translation provider [{$provider}] is not configured: [{$setting}] is missing or invalid.", TranslationErrorCode::NotConfigured, $provider);
    }

    public static function classificationNotAllowed(string $provider, TranslationDataClassification $classification): self
    {
        return new self("Translation provider [{$provider}] may not receive {$classification->value} data.", TranslationErrorCode::ClassificationNotAllowed, $provider);
    }

    public static function invalidLimit(string $setting): self
    {
        return new self("The translation setting [{$setting}] must be a positive integer.", TranslationErrorCode::NotConfigured);
    }
}
