<?php

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\ProjectTranslationSource;
use App\Models\User;
use App\Queries\Translations\ProjectTranslationSourcesQuery;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;

/**
 * An outdated translation: stored, made from English the unit no longer has,
 * as the English recorded with it says. Nothing recorded is not known to be
 * outdated; nothing stored is missing.
 */
function categoryUnit(Category $category): ProjectTranslationUnit
{
    return app(ProjectTranslationCatalog::class)->find("categories:{$category->id}:name");
}

/** Whether the category's translation into $locale is outdated, by what is recorded for it now. */
function isOutdatedCategory(Category $category, string $locale): bool
{
    $unit = categoryUnit($category);

    return ProjectTranslationSourceFingerprint::isOutdated($unit, $locale, app(ProjectTranslationSourcesQuery::class)->fingerprints($locale, [$unit->id])[$unit->id] ?? null);
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    [$this->target, $this->other] = twoTranslatedLocales();
});

it('is outdated once the English it was made from changes, in that language only', function () {
    $category = untranslatedCategory('Welcome');
    $unit = "categories:{$category->id}:name";
    app(UpdateProjectTranslationAction::class)->handle(User::factory()->admin()->create(), $unit, $this->target, 'Willkommen');

    expect(isOutdatedCategory($category, $this->target))->toBeFalse();

    $category->update(['name' => 'Hello']);

    expect(isOutdatedCategory($category, $this->target))->toBeTrue()
        // The old translation is still stored, and still what visitors of that language see.
        ->and(categoryUnit($category)->translation($this->target))->toBe('Willkommen')
        // Another language with nothing stored is missing, not outdated.
        ->and(isOutdatedCategory($category, $this->other))->toBeFalse();
});

it('is current again once it is saved for the new English, changed or as it was', function () {
    $category = outdatedCategory($this->target);

    app(UpdateProjectTranslationAction::class)->handle(User::factory()->admin()->create(), "categories:{$category->id}:name", $this->target, 'Willkommen');

    expect(isOutdatedCategory($category, $this->target))->toBeFalse();
});

it('is current again when the English changes back to what it was made from', function () {
    $category = outdatedCategory($this->target, 'Willkommen', 'Welcome', 'Hello');

    $category->update(['name' => 'Welcome']);

    expect(isOutdatedCategory($category, $this->target))->toBeFalse();
});

it('is not known to be outdated when nothing is recorded of its English', function () {
    $category = Category::factory()->create(['name' => 'Welcome', 'name_translations' => [$this->target => 'Willkommen'], 'is_active' => true]);
    $category->update(['name' => 'Hello']);

    expect(ProjectTranslationSource::query()->count())->toBe(0)
        ->and(isOutdatedCategory($category, $this->target))->toBeFalse();
});

it('is missing, not outdated, once its translation is gone, whatever is recorded', function () {
    $category = outdatedCategory($this->target);

    // Removed some other way than Translation Center's writer, which would forget the record too.
    $category->update(['name_translations' => null]);

    expect(translationSourceOf("categories:{$category->id}:name", $this->target))->not->toBeNull()
        ->and(isOutdatedCategory($category, $this->target))->toBeFalse();
});

it('reads what is recorded for a language, by unit, in one query each way', function () {
    $outdated = outdatedCategory($this->target, 'Willkommen', 'Welcome', 'Hello');
    $current = untranslatedCategory('Cats');
    $admin = User::factory()->admin()->create();
    app(UpdateProjectTranslationAction::class)->handle($admin, "categories:{$current->id}:name", $this->target, 'Katzen');
    app(UpdateProjectTranslationAction::class)->handle($admin, "categories:{$current->id}:name", $this->other, 'Котки');
    $query = app(ProjectTranslationSourcesQuery::class);
    [$outdatedUnit, $currentUnit] = ["categories:{$outdated->id}:name", "categories:{$current->id}:name"];

    $records = $query->forLocale($this->target);

    expect(array_keys($records))->toEqualCanonicalizing([$outdatedUnit, $currentUnit])
        ->and($records[$outdatedUnit]->reference)->toBe('Welcome')
        ->and($records[$outdatedUnit]->fingerprint)->toBe(ProjectTranslationSourceFingerprint::for($outdatedUnit, 'Welcome'))
        ->and($query->fingerprints($this->target, [$currentUnit, 'categories:999999:name']))->toBe([$currentUnit => ProjectTranslationSourceFingerprint::of(categoryUnit($current))])
        ->and($query->fingerprints($this->other))->toBe([$currentUnit => ProjectTranslationSourceFingerprint::of(categoryUnit($current))])
        ->and($query->fingerprints($this->target, []))->toBe([]);
});
