<?php

namespace App\Actions\Translations;

use App\Exceptions\Translations\CannotSaveTranslationException;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;
use App\Support\Translations\TranslatableField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Saves one language's translation of one piece of project content — what
 * Translation Center's Save does — where that content already keeps its
 * translations, so the editors that still translate in place read and write
 * the same value.
 *
 * Only the unit id, the language and the text come from the caller, and none
 * of them is trusted: the unit is found again in ProjectTranslationCatalog,
 * from the database, under a lock on its row, and its section, record, field
 * and limits are the catalog's — whatever the browser showed or sent. What is
 * written is that language's entry and nothing else.
 *
 * Any installed language but English may be translated, enabled or not, so a
 * language can be prepared before it is offered. English is the reference text
 * and is changed in the content's own editor.
 *
 * The text is trimmed; a blank one removes the stored translation, and
 * visitors see the English text again. A text that breaks the field's limits —
 * its maximum length, a single line, every placeholder of the English text —
 * is refused and nothing is written.
 */
final class UpdateProjectTranslationAction
{
    public function __construct(
        private readonly ProjectTranslationCatalog $catalog,
        private readonly LocaleManager $locales,
        private readonly ProjectSettingsManager $settings,
    ) {}

    /**
     * @return ProjectTranslationUnit the unit as stored now
     *
     * @throws CannotSaveTranslationException
     */
    public function handle(User $actor, mixed $unitId, mixed $locale, mixed $text): ProjectTranslationUnit
    {
        if (! Gate::forUser($actor)->allows('manage-project-settings')) {
            throw CannotSaveTranslationException::becauseUserIsNotAllowed();
        }

        if (! is_string($locale) || ! $this->locales->isSupported($locale)) {
            throw CannotSaveTranslationException::becauseLocaleIsNotInstalled();
        }

        if ($locale === TranslatableField::REFERENCE_LOCALE) {
            throw CannotSaveTranslationException::becauseLocaleIsTheReference();
        }

        if (! is_string($text)) {
            throw CannotSaveTranslationException::becauseItIsNotText();
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        $unit = DB::transaction(fn (): ?ProjectTranslationUnit => $this->catalog->write(
            $unitId,
            $locale,
            $text,
            fn (ProjectTranslationUnit $unit) => $this->check($unit, $text),
        ));

        if ($unit === null) {
            throw CannotSaveTranslationException::becauseUnitIsUnknown();
        }

        if (in_array($unit->section, [ProjectContentSection::ProjectSettings, ProjectContentSection::StaticPages], true)) {
            $this->settings->flush();
        }

        return $unit;
    }

    /**
     * The limits of the unit as the locked row holds it. Removing a
     * translation is always allowed; text is held to the field's limits,
     * characters counted as code points, as the editors count them.
     */
    private function check(ProjectTranslationUnit $unit, string $text): void
    {
        if (! $unit->requiresTranslation()) {
            throw CannotSaveTranslationException::becauseThereIsNothingToTranslate();
        }

        if ($text === '') {
            return;
        }

        if (! $unit->multiline && str_contains($text, "\n")) {
            throw CannotSaveTranslationException::becauseItSpansLines();
        }

        $over = mb_strlen($text) - $unit->maxLength;

        if ($over > 0) {
            throw CannotSaveTranslationException::becauseItIsTooLong($over);
        }

        $lost = array_values(array_filter($unit->placeholders(), fn (string $placeholder): bool => ! str_contains($text, $placeholder)));

        if ($lost !== []) {
            throw CannotSaveTranslationException::becausePlaceholdersAreMissing($lost);
        }
    }
}
