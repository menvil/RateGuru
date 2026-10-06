<?php

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Exceptions\Translations\CannotSaveTranslationException;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\ProjectTranslationUnit;
use App\Support\Translations\TranslatableField;
use Illuminate\Support\Facades\DB;

/**
 * Saving one language's translation of one unit: found again in the catalog,
 * held to the unit's own limits, and written where the content keeps its
 * translations — that language's entry and nothing else.
 */
function saveTranslation(mixed $unit, mixed $locale, mixed $text, ?User $actor = null): ProjectTranslationUnit
{
    return app(UpdateProjectTranslationAction::class)->handle($actor ?? User::factory()->admin()->create(), $unit, $locale, $text);
}

/** The reason a save is refused, or null when it is not. */
function refusalOf(Closure $save): ?string
{
    try {
        $save();
    } catch (CannotSaveTranslationException $exception) {
        return $exception->reason.': '.$exception->getMessage();
    }

    return null;
}

beforeEach(function () {
    ProjectSettings::factory()->create();
});

// Writing ------------------------------------------------------------------------

it('saves a translation a language did not have', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    $saved = saveTranslation("categories:{$category->id}:name", $target, 'Грузинская кухня');

    expect($category->fresh()->name_translations)->toBe([$target => 'Грузинская кухня'])
        ->and($saved->translation($target))->toBe('Грузинская кухня')
        ->and($saved->id)->toBe("categories:{$category->id}:name");
});

it('replaces the translation a language had', function () {
    [$target] = twoTranslatedLocales();
    $tag = Tag::factory()->create(['name' => 'zoomies', 'name_translations' => [$target => 'old']]);

    saveTranslation("tags:{$tag->id}:name", $target, 'new');

    expect($tag->fresh()->name_translations)->toBe([$target => 'new']);
});

it('keeps every other language as it is, English included', function () {
    [$target, $other] = twoTranslatedLocales();
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => ['en' => 'Dogs', $other => 'Hunde', $target => 'old'], 'is_active' => true]);
    ProjectSettings::query()->update(['site_name_translations' => json_encode(['en' => 'RateGuru', $other => 'Other'])]);

    saveTranslation("categories:{$category->id}:name", $target, 'new');
    saveTranslation('project_settings:site_name', $target, 'Name');

    expect($category->fresh()->name_translations)->toBe(['en' => 'Dogs', $other => 'Hunde', $target => 'new'])
        ->and(ProjectSettings::findOrFail(1)->site_name_translations)->toBe(['en' => 'RateGuru', $other => 'Other', $target => 'Name'])
        ->and($category->fresh()->name)->toBe('Dogs');
});

it('makes a language missing again for blank text, storing an empty map as null', function (string $blank) {
    [$target, $other] = twoTranslatedLocales();
    $alone = Category::factory()->create(['name_translations' => [$target => 'Text'], 'is_active' => true]);
    $shared = Category::factory()->create(['name_translations' => [$target => 'Text', $other => 'Other'], 'is_active' => true]);

    $saved = saveTranslation("categories:{$alone->id}:name", $target, $blank);
    saveTranslation("categories:{$shared->id}:name", $target, $blank);

    expect($alone->fresh()->name_translations)->toBeNull()
        ->and($shared->fresh()->name_translations)->toBe([$other => 'Other'])
        ->and($saved->translation($target))->toBeNull()
        ->and($saved->isTranslatedInto($target))->toBeFalse();
})->with(['empty' => [''], 'spaces' => ['   '], 'line breaks' => ["\n\r\n"]]);

it('trims the text it stores', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    saveTranslation("categories:{$category->id}:name", $target, "  Кухня \n");

    expect($category->fresh()->name_translations)->toBe([$target => 'Кухня']);
});

it('writes a rating group and a rating option field by field', function () {
    [$target] = twoTranslatedLocales();
    $group = RatingGroup::factory()->create(['description' => 'What?', 'label_translations' => [$target => 'Label'], 'is_active' => true]);
    $option = RatingOption::factory()->for($group, 'group')->create(['description' => "Line one\nline two", 'is_active' => true]);

    saveTranslation("rating_groups:{$group->id}:description", $target, 'Что?');
    saveTranslation("rating_options:{$option->id}:description", $target, "Строка\r\nвторая");

    expect($group->fresh()->label_translations)->toBe([$target => 'Label'])
        ->and($group->fresh()->description_translations)->toBe([$target => 'Что?'])
        ->and($option->fresh()->description_translations)->toBe([$target => "Строка\nвторая"])
        ->and($option->fresh()->label_translations)->toBeNull();
});

it('writes one field of one language of one static page, leaving the rest of the pages as they were', function () {
    [$target, $other] = twoTranslatedLocales();
    $before = ProjectSettings::findOrFail(1)->static_pages;

    saveTranslation('static_pages:about:title', $target, 'Новый заголовок');

    $after = ProjectSettings::findOrFail(1)->static_pages;
    $expected = $before;
    $expected['about'][$target]['title'] = 'Новый заголовок';

    expect($after)->toEqual($expected)
        ->and($after['about'][$target]['content'])->toBe($before['about'][$target]['content'])
        ->and($after['about'][$other])->toBe($before['about'][$other])
        ->and($after['about']['en'])->toBe($before['about']['en']);
});

