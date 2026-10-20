<?php

namespace App\Enums;

/**
 * Why a batch of post text suggestions ended without texts — kept on its
 * row and shown to the administrator as message(), never as anything the
 * provider said.
 */
enum StudioPostTextGenerationError: string
{
    case NotConfigured = 'not_configured';
    case AnalysisMissing = 'analysis_missing';
    case StaleAnalysis = 'stale_analysis';
    case PlanClosed = 'plan_closed';
    case AuthenticationFailed = 'authentication_failed';
    case RateLimited = 'rate_limited';
    case ProviderTimeout = 'provider_timeout';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderRejected = 'provider_rejected';
    case ProviderRefused = 'provider_refused';
    case InvalidProviderResponse = 'invalid_provider_response';
    case UnexpectedFailure = 'unexpected_failure';

    public function message(): string
    {
        return match ($this) {
            self::NotConfigured => 'Text suggestions are not configured.',
            self::AnalysisMissing => 'Text suggestions will be available when photo analysis is complete.',
            self::StaleAnalysis => 'The photo or its analysis changed while the suggestions were written, so they were not kept.',
            self::PlanClosed => 'This plan is no longer a draft, so the suggestions were not kept.',
            self::AuthenticationFailed => 'OpenAI did not accept the configured API key.',
            self::RateLimited => 'OpenAI is limiting requests right now. Try again in a few minutes.',
            self::ProviderTimeout => 'OpenAI did not answer in time. Try again.',
            self::ProviderUnavailable => 'OpenAI could not be reached. Try again later.',
            self::ProviderRejected => 'OpenAI rejected the request. Check the configured model.',
            self::ProviderRefused => 'OpenAI declined to write suggestions for this photo.',
            self::InvalidProviderResponse => 'OpenAI’s suggestions could not be used. Try again.',
            self::UnexpectedFailure => 'Writing the suggestions failed unexpectedly. Try again.',
        };
    }
}
