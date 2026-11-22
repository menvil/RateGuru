<?php

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Actions\Analytics\RecordVisitPostAction;
use App\Actions\Analytics\RefreshAnalyticsDayAction;
use App\Actions\Analytics\RefreshAnalyticsVisitsAction;
use App\Actions\Games\RecordGameInteractionAction;
use App\Actions\Games\StartGameAttemptAction;
use App\Actions\Traffic\AllocateSpendDayAction;
use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\TrafficEvent;
use App\Enums\UserActivity;
use App\Models\AccountAttribution;
use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsPerson;
use App\Models\AnalyticsRollupState;
use App\Models\AnalyticsVisit;
use App\Models\GameAttempt;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Support\Analytics\AnalyticsVisitRow;
use App\Support\Games\Play\GameVisitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;

/*
 * Analytics' own rows of the visits (analytics_visits): a day refreshed
 * writes a row a visit and, again, only the rows of the visits that changed;
 * each browser's first day the earliest its page ran; an older day told it
 * changed read again and let go; the days filled
 * from the first visit's on, as far as time allows, then counted as filled;
 * read only once filled, recently brought up to date and allowed; gone with
 * their visit; refreshed every minute and rechecked at night.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

it('writes a row a visit, then only the rows of the visits that changed', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-10', 'votes' => 2]);
    analyticsVisit('bob', ['network_day' => '2026-11-10']);
    analyticsVisit('cid', ['network_day' => '2026-11-11']);
    $refresh = app(RefreshAnalyticsDayAction::class);

    expect($refresh->handle('2026-11-10'))->toBe(2)
        ->and(AnalyticsVisit::query()->count())->toBe(2)
        ->and(AnalyticsVisit::query()->find($ann->id))->visitor_key->toBe(AnalyticsVisitRow::key(hash('sha256', 'ann')))->votes->toBe(2)->opened->toBeTrue()->started->toBeFalse()
        ->and($refresh->handle('2026-11-10'))->toBe(0);

    $ann->forceFill(['started_at' => '2026-11-10 10:02:00'])->save();

    expect($refresh->handle('2026-11-10'))->toBe(1)
        ->and(AnalyticsVisit::query()->find($ann->id)->started)->toBeTrue();
});

it('keeps each browser’s first day the earliest its page ran, whatever order the days are read in', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-12']);
    analyticsVisit('ann', ['network_day' => '2026-11-05']);
    analyticsVisit('bob', ['network_day' => '2026-11-04', 'opened_at' => null]);
    analyticsVisit('bob', ['network_day' => '2026-11-12']);
    $refresh = app(RefreshAnalyticsDayAction::class);
    $first = fn (string $browser): ?string => AnalyticsPerson::query()->find(AnalyticsVisitRow::key(hash('sha256', $browser)))?->first_day;

    $refresh->handle('2026-11-12');

    expect([$first('ann'), $first('bob')])->toBe(['2026-11-12', '2026-11-12']);

    $refresh->handle('2026-11-05');
    $refresh->handle('2026-11-04');
    $refresh->handle('2026-11-12');

    // A visit whose page never ran is no first day.
    expect([$first('ann'), $first('bob')])->toBe(['2026-11-05', '2026-11-12']);
});

it('marks an older day changed with a new token each time, and never a day read every minute anyway', function () {
    $mark = app(MarkAnalyticsDayAction::class);

    $mark->handle('2026-11-18');
    $mark->handle('2026-11-17 23:59:59');
    $mark->handle(null);
    $mark->handle('not a day');

    expect(AnalyticsDirtyDay::query()->count())->toBe(0);

    $mark->handle('2026-11-02 09:00:00');
    $token = AnalyticsDirtyDay::query()->find('2026-11-02')->token;
    $mark->handle('2026-11-02');

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-02'])
        ->and(AnalyticsDirtyDay::query()->find('2026-11-02')->token)->not->toBe($token);
});

it('marks a day about to leave the days read every minute, which the next whole read would miss', function () {
    $mark = app(MarkAnalyticsDayAction::class);

    // At 11:50 the 16th is read again at 11:55 still: nothing to tell.
    $mark->handle('2026-11-16', CarbonImmutable::parse('2026-11-18 11:50:00'));

    expect(AnalyticsDirtyDay::query()->count())->toBe(0);

    // At 11:57 it is among the days read every minute, but gone by the next
    // whole read at 12:00: it is marked.
    $mark->handle('2026-11-16', CarbonImmutable::parse('2026-11-18 11:57:00'));

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-16'])
        ->and(AnalyticsDirtyDay::query()->find('2026-11-16')->marked_at->toDateTimeString())->toBe('2026-11-18 11:57:00');

    // Marked again, it keeps the moment it was first marked.
    $mark->handle('2026-11-16', CarbonImmutable::parse('2026-11-18 11:58:00'));

    expect(AnalyticsDirtyDay::query()->find('2026-11-16')->marked_at->toDateTimeString())->toBe('2026-11-18 11:57:00');
});

it('marks the day of a visit days old that a late step, a spend shared out again or a click that came back changes', function () {
    $old = analyticsVisit('ann', ['network_day' => '2026-11-03']);
    $today = analyticsVisit('bob', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 10:00:00']);
    $source = TrafficSource::factory()->create();
    // A click whose spend was shared out from figures since removed: it goes
    // back to its own price, which writes it.
    analyticsVisit('cid', ['network_day' => '2026-11-05', 'arrived_at' => '2026-11-05 10:00:00', 'source_id' => $source->id, 'spend_basis' => 'manual', 'spend' => '1.50000000']);

    app(MarkTrafficVisitAction::class)->handle($today->id, TrafficEvent::Completed, now()->toImmutable());

    // A day read every minute anyway is never marked.
    expect(AnalyticsDirtyDay::query()->count())->toBe(0);

    app(MarkTrafficVisitAction::class)->handle($old->id, TrafficEvent::Account, now()->toImmutable(), ['signup_at' => now()]);
    app(AllocateSpendDayAction::class)->handle($source, '2026-11-05', now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->orderBy('day')->pluck('day')->all())->toBe(['2026-11-03', '2026-11-05']);

    // A day whose spend has nothing to move is not marked: the mark goes with
    // the writes it tells of, never ahead of them.
    app(AllocateSpendDayAction::class)->handle($source, '2026-11-06', now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->orderBy('day')->pluck('day')->all())->toBe(['2026-11-03', '2026-11-05']);
});

it('keeps what a visit did with posts on its row, and marks the day of a visit days old whose posts change', function () {
    $old = analyticsVisit('ann', ['network_day' => '2026-11-03']);
    $today = analyticsVisit('bob', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 10:00:00']);
    analyticsVisitPost($old, 7, ['views' => 2, 'voted' => true]);
    analyticsVisitPost($old, 8, ['views' => 0, 'voted' => true, 'shared_at' => '2026-11-03 10:05:00']);
    app(RefreshAnalyticsDayAction::class)->handle('2026-11-03');

    expect(AnalyticsVisit::query()->whereKey($old->id)->first(['post_views', 'post_viewed', 'post_voted', 'post_shares'])->only(['post_views', 'post_viewed', 'post_voted', 'post_shares']))
        ->toMatchArray(['post_views' => 2, 'post_shares' => 1])
        ->and((bool) AnalyticsVisit::query()->whereKey($old->id)->value('post_voted'))->toBeTrue();

    // A post opened on today's visit is read with today's day anyway; one opened or shared on the old visit marks its day.
    app(RecordVisitPostAction::class)->viewedOn($today->id, 9, now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->count())->toBe(0);

    app(RecordVisitPostAction::class)->viewedOn($old->id, 9, now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-03']);

    app(RefreshAnalyticsDayAction::class)->handle('2026-11-03');

    expect((int) AnalyticsVisit::query()->whereKey($old->id)->value('post_views'))->toBe(3);
});

it('reads a marked day again and lets it go', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-02']);
    analyticsVisit('bob', ['network_day' => '2026-11-03']);
    fillAnalyticsRollup();

    $ann->forceFill(['account_at' => '2026-11-09 08:00:00', 'signup_at' => '2026-11-02 10:05:00'])->save();
    app(MarkAnalyticsDayAction::class)->handle('2026-11-02');

    $done = app(RefreshAnalyticsVisitsAction::class)->handle(microtime(true) + 30);

    expect(AnalyticsVisit::query()->find($ann->id))->account->toBeTrue()->signup_at->toDateTimeString()->toBe('2026-11-02 10:05:00')
        ->and(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe([])
        ->and($done['filled'])->toBeTrue();
});

it('fills the days from the first visit’s on as far as time allows, and counts them filled once they reach the days read every minute', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-13']);
    analyticsVisit('cid', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 11:00:00']);
    $refresh = app(RefreshAnalyticsVisitsAction::class);

    // No time left: only the days read every minute.
    $refresh->handle(microtime(true) - 1);

    expect(AnalyticsVisit::query()->pluck('network_day')->all())->toBe(['2026-11-18'])
        ->and(AnalyticsRollupState::current())->filled_at->toBeNull()->refreshed_at->not->toBeNull();

    $done = $refresh->handle(microtime(true) + 30);

    expect($done['filled'])->toBeTrue()
        ->and(AnalyticsVisit::query()->orderBy('network_day')->pluck('network_day')->all())->toBe(['2026-11-10', '2026-11-13', '2026-11-18'])
        ->and(AnalyticsRollupState::current())->filled_through->toBe('2026-11-16')->filled_at->not->toBeNull();
});

it('reads the earlier days that still change before it first counts the rows filled, whatever the minute', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 10:00:00']);
    analyticsVisit('cid', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 10:00:00']);

    // 12:01: a minute that reads only today's day on — but the run that
    // finishes filling reads the 17th too, before Analytics reads the rows.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:01:00'));
    $done = app(RefreshAnalyticsVisitsAction::class)->handle(microtime(true) + 30, now: CarbonImmutable::now());

    expect($done['filled'])->toBeTrue()
        ->and(AnalyticsVisit::query()->orderBy('network_day')->pluck('network_day')->all())->toBe(['2026-11-10', '2026-11-17', '2026-11-18']);
});

it('reads today’s day and later every minute, and the earlier days that still change every fifth minute', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 10:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 10:00:00']);
    $refresh = app(RefreshAnalyticsVisitsAction::class);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:01:00'));
    $refresh->handle(microtime(true) - 1, now: CarbonImmutable::now());

    expect(AnalyticsVisit::query()->pluck('network_day')->all())->toBe(['2026-11-18']);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:05:00'));
    $refresh->handle(microtime(true) - 1, now: CarbonImmutable::now());

    expect(AnalyticsVisit::query()->orderBy('network_day')->pluck('network_day')->all())->toBe(['2026-11-17', '2026-11-18']);
});

it('reads the days given back from today again when asked to recheck them', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-01']);
    fillAnalyticsRollup();
    // Changed without telling: only a recheck reaching back that far sees it.
    $ann->forceFill(['completed_at' => '2026-11-01 10:09:00'])->save();
    $refresh = app(RefreshAnalyticsVisitsAction::class);

    $refresh->handle(microtime(true) + 30, 10);

    expect(AnalyticsVisit::query()->find($ann->id)->completed)->toBeFalse();

    $refresh->handle(microtime(true) + 30, 20);

    expect(AnalyticsVisit::query()->find($ann->id)->completed)->toBeTrue();
});

it('reads Analytics’ own rows only once filled, brought up to date recently and allowed', function () {
    $reads = function (): bool {
        app()->forgetScopedInstances();

        return app(AnalyticsRollupGate::class)->reads();
    };

    expect($reads())->toBeFalse();

    fillAnalyticsRollup();

    expect($reads())->toBeTrue();

    config(['analytics.rollup.read' => false]);

    expect($reads())->toBeFalse();

    config(['analytics.rollup.read' => true]);
    $this->travel(31)->minutes();

    expect($reads())->toBeFalse();
});

it('goes back to the visits while a day marked as changed waits longer than the rows may be stale', function () {
    $reads = function (): bool {
        app()->forgetScopedInstances();

        return app(AnalyticsRollupGate::class)->reads();
    };

    analyticsVisit('ann', ['network_day' => '2026-11-02']);
    fillAnalyticsRollup();

    // Brought up to date every minute, but one day keeps failing to be read.
    AnalyticsDirtyDay::query()->create(['day' => '2026-11-02', 'token' => '01JD0000000000000000000000', 'marked_at' => now()->subMinutes(5)]);

    expect($reads())->toBeTrue();

    AnalyticsDirtyDay::query()->whereKey('2026-11-02')->update(['marked_at' => now()->subMinutes(31)]);

    expect($reads())->toBeFalse();
});

it('goes with its visit', function () {
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-10']);
    fillAnalyticsRollup();

    $ann->delete();

    expect(AnalyticsVisit::query()->count())->toBe(0);
});

it('runs every minute and rechecks the last days at night, the two never at once', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'analytics:refresh-visits'))
        ->values();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('expression')->all())->toBe(['25 3 * * *', '* * * * *'])
        ->and($events->every(fn ($event) => $event->withoutOverlapping && $event->mutexName() === 'analytics-refresh-visits'))->toBeTrue()
        ->and($events[0]->command)->toContain('--recheck=60');

    // At 03:25 both are due, and the scheduler starts them in this order: the
    // recheck takes the shared lock, and the minute's run is the one skipped.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 03:25:00'));

    $due = collect(app(Schedule::class)->dueEvents(app()))
        ->filter(fn ($event) => str_contains($event->command ?? '', 'analytics:refresh-visits'))
        ->values();

    expect($due)->toHaveCount(2)
        ->and($due[0]->command)->toContain('--recheck=60');
});

it('refuses a recheck or a time it can’t read, before writing anything', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-18']);

    $this->artisan('analytics:refresh-visits', ['--recheck' => 'all'])->assertFailed();
    $this->artisan('analytics:refresh-visits', ['--seconds' => '0'])->assertFailed();

    expect(AnalyticsVisit::query()->count())->toBe(0);

    $this->artisan('analytics:refresh-visits')->assertSuccessful();

    expect(AnalyticsVisit::query()->count())->toBe(1);
});

it('still brings the visits up to date when a step before them fails, reporting it and failing the run', function () {
    $visit = analyticsVisit('ann', ['network_day' => '2026-11-18']);
    // An attribution left without its copies, whose copying fails: the hours and the visits are read all the same.
    AccountAttribution::factory()->create(['credited_visit_id' => $visit->id])->forceFill(['credited_source_key' => null, 'credited_device' => null])->saveQuietly();
    AccountAttribution::saving(fn () => throw new RuntimeException('copying failed'));
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (RuntimeException $failure) use (&$reported): bool {
        $reported[] = $failure->getMessage();

        return false;
    });

    $this->artisan('analytics:refresh-visits')->assertFailed();

    expect($reported)->toBe(['copying failed'])
        ->and(AnalyticsVisit::query()->count())->toBe(1)
        ->and(AnalyticsRollupState::current()->period_hours_through)->not->toBeNull();
});

it('keeps the attempts started on a visit and their share clicks on its row, and marks the day of a visit days old an attempt starts or shares on', function () {
    $version = liveGame(publishableGameDraft(2));
    $game = $version->game;
    $browser = hash('sha256', 'ann');
    $visitor = new GameVisitor(GameAudience::Anonymous, null, $browser, $browser);
    // A visit that already showed the version and started a game: a replay on it changes neither.
    $old = analyticsVisit('ann', ['network_day' => '2026-11-03', 'game_id' => $game->id, 'version_id' => $version->id, 'started_at' => '2026-11-03 10:01:00']);
    $today = analyticsVisit('ann', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 10:00:00', 'game_id' => $game->id, 'version_id' => $version->id, 'started_at' => '2026-11-18 10:01:00']);
    app(RefreshAnalyticsDayAction::class)->handle('2026-11-03');
    $start = fn (TrafficVisit $visit): GameAttempt => app(StartGameAttemptAction::class)->handle($game, $visitor, 'en', replay: true, visitId: $visit->id);
    $share = fn (GameAttempt $attempt) => app(RecordGameInteractionAction::class)->handle(GameEventType::ShareClicked, $game, $visitor, $attempt, null, 'reddit');
    $figures = fn (TrafficVisit $visit): array => array_map('intval', AnalyticsVisit::query()->whereKey($visit->id)->firstOrFail()->only(['attempts', 'share_clicks', 'shared_results']));

    // An attempt and a share on today's visit are read with today's day anyway.
    $share($start($today));

    expect(AnalyticsDirtyDay::query()->count())->toBe(0);

    $attempt = $start($old);

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-03']);

    app(RefreshAnalyticsDayAction::class)->handle('2026-11-03');
    AnalyticsDirtyDay::query()->delete();

    expect($figures($old))->toBe(['attempts' => 1, 'share_clicks' => 0, 'shared_results' => 0]);

    $share($attempt);
    $share($attempt);

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-03']);

    app(RefreshAnalyticsDayAction::class)->handle('2026-11-03');
    app(RefreshAnalyticsDayAction::class)->handle('2026-11-18');

    expect($figures($old))->toBe(['attempts' => 1, 'share_clicks' => 2, 'shared_results' => 1])
        ->and($figures($today))->toBe(['attempts' => 1, 'share_clicks' => 1, 'shared_results' => 1]);
});

it('keeps on a visit credited with an account the post its session opened first, and marks its day when a later visit of the session first opens one', function () {
    $credited = analyticsVisit('ann', ['network_day' => '2026-11-03', 'arrived_at' => '2026-11-03 23:40:00', 'account_at' => '2026-11-03 23:45:00', 'signup_at' => '2026-11-03 23:45:00']);
    $later = analyticsVisit('ann', ['network_day' => '2026-11-04', 'arrived_at' => '2026-11-04 00:05:00']);
    $other = analyticsVisit('bob', ['network_day' => '2026-11-04', 'arrived_at' => '2026-11-04 00:05:00']);
    analyticsSession($credited, $later);
    analyticsSession($other);
    $posts = app(RecordVisitPostAction::class);
    app(RefreshAnalyticsDayAction::class)->handle('2026-11-03');
    $sessionPost = fn (): mixed => AnalyticsVisit::query()->whereKey($credited->id)->value('session_post_id');

    expect($sessionPost())->toBeNull();

    // Another browser's first post is no post of this session; a vote on one before it is opened isn't one either.
    $posts->viewedOn($other->id, 31, now()->toImmutable());
    $posts->actedOn($later->id, 33, UserActivity::Voted, now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-04']);

    $posts->viewedOn($later->id, 32, now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->orderBy('day')->pluck('day')->all())->toBe(['2026-11-03', '2026-11-04']);

    app(RefreshAnalyticsDayAction::class)->handle('2026-11-03');
    AnalyticsDirtyDay::query()->delete();

    expect((int) $sessionPost())->toBe(32);

    // Opened again it begins nothing new: the credited visit's day stays as it is read.
    $posts->viewedOn($later->id, 32, now()->toImmutable());

    expect(AnalyticsDirtyDay::query()->pluck('day')->all())->toBe(['2026-11-04']);
});
