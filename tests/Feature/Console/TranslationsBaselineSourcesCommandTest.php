<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\ProjectTranslationSource;
use App\Models\RatingGroup;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use Illuminate\Console\Scheduling\Schedule;

/**
 * translations:baseline-sources gives every stored project translation with
 * nothing recorded of its English the English it stands beside now — and
 * never replaces a record, which would hide that the English changed.
 */
beforeEach(function () {
    ProjectSettings::factory()->create(['site_tagline' => 'Rate anything', 'site_tagline_translations' => null]);
    [$this->target, $this->other] = twoTranslatedLocales();
});

it('records the English of every stored translation that has none, in every language that stores one', function () {
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$this->target => 'Hunde', $this->other => 'Кучета'], 'is_active' => true]);
    ProjectSettings::query()->update(['site_tagline_translations' => json_encode([$this->target => 'Bewerte alles'])]);
    $unit = "categories:{$category->id}:name";

    $this->artisan('translations:baseline-sources')
        ->expectsOutput('Recorded the English of 3 project translations that had none recorded.')
        ->assertSuccessful();

    expect(translationSourceOf($unit, $this->target))->toBe(['fingerprint' => ProjectTranslationSourceFingerprint::for($unit, 'Dogs'), 'reference' => 'Dogs'])
        ->and(translationSourceOf($unit, $this->other)['reference'])->toBe('Dogs')
        ->and(translationSourceOf('project_settings:site_tagline', $this->target)['reference'])->toBe('Rate anything')
        ->and(translationSourceOf('project_settings:site_tagline', $this->other))->toBeNull();
});

it('never replaces a record: an outdated translation stays outdated', function () {
    $category = outdatedCategory($this->target, 'Willkommen', 'Welcome', 'Hello');
    $unit = "categories:{$category->id}:name";
    $before = translationSourceOf($unit, $this->target);

    $this->artisan('translations:baseline-sources')
        ->expectsOutput('Recorded the English of 0 project translations that had none recorded.')
        ->assertSuccessful();

    expect(translationSourceOf($unit, $this->target))->toBe($before)
        ->and($before['reference'])->toBe('Welcome');
});

it('records nothing for a missing translation, a unit with no English, a language not installed or content no visitor sees', function () {
    $missing = untranslatedCategory('Cats');
    $blank = RatingGroup::factory()->create(['description' => '', 'description_translations' => [$this->target => 'Leer'], 'is_active' => true]);
    $inactive = Category::factory()->create(['name' => 'Hidden', 'name_translations' => [$this->target => 'Versteckt'], 'is_active' => false]);
    $foreign = Category::factory()->create(['name' => 'Birds', 'name_translations' => ['xx' => 'Vögel', 'en' => 'Birds'], 'is_active' => true]);

    $this->artisan('translations:baseline-sources')->assertSuccessful();

    expect(ProjectTranslationSource::query()->whereIn('unit_id', [
        "categories:{$missing->id}:name", "rating_groups:{$blank->id}:description", "categories:{$inactive->id}:name", "categories:{$foreign->id}:name",
    ])->exists())->toBeFalse();
});

it('is safe to run again: a second run finds nothing to add', function () {
    Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$this->target => 'Hunde'], 'is_active' => true]);

    $this->artisan('translations:baseline-sources')->assertSuccessful();
    $count = ProjectTranslationSource::query()->count();

    $this->artisan('translations:baseline-sources')
        ->expectsOutput('Recorded the English of 0 project translations that had none recorded.')
        ->assertSuccessful();

    expect(ProjectTranslationSource::query()->count())->toBe($count)
        ->and($count)->toBeGreaterThan(0);
});

it('runs every day without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'translations:baseline-sources'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
