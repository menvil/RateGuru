<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Locale\LocaleManager;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationRequestFactory;
use App\Support\Translations\ProjectTranslationUnit;

/**
 * Project content described to the translation engine: one logical batch of
 * the catalog's units, each with its English text, its limits, its
 * placeholders, where a visitor meets it and what other languages store — all
 * read from the unit itself, nothing from a caller.
 */
function translationRequestFactory(): ProjectTranslationRequestFactory
{
    return app(ProjectTranslationRequestFactory::class);
}

function catalogUnit(string $id): ProjectTranslationUnit
{
    $unit = app(ProjectTranslationCatalog::class)->find($id);

    expect($unit)->not->toBeNull("the catalog lists no unit {$id}");

    return $unit;
}

/** The one item a single unit becomes. */
function requestItemFor(string $id, string $target): TranslationItem
{
    return translationRequestFactory()->make([catalogUnit($id)], $target)->items[0];
}

beforeEach(function () {
    ProjectSettings::factory()->create(['site_tagline' => 'Rate anything', 'site_tagline_translations' => null]);
});

afterEach(fn () => removeCatalogScratchDirectory($this));

it('describes a unit of every section to the engine', function (Closure $unit, string $contentType, int $max, bool $multiline, string $usage) {
    [$target] = twoTranslatedLocales();
    $id = $unit();
    $found = catalogUnit($id);

    $request = translationRequestFactory()->make([$found], $target);
    $item = $request->items[0];

    expect($request->targetLocale)->toBe($target)
        ->and($request->dataClassification)->toBe(TranslationDataClassification::PublicContent)
        ->and($request->glossary)->toBe([])
        ->and($request->items)->toHaveCount(1)
        ->and($item->id)->toBe($id)
        ->and($item->sourceLocale)->toBe('en')
        ->and($item->sourceText)->toBe($found->reference)
        ->and($item->contentType)->toBe($contentType)
        ->and($item->maxLength)->toBe($max)
        ->and($item->multiline)->toBe($multiline)
        ->and($item->placeholders)->toBe($found->placeholders())
        ->and($item->context)->toBe(implode("\n", [
            "Section: {$found->section->label()}",
            "Entity: {$found->label}",
            "Field: {$found->fieldName()}",
            "Business key: {$found->qualifiedKey()}",
            "Usage: {$usage}",
        ]));
})->with([
    'a project setting' => [
        fn () => 'project_settings:site_tagline',
        'project_settings.site_tagline', 180, false, 'The project’s tagline: one short line saying what the site is about.',
    ],
    'a static page' => [
        fn () => 'static_pages:about:title',
        'static_pages.title', 160, false, 'The title of the About page.',
    ],
    'a category' => [
        fn () => 'categories:'.Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'is_active' => true])->id.':name',
        'categories.name', 80, false, 'The category’s name on posts and in the upload form.',
    ],
    'a tag' => [
        fn () => 'tags:'.Tag::factory()->create(['slug' => 'zoomies', 'name' => 'zoomies'])->id.':name',
        'tags.name', 80, false, 'The tag’s name on posts and in the feed’s tag tabs.',
    ],
    'a rating group' => [
        fn () => 'rating_groups:'.RatingGroup::factory()->create(['key' => 'vibe', 'label' => 'Vibe', 'description' => 'What is this pet like?', 'is_active' => true])->id.':description',
        'rating_groups.description', 1000, true, 'Stored with the rating group: what it asks voters about.',
    ],
    'a rating option' => [
        fn () => 'rating_options:'.RatingOption::factory()->for(RatingGroup::factory()->create(['key' => 'vibe', 'label' => 'Vibe', 'is_active' => true]), 'group')
            ->create(['key' => 'calm', 'label' => 'Calm', 'is_active' => true])->id.':label',
        'rating_options.label', 120, false, 'A vote button in the Vibe rating group on a post.',
    ],
]);

it('names the kind of text, never the record it belongs to', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'is_active' => true]);

    $contentType = requestItemFor("categories:{$category->id}:name", $target)->contentType;

    expect($contentType)->toBe('categories.name')
        ->not->toContain((string) $category->id)
        ->not->toContain('dogs');

    expect(requestItemFor('static_pages:contact:content', $target)->contentType)->toBe('static_pages.content');
});

it('carries a placeholder of the English text exactly as the catalog finds it', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['name' => 'Write to {contact_email}, {name}', 'is_active' => true]);

    expect(requestItemFor("categories:{$category->id}:name", $target)->placeholders)->toBe(['{contact_email}', '{name}']);
});

// Other languages as context ---------------------------------------------------------

it('passes what the other installed languages store as context, never English or the target', function () {
    [$target, $other] = twoTranslatedLocales();
    $category = Category::factory()->create([
        'name' => 'Dogs',
        'name_translations' => ['en' => 'Dogs', $target => 'already there', $other => 'Кучета'],
        'is_active' => true,
    ]);

    expect(requestItemFor("categories:{$category->id}:name", $target)->existingTranslations)->toBe([$other => 'Кучета']);
});

