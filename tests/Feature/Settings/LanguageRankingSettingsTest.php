<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Actions\Settings\SaveProjectSettingsAction;
use App\Models\ProjectSettings;
use App\Support\Languages\LanguageRankingSettings;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * How the feed and the threads put the viewer's language first, as Project settings hold it: off until an
 * administrator switches it on, every other value falling back to its default until saved.
 */

function languageRanking(): LanguageRankingSettings
{
    $manager = app(ProjectSettingsManager::class);
    $manager->flush();

    return $manager->current()->languageRanking();
}

it('runs an installation without settings on the defaults, switched off', function () {
    expect(ProjectSettings::query()->exists())->toBeFalse()
        ->and(languageRanking())->toEqual(LanguageRankingSettings::fromArray([]))
        ->and(languageRanking()->enabled)->toBeFalse()
        ->and(languageRanking()->guestWindowDays)->toBe(7)
        ->and(languageRanking()->hitEvery)->toBe(10)
        ->and(languageRanking()->hotFloorPercentile)->toBe(0)
        ->and(languageRanking()->aiChecks)->toBeTrue();
});

it('reads the defaults under a row written before the setting existed', function () {
    ProjectSettings::factory()->create();

    expect(ProjectSettings::query()->value('language_ranking'))->toBeNull()
        ->and(languageRanking())->toEqual(LanguageRankingSettings::fromArray([]));
});

it('reads a stored value over the defaults, the others falling back', function () {
    ProjectSettings::factory()->create(['language_ranking' => ['enabled' => true, 'hit_every' => 5]]);

    expect(languageRanking()->enabled)->toBeTrue()
        ->and(languageRanking()->hitEvery)->toBe(5)
        ->and(languageRanking()->memberWindowDays)->toBe(7);
});

it('keeps the values a save did not name', function () {
    ProjectSettings::factory()->create(['language_ranking' => ['enabled' => true, 'guest_window_days' => 3]]);

    app(SaveProjectSettingsAction::class)->handle(['language_ranking' => ['hit_every' => 0]]);

    expect(ProjectSettings::query()->find(1)->language_ranking)->toBe(['enabled' => true, 'guest_window_days' => 3, 'hit_every' => 0])
        ->and(languageRanking()->enabled)->toBeTrue()
        ->and(languageRanking()->hitEvery)->toBe(0);
});

it('leaves the setting alone when a preset is applied', function () {
    ProjectSettings::factory()->create(['language_ranking' => ['enabled' => true]]);

    app(ApplyProjectPresetAction::class)->handle('generic', force: true);

    expect(ProjectSettings::query()->find(1)->language_ranking)->toBe(['enabled' => true]);
});

it('starts a new installation with the defaults written down', function () {
    DB::transaction(fn () => app(ProjectSettingsManager::class)->lockedRow());

    expect(ProjectSettings::query()->find(1)->language_ranking)->toBe(LanguageRankingSettings::DEFAULTS);
});

it('holds each value to its range', function () {
    $settings = LanguageRankingSettings::fromArray([
        'guest_window_days' => 0,
        'member_window_days' => 400,
        'hit_every' => -3,
        'hit_top_percent' => 99,
        'hot_floor_percentile' => 'lots',
        'ai_checks_per_day' => 10_000_000,
    ]);

    expect($settings->guestWindowDays)->toBe(1)
        ->and($settings->memberWindowDays)->toBe(30)
        ->and($settings->hitEvery)->toBe(0)
        ->and($settings->hitTopPercent)->toBe(50)
        ->and($settings->hotFloorPercentile)->toBe(0)
        ->and($settings->aiChecksPerDay)->toBe(100000);
});

it('puts the viewer\'s language first in threads only while the feature is on', function () {
    expect(LanguageRankingSettings::fromArray(['enabled' => false])->ranksComments())->toBeFalse()
        ->and(LanguageRankingSettings::fromArray(['enabled' => true])->ranksComments())->toBeTrue()
        ->and(LanguageRankingSettings::fromArray(['enabled' => true, 'comments_first' => false])->ranksComments())->toBeFalse();
});

it('undoes the column and adds it again, the row staying', function () {
    ProjectSettings::factory()->create();
    $migration = require database_path('migrations/2026_11_25_100300_add_language_ranking_to_project_settings_table.php');

    $migration->down();

    expect(Schema::hasColumn('project_settings', 'language_ranking'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('project_settings', 'language_ranking'))->toBeTrue()
        ->and(ProjectSettings::query()->whereKey(1)->exists())->toBeTrue();
});

it('never puts a strong post at every place, from every second one on', function () {
    expect(LanguageRankingSettings::fromArray(['hit_every' => 1])->hitEvery)->toBe(2)
        ->and(LanguageRankingSettings::fromArray(['hit_every' => 0])->hitEvery)->toBe(0);
});
