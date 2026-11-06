<?php

namespace App\Support\TranslationEngine\Enums;

/**
 * Why a translation was not produced, in terms a consumer can act on.
 *
 * Every code has a fixed, generic message. Nothing a provider returned — no
 * response body, no provider error text — ever becomes part of one, so a
 * message is always safe to show an administrator or write to a log.
 */
enum TranslationErrorCode: string
{
    /** No provider is configured, the configured one is not registered, or it lacks a setting such as its API key. */
    case NotConfigured = 'not_configured';

    /** The configured provider may not receive this batch's data classification. */
    case ClassificationNotAllowed = 'classification_not_allowed';

    /** The provider refused the credentials. */
    case AuthenticationFailed = 'authentication_failed';

    /** The provider asked to slow down. */
    case RateLimited = 'rate_limited';

    /** The provider could not be reached, or failed on its side. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The provider did not answer in time. */
    case ProviderTimeout = 'provider_timeout';

    /** The provider rejected the request itself — an unknown model, for instance. */
    case ProviderRejectedRequest = 'provider_rejected_request';

    /** The model declined to produce the translation. */
    case ProviderRefused = 'provider_refused';

    /** The provider answered, but not with the structured output the request required. */
    case InvalidProviderResponse = 'invalid_provider_response';

    /** The translation broke a constraint of its item: blank, too long, a line break, a lost placeholder. */
    case ConstraintViolation = 'constraint_violation';

    /** The item is larger than the provider accepts in one request, and is never cut to fit. */
    case RequestTooLarge = 'request_too_large';

    public function message(): string
    {
        return match ($this) {
            self::NotConfigured => 'Machine translation is not configured.',
            self::ClassificationNotAllowed => 'The configured translation provider may not receive this kind of content.',
            self::AuthenticationFailed => 'The translation provider rejected the credentials.',
            self::RateLimited => 'The translation provider is rate limiting requests. Try again later.',
            self::ProviderUnavailable => 'The translation provider is unavailable. Try again later.',
            self::ProviderTimeout => 'The translation provider did not respond in time.',
            self::ProviderRejectedRequest => 'The translation provider rejected the request.',
            self::ProviderRefused => 'The translation provider declined to translate this content.',
            self::InvalidProviderResponse => 'The translation provider returned a response that could not be used.',
            self::ConstraintViolation => 'The translation does not meet the constraints of this text.',
            self::RequestTooLarge => 'The text is too large to translate in one request.',
        };
    }
}
