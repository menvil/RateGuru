<?php

namespace App\Enums;

/**
 * Why a photo analysis attempt ended without a result — kept on the analysis
 * row and shown to the administrator as message(), never as anything the
 * provider said.
 */
enum StudioImageAnalysisError: string
{
    case NotConfigured = 'not_configured';
    case ImageMissing = 'image_missing';
    case ImageUnavailable = 'image_unavailable';
    case AuthenticationFailed = 'authentication_failed';
    case RateLimited = 'rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderTimeout = 'provider_timeout';
    case ProviderRejected = 'provider_rejected';
    case ProviderRefused = 'provider_refused';
    case InvalidProviderResponse = 'invalid_provider_response';
    case StaleImage = 'stale_image';
    case PlanClosed = 'plan_closed';
    case UnexpectedFailure = 'unexpected_failure';

    public function message(): string
    {
        return match ($this) {
            self::NotConfigured => 'Photo analysis is not configured.',
            self::ImageMissing => 'This draft has no saved photo to analyse.',
            self::ImageUnavailable => 'The saved photo could not be read for analysis.',
            self::AuthenticationFailed => 'OpenAI did not accept the configured API key.',
            self::RateLimited => 'OpenAI is limiting requests right now. Try again in a few minutes.',
            self::ProviderUnavailable => 'OpenAI could not be reached. Try again later.',
            self::ProviderTimeout => 'OpenAI did not answer in time. Try again.',
            self::ProviderRejected => 'OpenAI rejected the request. Check the configured model.',
            self::ProviderRefused => 'OpenAI declined to describe this photo.',
            self::InvalidProviderResponse => 'OpenAI’s answer could not be used. Try again.',
            self::StaleImage => 'The photo was changed while it was being analysed, so the result was not kept.',
            self::PlanClosed => 'This plan is no longer a draft, so the analysis was not kept.',
            self::UnexpectedFailure => 'The analysis failed unexpectedly. Try again.',
        };
    }
}
