<?php

namespace App\Actions\Translations;

use App\Actions\Translations\Concerns\ResolvesProjectTranslationGeneration;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Models\User;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use Carbon\CarbonImmutable;

/**
 * The background generation an administrator has for one language — what
 * Translation Center restores when it opens and polls while it runs — or null
 * when there is none.
 *
 * Always the actor's own: the batch is found through the actor's pointer for
 * the language, never through anything the browser names. A pointer to a
 * batch that has expired is cleaned up on the way. Reading reconciles a chunk
 * a worker abandoned, and never extends the batch's expiry.
 */
final class ReadProjectTranslationGenerationAction
{
    use ResolvesProjectTranslationGeneration;

    public function __construct(private readonly ProjectTranslationGenerationStore $store) {}

    /**
     * @return array<string, mixed>|null the batch's summary for the browser
     *
     * @throws CannotGenerateTranslationsException
     */
    public function handle(User $actor, mixed $locale): ?array
    {
        $this->authorize($actor);
        $locale = $this->targetLocale($locale);
        $userId = (int) $actor->getKey();

        $batchId = $this->store->activeBatchId($userId, $locale);

        if ($batchId === null) {
            return null;
        }

        $batch = $this->store->read($batchId, CarbonImmutable::now());

        if ($batch === null || $batch['meta']['user_id'] !== $userId || $batch['meta']['target_locale'] !== $locale) {
            $this->store->forgetActive($userId, $locale, $batchId);

            return null;
        }

        return ProjectTranslationGenerationStore::summary($batch);
    }
}
