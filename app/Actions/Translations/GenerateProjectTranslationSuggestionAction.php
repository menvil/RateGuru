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
 * Asks the translation engine for a suggestion for one unit in one language —
 * what Translation Center's AI translate, Suggest alternative and Regenerate
 * do — and returns it. It stores nothing: a suggestion becomes project content
 * only when an administrator saves it, through UpdateProjectTranslationAction
 * like any other draft.
 *
 * The caller names the unit and the language, and says which stored
 * translation the administrator is looking at: none for a missing one, the
 * text for a saved one. None of it is trusted. The unit is found again in
 * ProjectTranslationCatalog as it is now, and everything sent for translation
 * — the English text, the limits, the placeholders, the context, the other
 * languages — is the catalog's, never the browser's. The stored text the
 * browser names is only compared, never sent: a suggestion is made only while
 * the stored translation is still the one the administrator sees, so it never
 * lands against text someone else saved or changed meanwhile, in another tab.
 *
 * A missing translation gets a suggestion that fills it; a saved one gets an
 * alternative that replaces nothing until it is saved. Every refusal happens
 * before the engine is asked, so it costs nothing.
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
    public function handle(User $actor, mixed $unitId, mixed $targetLocale, mixed $stored = ''): ProjectTranslationSuggestion
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

        $seen = is_string($stored) ? $stored : '';

        if (($unit->translation($targetLocale) ?? '') !== $seen) {
            throw $seen === ''
                ? CannotSuggestTranslationException::becauseItIsAlreadyTranslated()
                : CannotSuggestTranslationException::becauseItChanged();
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