it('removes a page language left without a field, and nothing else', function () {
    [$target] = twoTranslatedLocales();
    $before = ProjectSettings::findOrFail(1)->static_pages;

    saveTranslation('static_pages:about:title', $target, '');

    expect(ProjectSettings::findOrFail(1)->static_pages['about'][$target])->toBe(['content' => $before['about'][$target]['content']]);

    saveTranslation('static_pages:about:content', $target, ' ');

    $after = ProjectSettings::findOrFail(1)->static_pages;
    $expected = $before;
    unset($expected['about'][$target]);

    expect($after['about'])->not->toHaveKey($target)
        ->and($after)->toEqual($expected);
});

it('creates the settings row from the bootstrap when an installation has none yet', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::query()->delete();

    saveTranslation('project_settings:site_tagline', $target, 'Оценивайте всё');

    $row = ProjectSettings::findOrFail(1);

    expect($row->site_tagline_translations)->toBe([$target => 'Оценивайте всё'])
        ->and($row->site_name)->toBe('RateGuru')
        ->and($row->static_pages)->toEqual(config('static-pages.defaults'));
});

it('serves a saved project setting at once, without a stale copy', function () {
    [$target] = twoTranslatedLocales();
    $settings = app(ProjectSettingsManager::class);
    app()->setLocale($target);

    expect($settings->current()->siteTagline())->toBe('Rate anything');

    saveTranslation('project_settings:site_tagline', $target, 'Оценивайте всё');

    expect($settings->current()->siteTagline())->toBe('Оценивайте всё');
});

it('translates a language that is installed but not offered yet', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    $category = untranslatedCategory();

    saveTranslation("categories:{$category->id}:name", $withheld, 'Text');

    expect(offeredLocales())->not->toContain($withheld)
        ->and($category->fresh()->name_translations)->toBe([$withheld => 'Text']);
});

it('reads and writes under a lock on the row it changes', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    saveTranslation("categories:{$category->id}:name", $target, 'Text');
    saveTranslation('project_settings:site_name', $target, 'Name');

    $locked = collect($statements)->filter(fn (string $sql): bool => preg_match('/for update/i', $sql) === 1);

    // SQLite has no row locks; its grammar leaves the clause out.
    expect($locked)->toHaveCount(DB::getDriverName() === 'sqlite' ? 0 : 2);
});

// Refusing -----------------------------------------------------------------------

it('refuses a text over the field\'s limit, saying by how much, and writes nothing', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['name_translations' => [$target => 'Kept'], 'is_active' => true]);

    expect(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $target, str_repeat('я', 84))))->toBe('too_long: 4 over the limit')
        // Code points, not bytes: 80 Cyrillic letters fit.
        ->and(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $target, str_repeat('я', 80))))->toBeNull();

    $category->fresh()->update(['name_translations' => [$target => 'Kept']]);

    expect(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $target, str_repeat('x', 81))))->toBe('too_long: 1 over the limit')
        ->and($category->fresh()->name_translations)->toBe([$target => 'Kept']);
});

it('refuses a text that loses a placeholder of the English text, and writes nothing', function () {
    [$target] = twoTranslatedLocales();
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['contact']['en']['content'] = 'Write to {contact_email} about {site_name}.';
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);

    expect(refusalOf(fn () => saveTranslation('static_pages:contact:content', $target, 'Пишите нам о {site_name}.')))->toBe('placeholder_missing: Keep {contact_email}')
        ->and(refusalOf(fn () => saveTranslation('static_pages:contact:content', $target, 'Пишите нам.')))->toBe('placeholder_missing: Keep {contact_email}, {site_name}')
        ->and(ProjectSettings::findOrFail(1)->static_pages)->toEqual($pages)
        ->and(refusalOf(fn () => saveTranslation('static_pages:contact:content', $target, '{site_name}: {contact_email}')))->toBeNull();
});

it('refuses a line break in a single-line field, and takes one in a multiline field', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    expect(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $target, "One\ntwo")))->toBe('line_break: Remove the line break: this text is a single line')
        ->and($category->fresh()->name_translations)->toBeNull()
        ->and(refusalOf(fn () => saveTranslation('project_settings:site_description', $target, "One\ntwo")))->toBe('nothing_to_translate: The English text is empty, so there is nothing to translate.');

    ProjectSettings::query()->update(['site_description' => 'About']);

    expect(refusalOf(fn () => saveTranslation('project_settings:site_description', $target, "One\ntwo")))->toBeNull();
});

