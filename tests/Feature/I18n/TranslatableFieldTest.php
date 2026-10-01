<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\TranslatableField;
use Illuminate\Support\Str;

/**
 * A visitor gets a stored translation only when it is one — the same rule the
 * Languages page counts missing translations by. Anything else falls back to
 * the reference text, so "visitors may see fallback content" is what happens,
 * never blank text or a value that is not text.
 */

/**
 * What a visitor reading this language is shown for a piece of content whose
 * reference text is "Reference text" and whose translation is stored as given
 * — read back from the database, through the accessor the site renders with.
 */
function visitorText(string $content, string $locale, mixed $translation): string
{
    $translations = [$locale => $translation];

    return match ($content) {
        'category' => Category::factory()->create(['name' => 'Reference text', 'name_translations' => $translations])->fresh()->translatedName($locale),
        'tag' => Tag::factory()->create(['name' => 'Reference text', 'name_translations' => $translations])->fresh()->translatedName($locale),
        'rating group' => RatingGroup::factory()->create(['label' => 'Reference text', 'label_translations' => $translations])->fresh()->translatedLabel($locale),
        'rating option' => RatingOption::factory()->for(RatingGroup::factory(), 'group')->create(['label' => 'Reference text', 'label_translations' => $translations])->fresh()->translatedLabel($locale),
        'static page' => visitorStaticPageTitle($locale, $translation),
        default => visitorProjectSetting($content, $locale, $translations),
    };
}

function visitorProjectSetting(string $field, string $locale, array $translations): string
{
    ProjectSettings::factory()->create([$field => 'Reference text', "{$field}_translations" => $translations]);
    app(ProjectSettingsManager::class)->flush();
    app()->setLocale($locale);

    return (string) app(ProjectSettingsManager::class)->current()->{Str::camel($field)}();
}

function visitorStaticPageTitle(string $locale, mixed $translation): string
{
    // Stored English and configured text of this language both out of the
    // way, so the stored value is all that stands between the visitor and the
    // English fallback.
    config(["static-pages.defaults.about.{$locale}" => []]);
    ProjectSettings::factory()->create(['static_pages' => ['about' => [
        'en' => ['title' => 'Reference text', 'content' => 'Reference content'],
        $locale => ['title' => $translation, 'content' => 'Translated content'],
    ]]]);
    app(ProjectSettingsManager::class)->flush();
    app()->setLocale($locale);

    return app(ProjectSettingsManager::class)->current()->staticPage('about')['title'];
}

dataset('visitor-facing content', [
    'category name' => ['category'],
    'tag name' => ['tag'],
    'rating group label' => ['rating group'],
    'rating option label' => ['rating option'],
    'static page title' => ['static page'],
    ...array_combine(
        array_map(fn (string $field): string => "project setting {$field}", PresetSettingsBuilder::TRANSLATABLE),
        array_map(fn (string $field): array => [$field], PresetSettingsBuilder::TRANSLATABLE),
    ),
]);

it('falls back to the reference text where the stored value is not a translation', function (mixed $value) {
    [$target] = twoTranslatedLocales();

    expect(TranslatableField::isPresent($value))->toBeFalse()
        ->and(TranslatableField::resolve([$target => $value], 'Reference text', $target))->toBe('Reference text');
})->with('not a translation');

it('serves a translation that is text', function () {
    [$target] = twoTranslatedLocales();

    expect(TranslatableField::resolve([$target => 'Normal'], 'Reference text', $target))->toBe('Normal');
});

it('falls back when the language has no entry or nothing is stored at all', function (mixed $translations) {
    [$target] = twoTranslatedLocales();

    expect(TranslatableField::resolve($translations, 'Reference text', $target))->toBe('Reference text');
})->with([
    'another language only' => [['en' => 'English']],
    'no translations' => [null],
    'not a map of languages' => ['Normal'],
]);

it('reads the current language when it is given none', function () {
    [$target, $other] = twoTranslatedLocales();
    app()->setLocale($target);

    expect(TranslatableField::resolve([$target => 'Normal', $other => 'Other'], 'Reference text'))->toBe('Normal');
});

it('shows a visitor the reference text wherever the stored translation is not one', function (string $content, mixed $value) {
    [$target] = twoTranslatedLocales();

    expect(visitorText($content, $target, $value))->toBe('Reference text');
})->with('visitor-facing content')->with('not a translation');

it('shows a visitor the stored translation wherever it is one', function (string $content) {
    [$target] = twoTranslatedLocales();

    expect(visitorText($content, $target, 'Normal'))->toBe('Normal');
})->with('visitor-facing content');

it('serves visitors the database text, never what the repository ships now', function () {
    // Content a preset seeded is the project's from then on. A later release
    // that drops every preset and rewrites the static pages changes nothing a
    // visitor reads.
    [$target] = twoTranslatedLocales();
    app(ApplyProjectPresetAction::class)->handle('nature');
    offerEveryInstalledLocale();

    $read = function () use ($target): array {
        app(ProjectSettingsManager::class)->flush();
        app()->setLocale($target);
        $settings = app(ProjectSettingsManager::class)->current();

        return [
            'category' => Category::query()->where('slug', 'landscape')->sole()->translatedName($target),
            'tag' => Tag::query()->where('slug', 'sunrise')->sole()->translatedName($target),
            'rating group' => RatingGroup::query()->where('key', 'photographer_type')->sole()->translatedLabel($target),
            'rating option' => RatingOption::query()->where('key', 'professional')->sole()->translatedLabel($target),
            'project setting' => $settings->siteName(),
            'static page' => $settings->staticPage('about')['title'],
        ];
    };
    $before = $read();

    config([
        'project_presets' => [],
        'static-pages.defaults.about' => [
            'en' => ['title' => 'Rewritten in a later release', 'content' => 'Rewritten.'],
            $target => ['title' => 'Translated anew in a later release', 'content' => 'Translated anew.'],
        ],
    ]);

    expect($read())->toBe($before)
        ->and($before['static page'])->not->toBe('Translated anew in a later release');
});