it('passes a disabled installed language too, since context does not need visitors', function () {
    [$target, $other] = twoTranslatedLocales();
    offerLocales([$target]);
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$other => 'Кучета'], 'is_active' => true]);

    expect(app(LocaleManager::class)->isEnabled($other))->toBeFalse()
        ->and(requestItemFor("categories:{$category->id}:name", $target)->existingTranslations)->toBe([$other => 'Кучета']);
});

it('leaves out a stale language, a blank value and anything that is not text', function () {
    [$target, $other] = twoTranslatedLocales();
    $category = Category::factory()->create([
        'name' => 'Dogs',
        'name_translations' => ['xx' => 'uninstalled', 'zz-legacy' => 'stale', $other => '   '],
        'is_active' => true,
    ]);
    $tag = Tag::factory()->create(['name' => 'zoomies', 'name_translations' => [$other => ['not' => 'text']]]);

    expect(requestItemFor("categories:{$category->id}:name", $target)->existingTranslations)->toBe([])
        ->and(requestItemFor("tags:{$tag->id}:name", $target)->existingTranslations)->toBe([]);
});

it('keeps the installed order of the languages it passes', function () {
    $codes = installLanguagesUpTo(8);
    $target = $codes[1];
    $translations = [];

    foreach (array_reverse(array_slice($codes, 2)) as $code) {
        $translations[$code] = "Dogs in {$code}";
    }

    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => $translations, 'is_active' => true]);

    expect(array_keys(requestItemFor("categories:{$category->id}:name", $target)->existingTranslations))->toBe(array_slice($codes, 2));
});

it('bounds the context so a long text stored in many languages can still be sent, leaving languages out whole', function () {
    $codes = installLanguagesUpTo(6);
    $target = $codes[1];
    [$first, $second, $third] = array_slice($codes, 2, 3);
    $long = str_repeat('Ein langer Absatz. ', 600);   // 11,400 characters
    $short = 'Kurz.';

    ProjectSettings::query()->firstOrFail()->update(['static_pages' => array_replace_recursive(config('static-pages.defaults'), [
        'about' => [
            $first => ['title' => 'About', 'content' => $long],
            $second => ['title' => 'About', 'content' => $long],
            $third => ['title' => 'About', 'content' => $short],
        ],
    ])]);

    $context = requestItemFor('static_pages:about:content', $target)->existingTranslations;

    // The second long translation would pass the budget, so it is left out whole; the short one after it still fits.
    expect(array_keys($context))->toBe([$first, $third])
        ->and($context[$first])->toBe($long)
        ->and(array_sum(array_map(mb_strlen(...), $context)))->toBeLessThanOrEqual(ProjectTranslationRequestFactory::CONTEXT_TRANSLATIONS_BUDGET);
});

// A batch of many ---------------------------------------------------------------------

it('turns several units into one logical batch, in the order given', function () {
    [$target] = twoTranslatedLocales();
    $dogs = Category::factory()->create(['name' => 'Dogs', 'is_active' => true]);
    $tag = Tag::factory()->create(['name' => 'zoomies']);
    $ids = ["tags:{$tag->id}:name", 'project_settings:site_tagline', "categories:{$dogs->id}:name", 'static_pages:about:title'];

    $request = translationRequestFactory()->make(array_map(catalogUnit(...), $ids), $target);

    expect($request->itemIds())->toBe($ids)
        ->and($request->targetLocale)->toBe($target)
        ->and($request->dataClassification)->toBe(TranslationDataClassification::PublicContent);
});

it('names the target so a translator cannot mistake its script or variant', function (string $installed, string $tag) {
    $tagline = ProjectSettings::findOrFail(1);
    $tagline->update(['site_tagline_translations' => [$installed => 'Stored in the target', 'de' => 'Bewerte alles']]);

    $request = translationRequestFactory()->make([catalogUnit('project_settings:site_tagline')], $installed);

    // Its own stored text is still told apart by the installed code: never context.
    expect($request->targetLocale)->toBe($tag)
        ->and($request->items[0]->existingTranslations)->not->toHaveKey($installed)
        ->and($request->items[0]->existingTranslations)->not->toHaveKey($tag);
})->with([
    'Serbian, in Cyrillic' => ['sr', 'sr-Cyrl'],
    'Montenegrin, in Latin' => ['cnr', 'cnr-Latn'],
    'Brazilian Portuguese' => ['pt', 'pt-BR'],
    'Simplified Chinese' => ['zh', 'zh-Hans'],
    'a language its code pins down' => ['de', 'de'],
]);

it('takes a glossary from its caller and passes it on unchanged', function () {
    [$target] = twoTranslatedLocales();

    $request = translationRequestFactory()->make([catalogUnit('project_settings:site_tagline')], $target, ['RateGuru' => 'RateGuru']);

    expect($request->glossary)->toBe(['RateGuru' => 'RateGuru']);
});
