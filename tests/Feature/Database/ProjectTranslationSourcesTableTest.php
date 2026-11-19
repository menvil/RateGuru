<?php

use App\Models\ProjectTranslationSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * project_translation_sources: one record per unit and language of the
 * English its translation was made from — added on its own, and taken away
 * on its own, touching nothing else.
 */
function translationSourceRow(string $unit = 'categories:1:name', string $locale = 'de'): array
{
    return ['unit_id' => $unit, 'locale' => $locale, 'fingerprint' => str_repeat('a', 64), 'reference' => str_repeat('Ж', 20_000)];
}

it('keeps one record per unit and language, long enough for a whole static page', function () {
    ProjectTranslationSource::query()->create(translationSourceRow());
    ProjectTranslationSource::query()->create(translationSourceRow(locale: 'ru'));

    expect(ProjectTranslationSource::query()->where('locale', 'de')->value('reference'))->toBe(str_repeat('Ж', 20_000))
        ->and(fn () => ProjectTranslationSource::query()->create(translationSourceRow()))->toThrow(QueryException::class);
});

it('drops its table on the way down, and makes it again on the way up', function () {
    $migration = require database_path('migrations/2026_11_17_101300_create_project_translation_sources_table.php');

    $migration->down();

    expect(Schema::hasTable('project_translation_sources'))->toBeFalse()
        ->and(Schema::hasTable('categories'))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumns('project_translation_sources', ['unit_id', 'locale', 'fingerprint', 'reference', 'created_at', 'updated_at']))->toBeTrue()
        ->and(array_column(Schema::getIndexes('project_translation_sources'), 'name'))->toContain('project_translation_sources_unit_locale_unique');
});
