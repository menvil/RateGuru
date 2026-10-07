<?php

namespace App\Support\Translations\Generation;

/**
 * Why a background batch has no usable suggestion for an item, when the
 * reason is the batch's own rather than the translation engine's.
 *
 * These are orchestration outcomes: the engine was never asked, or its answer
 * could not be used. The engine's own failures keep their engine error codes.
 * Every message is fixed and safe to show; none carries anything a provider
 * or an exception said.
 */
enum ProjectTranslationGenerationIssue: string
{
    case SourceChanged = 'source_changed';
    case AlreadyTranslated = 'already_translated';
    case UnitUnavailable = 'unit_unavailable';
    case NothingToTranslate = 'nothing_to_translate';
    case NotAllowed = 'not_allowed';
    case ProviderConfigurationChanged = 'provider_configuration_changed';
    case WorkerInterrupted = 'worker_interrupted';
    case JobFailed = 'job_failed';
    case DispatchFailed = 'dispatch_failed';
    case SaveRefused = 'save_refused';

    public function message(): string
    {
        return match ($this) {
            self::SourceChanged => 'English changed after this suggestion was generated. Generate a new translation.',
            self::AlreadyTranslated => 'This translation was saved by someone else meanwhile.',
            self::UnitUnavailable => 'This item is no longer translated here.',
            self::NothingToTranslate => 'The English text is empty, so there is nothing to translate.',
            self::NotAllowed => 'Generation stopped: whoever started it may no longer translate project content.',
            self::ProviderConfigurationChanged => 'The translation provider changed after this generation was planned. Generate missing again.',
            self::WorkerInterrupted => 'Background generation was interrupted. Generate missing again, or translate it manually.',
            self::JobFailed => 'Background generation failed. Generate missing again, or translate it manually.',
            self::DispatchFailed => 'Background generation could not be started. Try again.',
            self::SaveRefused => 'This suggestion no longer meets the field’s limits, so it was not saved.',
        };
    }
}
