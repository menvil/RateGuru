<?php

use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\RepositoryTranslation;
use App\Support\Translations\RepositoryTranslations;

/**
 * The project content the repository ships is translated into every installed
 * language before a release can merge.
 *
 * TranslationParityTest holds the application catalogs to that; this holds the
 * other half a language needs — the presets' settings, categories, rating
 * groups, options and tags, and the static page defaults. Without it a release
 * could add lang/{code}/ and forget the content, and the language would only
 * look finished.
 *
 * Only presence is checked. A name that reads the same in two languages — "HD",
 * "Anime" — is a translation like any other.
 */
function repositoryTranslations(): RepositoryTranslations
{
    return app(RepositoryTranslations::class);
}

it('ships every preset and static page in every installed language', function () {
    $problems = repositoryTranslations()->missing(supportedLocales());

    expect($problems)->toBe([], "repository content without a translation:\n".implode("\n", $problems));
});

it('reads every kind of content the presets and static pages ship', function () {
    $sections = collect([...array_merge(...array_values(repositoryTranslations()->presets())), ...repositoryTranslations()->staticPages()])
        ->map(fn (RepositoryTranslation $entry): ProjectContentSection => $entry->section)
        ->unique()
        ->values()
        ->all();

    expect($sections)->toEqualCanonicalizing(ProjectContentSection::cases())
        ->and(array_keys(repositoryTranslations()->presets()))->toBe(array_keys(config('project_presets')))
        ->and(count(repositoryTranslations()->staticPages()))->toBe(count(config('static-pages.defaults')) * 2);
});

it('names each value a newly declared language still owes', function () {
    $declared = unsupportedLocale();
    $problems = repositoryTranslations()->missing([$declared]);

    expect($problems)
        ->toContain("project_presets.nature.settings.site_name has no [{$declared}] text")
        ->toContain("project_presets.nature.categories[landscape].name has no [{$declared}] text")
        ->toContain("project_presets.nature.rating_groups[photographer_type].label has no [{$declared}] text")
        ->toContain("project_presets.nature.rating_groups[photographer_type].options[professional].label has no [{$declared}] text")
        ->toContain("project_presets.nature.tags[sunrise].name has no [{$declared}] text")
        ->toContain("static-pages.defaults.about.content has no [{$declared}] text")
        // A blank reference needs no translation.
        ->not->toContain("project_presets.generic.settings.site_description has no [{$declared}] text");
});

it('asks nothing of a value whose reference is blank', function () {
    config(['project_presets' => ['blank' => [
        'settings' => ['site_tagline' => ['en' => '   ']],
        'rating_groups' => [['key' => 'k', 'label' => ['en' => 'Label', 'zz' => 'Etikett'], 'description' => null, 'options' => []]],
    ]], 'static-pages.defaults' => []]);

    expect(repositoryTranslations()->missing(['zz']))->toBe([]);
});

it('treats a plain-string value as the reference text alone', function () {
    config(['project_presets' => ['custom' => ['settings' => ['site_name' => 'CustomGuru']]]]);

    expect(repositoryTranslations()->missing(['zz']))
        ->toContain('project_presets.custom.settings.site_name has no [zz] text');
});
