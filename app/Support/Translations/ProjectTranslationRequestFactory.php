<?php

namespace App\Support\Translations;

use App\Support\Locale\LanguageRules;
use App\Support\Locale\LocaleManager;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;

/**
 * Turns units of this project's own content into a request for the
 * translation engine — the one place project content is described to it.
 *
 * The dependency runs one way: this knows ProjectTranslationUnit and the
 * engine's request contract; the engine knows nothing of the project. Everything
 * that describes a unit to a model is read from the catalog's unit as it is
 * now — its English text, its limits, its placeholders, where a visitor meets
 * it, and what the other languages already store — so nothing here repeats a
 * field length or parses a placeholder of its own.
 *
 * It takes a list on purpose: an interactive suggestion is a batch of one,
 * and translating every missing unit of a language is the same call with
 * many. Translation Center's context drawer builds its preview of what AI
 * translate sends from here too, so the preview and the request cannot drift.
 */
final class ProjectTranslationRequestFactory
{
    /**
     * How many characters of other languages' translations one unit may carry
     * as context, in total. Context helps with terminology and tone, but a long
     * static page stored in many languages would otherwise make one unit larger
     * than any provider request, and the unit could never be translated at all.
     * Languages are taken in their installed order; one that does not fit is
     * left out whole, never cut.
     */
    public const CONTEXT_TRANSLATIONS_BUDGET = 20_000;

    public function __construct(private readonly LocaleManager $locales) {}

    /**
     * One logical batch: every unit, in the order given, into one target
     * language. Project content is public content — what visitors read.
     *
     * The glossary is the caller's: none exists for project content yet, and
     * a later source of one is passed here without the engine changing.
     *
     * @param  list<ProjectTranslationUnit>  $units
     * @param  array<string, string>  $glossary  source term => required target form
     *
     * @throws InvalidTranslationRequestException when a unit cannot be described to the engine — blank English, for instance
     */
    public function make(array $units, string $targetLocale, array $glossary = []): TranslationBatchRequest
    {
        return new TranslationBatchRequest(
            // Named so a translator cannot mistake the script or variant: Serbian is sr-Cyrl, not Latin.
            LanguageRules::translationLocale($targetLocale),
            TranslationDataClassification::PublicContent,
            array_map(fn (ProjectTranslationUnit $unit): TranslationItem => $this->item($unit, $targetLocale), $units),
            $glossary,
        );
    }

    private function item(ProjectTranslationUnit $unit, string $targetLocale): TranslationItem
    {
        return new TranslationItem(
            id: $unit->id,
            sourceLocale: TranslatableField::REFERENCE_LOCALE,
            sourceText: $unit->reference,
            contentType: self::contentType($unit),
            multiline: $unit->multiline,
            context: self::context($unit),
            maxLength: $unit->maxLength,
            placeholders: $unit->placeholders(),
            existingTranslations: $this->existingTranslations($unit, $targetLocale),
        );
    }

    /**
     * What kind of text a unit is, stable across records: `categories.name`,
     * `static_pages.title`. Never a record id, slug or page — those identify
     * one piece of content, and the unit's id and context already carry them.
     */
    public static function contentType(ProjectTranslationUnit $unit): string
    {
        return "{$unit->section->value}.{$unit->field}";
    }

    /**
     * What the text is and where a visitor meets it, in a few labelled lines:
     * the section, the content it belongs to, its field, its business key and
     * the catalog's own description of where it appears.
     */
    public static function context(ProjectTranslationUnit $unit): string
    {
        return implode("\n", [
            "Section: {$unit->section->label()}",
            "Entity: {$unit->label}",
            "Field: {$unit->fieldName()}",
            "Business key: {$unit->qualifiedKey()}",
            "Usage: {$unit->usage}",
        ]);
    }

    /**
     * What the other installed languages store for the unit, as context:
     * enabled or not, never English — the source itself — never the target,
     * never a value that is not text, never a language that is not installed.
     * Within CONTEXT_TRANSLATIONS_BUDGET, in installed order.
     *
     * @return array<string, string> locale => translation
     */
    private function existingTranslations(ProjectTranslationUnit $unit, string $targetLocale): array
    {
        $translations = [];
        $used = 0;

        foreach (array_keys($this->locales->supported()) as $locale) {
            $text = $unit->translation($locale);

            if ($locale === TranslatableField::REFERENCE_LOCALE || $locale === $targetLocale || $text === null) {
                continue;
            }

            $length = mb_strlen($text);

            if ($used + $length > self::CONTEXT_TRANSLATIONS_BUDGET) {
                continue;
            }

            $translations[$locale] = $text;
            $used += $length;
        }

        return $translations;
    }
}
