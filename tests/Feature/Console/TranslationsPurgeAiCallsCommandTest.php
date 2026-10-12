<?php

use App\Models\Category;
use App\Models\TranslationAiProviderCall;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Translation AI telemetry is kept for translation.telemetry.retention_days
 * (90) and then deleted by translations:purge-ai-calls — that table only, in
 * small batches, and safely again and again.
 */
function aiCallCompletedDaysAgo(int|float $days): TranslationAiProviderCall
{
    return TranslationAiProviderCall::factory()->create(['completed_at' => CarbonImmutable::now('UTC')->subSeconds((int) round($days * 86400))]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-11 03:00:00');
});

it('keeps telemetry for ninety days, without a new environment variable', function () {
    expect(config('translation.telemetry.retention_days'))->toBe(90)
        ->and(file_get_contents(config_path('translation.php')))->toContain("'retention_days' => 90,");
});

it('deletes the calls that completed before the retention period, and keeps the rest', function () {
    $old = [aiCallCompletedDaysAgo(400), aiCallCompletedDaysAgo(91), aiCallCompletedDaysAgo(90.01)];
    $kept = [aiCallCompletedDaysAgo(90), aiCallCompletedDaysAgo(89.99), aiCallCompletedDaysAgo(1), aiCallCompletedDaysAgo(0)];

    $this->artisan('translations:purge-ai-calls')
        ->expectsOutput('Deleted 3 Translation AI call records older than 90 days.')
        ->assertSuccessful();

    expect(TranslationAiProviderCall::query()->orderBy('id')->pluck('id')->all())->toBe(array_map(fn ($call) => $call->id, $kept))
        ->and(TranslationAiProviderCall::query()->whereKey(array_map(fn ($call) => $call->id, $old))->exists())->toBeFalse();
});

it('deletes in batches of the size it is given', function () {
    foreach (range(1, 7) as $n) {
        aiCallCompletedDaysAgo(100 + $n);
    }
    aiCallCompletedDaysAgo(10);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('translations:purge-ai-calls', ['--chunk' => 3])
        ->expectsOutput('Deleted 7 Translation AI call records older than 90 days.')
        ->assertSuccessful();

    $deletes = array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'delete')));
    DB::disableQueryLog();

    expect($deletes)->toHaveCount(3)
        // Integer keys are written into the statement rather than bound.
        ->and(array_map(fn (array $query): int => preg_match('/ in \(([^)]*)\)/i', $query['query'], $in) === 1 ? count(explode(',', $in[1])) : 0, $deletes))->toBe([3, 3, 1])
        ->and(TranslationAiProviderCall::query()->count())->toBe(1);
});

it('is safe to run again: a second run finds nothing to delete', function () {
    aiCallCompletedDaysAgo(120);
    aiCallCompletedDaysAgo(5);

    $this->artisan('translations:purge-ai-calls')->assertSuccessful();
    $this->artisan('translations:purge-ai-calls')
        ->expectsOutput('Deleted 0 Translation AI call records older than 90 days.')
        ->assertSuccessful();

    expect(TranslationAiProviderCall::query()->count())->toBe(1);
});

it('follows the configured retention', function () {
    config(['translation.telemetry.retention_days' => 7]);
    aiCallCompletedDaysAgo(8);
    aiCallCompletedDaysAgo(6);

    $this->artisan('translations:purge-ai-calls')
        ->expectsOutput('Deleted 1 Translation AI call records older than 7 days.')
        ->assertSuccessful();

    expect(TranslationAiProviderCall::query()->count())->toBe(1);
});

it('deletes nothing when the retention is not a positive number of days', function (mixed $days) {
    config(['translation.telemetry.retention_days' => $days]);
    aiCallCompletedDaysAgo(400);

    $this->artisan('translations:purge-ai-calls')->assertFailed();

    expect(TranslationAiProviderCall::query()->count())->toBe(1);
})->with([0, -5, null, '90']);

it('refuses a batch size outside 1 to 10,000 without deleting anything', function (string $chunk) {
    aiCallCompletedDaysAgo(400);

    $this->artisan('translations:purge-ai-calls', ['--chunk' => $chunk])->assertFailed();

    expect(TranslationAiProviderCall::query()->count())->toBe(1);
})->with(['0', '10001', 'many']);

it('touches nothing but Translation AI telemetry', function () {
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => ['de' => 'Hunde']]);
    Category::query()->whereKey($category->id)->update(['created_at' => now()->subYears(2), 'updated_at' => now()->subYears(2)]);
    Cache::store(config('translation.bulk.cache_store'))->put('translation-generation:batch:old', ['status' => 'completed'], 3600);
    aiCallCompletedDaysAgo(400);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->artisan('translations:purge-ai-calls')->assertSuccessful();
    $tables = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    expect($category->fresh()->name_translations)->toBe(['de' => 'Hunde'])
        ->and(Cache::store(config('translation.bulk.cache_store'))->get('translation-generation:batch:old'))->toBe(['status' => 'completed'])
        ->and($tables)->each->toContain('translation_ai_provider_calls');
});

it('runs every day without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'translations:purge-ai-calls'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
