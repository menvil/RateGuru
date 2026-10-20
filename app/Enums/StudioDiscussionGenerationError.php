<?php

namespace App\Enums;

/**
 * Why a discussion generation was not asked for, ended without a proposal,
 * or could not be taken into the plan — kept on its row, logged, and shown
 * to the administrator as message(), never as anything the provider said.
 */
enum StudioDiscussionGenerationError: string
{
    case NotConfigured = 'not_configured';
    case AnalysisMissing = 'analysis_missing';
    case IncompletePost = 'incomplete_post';
    case NoPersonas = 'no_personas';
    case InvalidPersona = 'invalid_persona';
    case InvalidTarget = 'invalid_target';
    case StaleVersion = 'stale_version';
    case StaleContext = 'stale_context';
    case PlanClosed = 'plan_closed';
    case NotCompleted = 'not_completed';
    case AlreadyApplied = 'already_applied';
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
            self::NotConfigured => 'Discussion generation is not configured.',
            self::AnalysisMissing => 'A discussion can be written once photo analysis is complete.',
            self::IncompletePost => 'Choose the post’s final title before writing a discussion.',
            self::NoPersonas => 'Choose at least one persona to take part.',
            self::InvalidPersona => 'A chosen persona is no longer one who can take part. Choose another.',
            self::InvalidTarget => 'That comment is not part of this plan’s discussion. Reload the plan.',
            self::StaleVersion => 'The draft was changed by another administrator. Reload it and try again.',
            self::StaleContext => 'The post, its photo, its discussion or a persona changed since this was written, so it can no longer be used. Write it again.',
            self::PlanClosed => 'This plan is no longer a draft.',
            self::NotCompleted => 'This generation has no proposal to use.',
            self::AlreadyApplied => 'This proposal has already been taken into the discussion.',
            self::AuthenticationFailed => 'OpenAI did not accept the configured API key.',
            self::RateLimited => 'OpenAI is limiting requests right now. Try again in a few minutes.',
            self::ProviderTimeout => 'OpenAI did not answer in time. Try again.',
            self::ProviderUnavailable => 'OpenAI could not be reached. Try again later.',
            self::ProviderRejected => 'OpenAI rejected the request. Check the configured model.',
            self::ProviderRefused => 'OpenAI declined to write this discussion.',
            self::InvalidProviderResponse => 'OpenAI’s discussion could not be used. Try again.',
            self::UnexpectedFailure => 'Writing the discussion failed unexpectedly. Try again.',
        };
    }
}
