<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * English lives in a translatable field's base column. The copies presets and
 * the default seeder wrote among the translations are dropped, so a stale one
 * can never be read again; the base column — what the editors and Translation
 * Center show — stays as it is, and takes the copy only where it was blank.
 */
function englishEntriesMigration(): object
{
    // THE MIGRATION ITSELF, so this notices it being deleted or broken.
    return require base_path('database/migrations/2026_10_13_100000_drop_english_from_translation_columns.php');
}

/** @return array<string, string> one text per language besides English */
function nonEnglishTexts(string $text): array
{
    return collect(translatedLocales())->mapWithKeys(fn (string $locale): array => [$locale => "{$text} ({$locale})"])->all();
}

it('drops the English entry of every translation column, keeping the base column and the other languages', function () {
    $category = Category::factory()->create(['name' => 'Renamed in English', 'name_translations' => ['en' => 'Old copy', ...nonEnglishTexts('Dogs')]]);
    $tag = Tag::factory()->create(['name' => 'zoomies', 'name_translations' => ['en' => 'zoomies']]);
    $group = RatingGroup::factory()->create(['label' => 'Is it fake or real?q', 'label_translations' => ['en' => 'Is it fake or real?', ...nonEnglishTexts('Real')], 'description_translations' => nonEnglishTexts('About')]);
    $option = RatingOption::factory()->create(['label' => 'Real', 'label_translations' => ['en' => 'Real', ...nonEnglishTexts('Real')]]);
    ProjectSettings::factory()->create(['site_name' => 'Pawrank', 'site_name_translations' => ['en' => 'Old name', ...nonEnglishTexts('Pawrank')]]);

    englishEntriesMigration()->up();

    expect($category->fresh())->name->toBe('Renamed in English')->name_translations->toBe(nonEnglishTexts('Dogs'))
        // A map that held English alone is left empty, the way the editors leave it.
        ->and($tag->fresh()->name_translations)->toBeNull()
        ->and($group->fresh())->label->toBe('Is it fake or real?q')->label_translations->toBe(nonEnglishTexts('Real'))->description_translations->toBe(nonEnglishTexts('About'))
        ->and($option->fresh()->label_translations)->toBe(nonEnglishTexts('Real'))
        ->and(ProjectSettings::findOrFail(1))->site_name->toBe('Pawrank')->site_name_translations->toBe(nonEnglishTexts('Pawrank'));
});

it('moves the English copy into a blank base column, which an English visitor saw until now', function () {
    ProjectSettings::factory()->create(['site_tagline' => '', 'site_tagline_translations' => ['en' => 'Rate everything', ...nonEnglishTexts('Rate')]]);

    englishEntriesMigration()->up();

    expect(ProjectSettings::findOrFail(1))->site_tagline->toBe('Rate everything')->site_tagline_translations->toBe(nonEnglishTexts('Rate'));
});

it('is safe to run again, and leaves alone what holds no English entry', function () {
    $translated = Category::factory()->create(['name' => 'Cats', 'name_translations' => nonEnglishTexts('Cats')]);
    $untranslated = Category::factory()->create(['name' => 'Birds', 'name_translations' => null]);
    $copy = Category::factory()->create(['name' => 'Fish', 'name_translations' => ['en' => 'Fish', ...nonEnglishTexts('Fish')]]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'update')) {
            $queries[] = $query->sql;
        }
    });

    englishEntriesMigration()->up();
    englishEntriesMigration()->up();

    expect($queries)->toHaveCount(1)
        ->and($translated->fresh()->name_translations)->toBe(nonEnglishTexts('Cats'))
        ->and($untranslated->fresh()->name_translations)->toBeNull()
        ->and($copy->fresh()->name_translations)->toBe(nonEnglishTexts('Fish'));
});
