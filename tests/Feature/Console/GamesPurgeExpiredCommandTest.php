<?php

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What playing a game recorded is kept for a while and then deleted by
 * games:purge-expired: game events after games.retention_days.events (400),
 * emails left in a game after games.retention_days.email_captures (365) — in
 * small batches, safely again and again, and never an attempt, an answer or a
 * result.
 */
function expiringGameEvent(GameVersion $version, int|float $daysAgo): GameEvent
{
    $event = new GameEvent;
    $event->forceFill([
        'game_id' => $version->game_id,
        'version_id' => $version->id,
        'audience' => GameAudience::Anonymous,
        'visitor_hash' => hash('sha256', 'guest'),
        'type' => GameEventType::View,
        'occurred_at' => CarbonImmutable::now('UTC')->subSeconds((int) round($daysAgo * 86400)),
    ])->save();

    return $event;
}

function expiringGameEmail(GameVersion $version, int|float $daysAgo): GameEmailCapture
{
    $attempt = GameAttempt::factory()->create(['version_id' => $version->id]);

    return GameEmailCapture::query()->forceCreate([
        'attempt_id' => $attempt->id,
        'game_id' => $version->game_id,
        'email' => "guest{$attempt->id}@example.test",
        'captured_at' => CarbonImmutable::now('UTC')->subSeconds((int) round($daysAgo * 86400)),
    ]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-09 03:00:00');
    $this->version = GameVersion::factory()->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('keeps game events for 400 days and emails for 365, without a new environment variable', function () {
    expect(config('games.retention_days'))->toBe(['events' => 400, 'email_captures' => 365])
        ->and(file_get_contents(config_path('games.php')))->not->toContain('env(');
});

it('deletes the events and emails past their retention, and keeps the rest', function () {
    $oldEvents = [expiringGameEvent($this->version, 500), expiringGameEvent($this->version, 400.01)];
    $keptEvents = [expiringGameEvent($this->version, 400), expiringGameEvent($this->version, 30)];
    $oldEmail = expiringGameEmail($this->version, 365.01);
    $keptEmail = expiringGameEmail($this->version, 365);

    $this->artisan('games:purge-expired')
        ->expectsOutput('Deleted 2 game events older than 400 days.')
        ->expectsOutput('Deleted 1 emails left in games more than 365 days ago.')
        ->assertSuccessful();

    expect(GameEvent::query()->orderBy('id')->pluck('id')->all())->toBe(array_map(fn (GameEvent $event): int => $event->id, $keptEvents))
        ->and(GameEvent::query()->whereKey(array_map(fn (GameEvent $event): int => $event->id, $oldEvents))->exists())->toBeFalse()
        ->and(GameEmailCapture::query()->pluck('id')->all())->toBe([$keptEmail->id])
        // The attempt the email was left in stays: it is the player's result.
        ->and(GameAttempt::query()->whereKey($oldEmail->attempt_id)->exists())->toBeTrue();
});

it('deletes in batches of the size it is given, and finds nothing the second time', function () {
    foreach (range(1, 5) as $n) {
        expiringGameEvent($this->version, 500 + $n);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('games:purge-expired', ['--chunk' => 2])
        ->expectsOutput('Deleted 5 game events older than 400 days.')
        ->assertSuccessful();

    $deletes = array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'delete')
        && str_contains($query['query'], 'game_events')));
    DB::disableQueryLog();

    $this->artisan('games:purge-expired')
        ->expectsOutput('Deleted 0 game events older than 400 days.')
        ->assertSuccessful();

    expect($deletes)->toHaveCount(3)
        // Integer keys are written into the statement rather than bound.
        ->and(array_map(fn (array $query): int => preg_match('/ in \(([^)]*)\)/i', $query['query'], $in) === 1 ? count(explode(',', $in[1])) : 0, $deletes))->toBe([2, 2, 1])
        ->and(GameEvent::query()->count())->toBe(0);
});

it('finds what has expired by an index on its own date, however large the tables grow', function () {
    $leading = fn (string $table): array => collect(Schema::getIndexes($table))
        ->map(fn (array $index): string => $index['columns'][0])
        ->all();

    expect($leading('game_events'))->toContain('occurred_at')
        ->and($leading('game_email_captures'))->toContain('captured_at');
});

it('deletes nothing when a retention is not a positive number of days', function (string $key, mixed $days) {
    config(["games.retention_days.{$key}" => $days]);
    expiringGameEvent($this->version, 1000);
    expiringGameEmail($this->version, 1000);

    $this->artisan('games:purge-expired')->assertFailed();

    expect(GameEvent::query()->count())->toBe(1)
        ->and(GameEmailCapture::query()->count())->toBe(1);
})->with([
    'events at zero' => ['events', 0],
    'events negative' => ['events', -5],
    'emails missing' => ['email_captures', null],
    'emails as text' => ['email_captures', '365'],
]);

it('refuses a batch size outside 1 to 10,000 without deleting anything', function (string $chunk) {
    expiringGameEvent($this->version, 1000);

    $this->artisan('games:purge-expired', ['--chunk' => $chunk])->assertFailed();

    expect(GameEvent::query()->count())->toBe(1);
})->with(['0', '10001', 'many']);

it('runs every day without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'games:purge-expired'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
