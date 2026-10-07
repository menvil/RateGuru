<?php

namespace App\Actions\Translations;

use App\Exceptions\Translations\CannotSuggestTranslationException;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\TranslationEngine\TranslationService;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationRequestFactory;
use App\Support\Translations\ProjectTranslationSuggestion;
use App\Support\Translations\TranslatableField;
use Illuminate\Support\Facades\Gate;

/**
 * Asks the translation engine for a suggestion for one missing translation —
 * what Translation Center's AI translate and Regenerate do — and returns it.
 * It stores nothing: a suggestion becomes project content only when an
 * administrator saves it, through UpdateProjectTranslationAction like any
 * other draft.
 *
 * Only the unit id and the language come from the caller, and neither is
 * trusted. The unit is found again in ProjectTranslationCatalog as it is now,
 * and everything sent for translation — the English text, the limits, the
 * placeholders, the context, the other languages — is the catalog's, never
 * the browser's.
 *
 * It fills a gap and replaces nothing: a unit whose translation in that
 * language has been saved meanwhile — by someone else, in another tab — is
 * refused before anything is sent. Every refusal happens before the engine is
 * asked, so it costs nothing.
 *
 * One unit is a batch of one: the request goes through the engine's one batch
 * contract, exactly as a batch of many would. There is no retry — every
 * request may be a paid one, and asking again is the administrator's choice.
 */
final class GenerateProjectTranslationSuggestionAction
{
    public function __construct(
        private readonly ProjectTranslationCatalog $catalog,
        private readonly ProjectTranslationRequestFactory $requests,
        private readonly TranslationService $translations,
        private readonly LocaleManager $locales,
    ) {}

    /**
     * @throws CannotSuggestTranslationException
     */
    public function handle(User $actor, mixed $unitId, mixed $targetLocale): ProjectTranslationSuggestion
    {
        if (! Gate::forUser($actor)->allows('manage-project-settings')) {
            throw CannotSuggestTranslationException::becauseUserIsNotAllowed();
        }

        if (! is_string($targetLocale) || ! $this->locales->isSupported($targetLocale)) {
            throw CannotSuggestTranslationException::becauseLocaleIsNotInstalled();
        }

        if ($targetLocale === TranslatableField::REFERENCE_LOCALE) {
            throw CannotSuggestTranslationException::becauseLocaleIsTheReference();
        }

        $unit = $this->catalog->find($unitId);

        if ($unit === null) {
            throw CannotSuggestTranslationException::becauseUnitIsUnknown();
        }

        if (! $unit->requiresTranslation()) {
            throw CannotSuggestTranslationException::becauseThereIsNothingToTranslate();
        }

        if ($unit->translation($targetLocale) !== null) {
            throw CannotSuggestTranslationException::becauseItIsAlreadyTranslated();
        }

        try {
            $result = $this->translations->translate($this->requests->make([$unit], $targetLocale));
        } catch (TranslationConfigurationException $exception) {
            throw CannotSuggestTranslationException::becauseTheEngineFailed($exception->errorCode);
        } catch (InvalidTranslationRequestException) {
            // The unit cannot be described within the engine's contract or
            // limits — in practice, a text too large to send.
            throw CannotSuggestTranslationException::becauseTheEngineFailed(TranslationErrorCode::RequestTooLarge);
        }

        $item = $result->item($unit->id);

        if ($item === null || ! $item->isSuccessful() || $item->text === null) {
            throw CannotSuggestTranslationException::becauseTheEngineFailed($item->errorCode ?? TranslationErrorCode::InvalidProviderResponse);
        }

        $call = $this->callFor($result->calls, $unit->id);

        return new ProjectTranslationSuggestion(
            $unit->id,
            $targetLocale,
            $item->text,
            $call->provider ?? '',
            $call->model ?? '',
            now()->toImmutable(),
        );
    }

    /** @param  list<TranslationProviderCall>  $calls */
    private function callFor(array $calls, string $unitId): ?TranslationProviderCall
    {
        foreach ($calls as $call) {
            if (in_array($unitId, $call->itemIds, true)) {
                return $call;
            }
        }

        return null;
    }
}