it('refuses a unit whose English text is blank: there is nothing to translate', function () {
    [$target] = twoTranslatedLocales();
    $group = RatingGroup::factory()->create(['description' => '  ', 'is_active' => true]);

    expect(refusalOf(fn () => saveTranslation("rating_groups:{$group->id}:description", $target, 'Text')))->toStartWith('nothing_to_translate')
        ->and($group->fresh()->description_translations)->toBeNull();
});

it('removes a translation left behind once the English text is cleared, and refuses new text for it', function (string $blank) {
    [$target, $other] = twoTranslatedLocales();
    $group = RatingGroup::factory()->create(['description' => 'What is it like?', 'description_translations' => [$target => 'Какой он?', $other => 'Какъв е?'], 'is_active' => true]);
    $unit = "rating_groups:{$group->id}:description";
    $group->update(['description' => null]);
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['about']['en']['content'] = '';
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);

    // The stale translation is still stored, and still what a visitor of that language is served.
    expect($group->fresh()->description_translations[$target])->toBe('Какой он?')
        ->and(TranslatableField::resolve($group->fresh()->description_translations, '', $target))->toBe('Какой он?');

    $removed = saveTranslation($unit, $target, $blank);
    saveTranslation('static_pages:about:content', $target, $blank);

    expect($group->fresh()->description_translations)->toBe([$other => 'Какъв е?'])
        ->and($removed->translation($target))->toBeNull()
        ->and(ProjectSettings::findOrFail(1)->static_pages['about'][$target])->toBe(['title' => $pages['about'][$target]['title']]);

    // New text still needs English to translate.
    expect(refusalOf(fn () => saveTranslation($unit, $other, 'Нов текст')))->toBe('nothing_to_translate: The English text is empty, so there is nothing to translate.')
        ->and(refusalOf(fn () => saveTranslation('static_pages:about:content', $target, 'Новый текст')))->toStartWith('nothing_to_translate')
        ->and($group->fresh()->description_translations)->toBe([$other => 'Какъв е?']);
})->with(['empty' => [''], 'spaces' => ['  ']]);

it('refuses an id that names nothing the catalog lists now', function (Closure $unit) {
    [$target] = twoTranslatedLocales();
    $id = $unit();
    $before = Category::query()->orderBy('id')->pluck('name_translations', 'id')->all();

    expect(refusalOf(fn () => saveTranslation($id, $target, 'Text')))->toStartWith('unknown_unit')
        ->and(Category::query()->orderBy('id')->pluck('name_translations', 'id')->all())->toBe($before);
})->with([
    'nothing at all' => [fn (): mixed => null],
    'not a string' => [fn (): mixed => ['categories', 1]],
    'malformed' => [fn (): string => 'categories::name'],
    'a field that is not translated' => [fn (): string => 'categories:'.untranslatedCategory()->id.':slug'],
    'a deleted category' => [function (): string {
        $category = untranslatedCategory();
        $category->delete();

        return "categories:{$category->id}:name";
    }],
    'an inactive category' => [fn (): string => 'categories:'.Category::factory()->create(['is_active' => false])->id.':name'],
    'an archived option' => [fn (): string => 'rating_options:'.RatingOption::factory()->create(['archived_at' => now()])->id.':label'],
    'a static page that is not built in' => [fn (): string => 'static_pages:imprint:title'],
]);

it('refuses a language that is not installed, English, and anything that is not a language', function (mixed $locale, string $reason) {
    $category = untranslatedCategory();

    expect(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $locale, 'Text')))->toStartWith($reason)
        ->and($category->fresh()->name_translations)->toBeNull();
})->with([
    'not installed' => ['xx', 'unknown_locale'],
    'empty' => ['', 'unknown_locale'],
    'not a string' => [['de'], 'unknown_locale'],
    'nothing' => [null, 'unknown_locale'],
    'English, the reference' => ['en', 'reference_locale'],
]);

it('refuses a text that is not text', function (mixed $text) {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    expect(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $target, $text)))->toStartWith('not_text')
        ->and($category->fresh()->name_translations)->toBeNull();
})->with(['null' => [null], 'a number' => [42], 'a list' => [['Text']]]);

it('refuses anyone who may not manage project settings, and writes nothing', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    foreach ([User::factory()->moderator()->create(), User::factory()->create()] as $actor) {
        expect(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $target, 'Text', $actor)))->toStartWith('not_allowed');
    }

    expect($category->fresh()->name_translations)->toBeNull();
});

it('takes the section, record and limits from the catalog, whatever a forged id claims', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $tag = Tag::factory()->create(['id' => $category->id + 1000, 'name' => 'Tag', 'name_translations' => null]);

    // The same record id under another section is another unit: the tag, never the category.
    saveTranslation("tags:{$tag->id}:name", $target, 'Метка');

    expect($tag->fresh()->name_translations)->toBe([$target => 'Метка'])
        ->and($category->fresh()->name_translations)->toBeNull()
        // A multiline limit claimed for a single-line field changes nothing.
        ->and(refusalOf(fn () => saveTranslation("categories:{$category->id}:name", $target, str_repeat('x', 1000))))->toStartWith('too_long');
});
