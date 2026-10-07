<?php

use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\RepositoryTranslation;
use App\Support\Translations\RepositoryTranslations;

/**
 * The project content the repository ships — the presets' settings,
 * categories, rating groups, options and tags, and the static page defaults —
 * is English alone.
 *
 * A project translates its own content, in Translation Center, the same way
 * whichever language and whichever content: what a preset seeded and what an
 * administrator created alike. So no language is half-shipped in the
 * repository and half-made in a project, and adding a language never means
 * translating presets. TranslationParityTest holds the application catalogs,
 * which only a release can translate, to every installed language instead.
 */
function repositoryTranslations(): RepositoryTranslations
{
    return app(RepositoryTranslations::class);
}

it('ships every preset and static page in English alone', function () {
    $problems = repositoryTranslations()->translated();

    expect($problems)->toBe([], "repository content in a language besides English:\n".implode("\n", $problems));
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

it('names each value shipped in another language', function () {
    $other = unsupportedLocale();
    shipRepositoryContentTranslatedInto([$other]);

    expect(repositoryTranslations()->translated())
        ->toContain("project_presets.nature.settings.site_name has [{$other}] text")
        ->toContain("project_presets.nature.categories[landscape].name has [{$other}] text")
        ->toContain("project_presets.nature.rating_groups[photographer_type].label has [{$other}] text")
        ->toContain("project_presets.nature.rating_groups[photographer_type].options[professional].label has [{$other}] text")
        ->toContain("project_presets.nature.tags[sunrise].name has [{$other}] text")
        ->toContain("static-pages.defaults.about.content has [{$other}] text")
        // A blank value says nothing in any language.
        ->not->toContain("project_presets.generic.settings.site_description has [{$other}] text");
});

it('takes a plain-string value as English alone', function () {
    config(['project_presets' => ['custom' => ['settings' => ['site_name' => 'CustomGuru']]], 'static-pages.defaults' => []]);

    expect(repositoryTranslations()->translated())->toBe([]);
});
