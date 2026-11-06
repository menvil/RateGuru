<?php

namespace App\Actions\Translations\Concerns;

use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use App\Support\Translations\TranslatableField;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * What every background generation operation checks first: the actor may
 * manage project settings; the language is installed and is not English;
 * and a batch named by the browser is this actor's batch for this language.
 *
 * A batch id is never authority on its own. One that has expired, never
 * existed, or belongs to another administrator or another language is
 * unavailable — the refusal never says which.
 *
 * @phpstan-import-type Batch from ProjectTranslationGenerationStore
 */
trait ResolvesProjectTranslationGeneration
{
    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('manage-project-settings')) {
            throw CannotGenerateTranslationsException::becauseUserIsNotAllowed();
        }
    }

    private function targetLocale(mixed $locale): string
    {
        if (! is_string($locale) || ! app(LocaleManager::class)->isSupported($locale)) {
            throw CannotGenerateTranslationsException::becauseLocaleIsNotInstalled();
        }

        if ($locale === TranslatableField::REFERENCE_LOCALE) {
            throw CannotGenerateTranslationsException::becauseLocaleIsTheReference();
        }

        return $locale;
    }

    /**
     * @return Batch
     */
    private function ownedBatch(ProjectTranslationGenerationStore $store, User $actor, string $locale, mixed $batchId): array
    {
        $batch = is_string($batchId) && Str::isUuid($batchId) ? $store->read($batchId, CarbonImmutable::now()) : null;

        if ($batch === null
            || $batch['meta']['user_id'] !== (int) $actor->getKey()
            || $batch['meta']['target_locale'] !== $locale) {
            throw CannotGenerateTranslationsException::becauseTheBatchIsUnavailable();
        }

        return $batch;
    }
}
