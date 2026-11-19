<?php

use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrackingLink;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Support\Analytics\CampaignKey;
use Carbon\CarbonImmutable;
use Database\Seeders\AnalyticsVolumeSeeder;

/**
 * The load for Growth → Analytics, made small: a few thousand visits over a
 * week and the week before, written as capture records them — sessions
 * keyed by their first visit, paid clicks of every source and campaign, the
 * site's own arrivals, the game's versions split by period, accounts and
 * the days they came back — and only once.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 15:30:00'));
    demoGameWithMembers(20);
    TrafficSource::factory()->create(['name' => 'ExoClick tests', 'link_key' => 'exo', 'preset_key' => 'exoclick', 'reporting_timezone' => 'America/New_York']);
});

it('writes a week of visits and the week before as capture records them, once', function () {
    (new AnalyticsVolumeSeeder(visits: 3000, days: 7))->__invoke();

    $visits = TrafficVisit::query()->where('visitor_hash', '!=', str_repeat('0', 64))->get()->keyBy('id');
    $latest = $visits->filter(fn (TrafficVisit $visit): bool => $visit->arrived_at->greaterThanOrEqualTo(now()->startOfDay()->subDays(6)));
    $before = $visits->filter(fn (TrafficVisit $visit): bool => $visit->arrived_at->lessThan(now()->startOfDay()->subDays(6)));
    $paid = $visits->whereNotNull('source_id');
    $later = $visits->filter(fn (TrafficVisit $visit): bool => $visit->entry_visit_id !== $visit->id);

    expect($latest->count())->toBeGreaterThanOrEqual(3000)->toBeLessThan(3020)
        // The site grew: the week before brought fewer.
        ->and($before->count())->toBeGreaterThan(1500)->toBeLessThan($latest->count())
        ->and($visits->max('arrived_at')->lessThanOrEqualTo(now()))->toBeTrue()
        ->and(TrafficSetting::captureFlags())->toBe(['capture' => true, 'site' => true])
        // Every paid click begins its own session, keyed by its source and campaign, and cost something.
        ->and($paid->filter(fn (TrafficVisit $visit): bool => $visit->entry_visit_id !== $visit->id))->toBeEmpty()
        ->and($paid->filter(fn (TrafficVisit $visit): bool => $visit->source_key !== 'paid:'.$visit->source_id || $visit->campaign_key !== CampaignKey::paid($visit->campaign_id)))->toBeEmpty()
        ->and($paid->where('spend', '<=', 0))->toBeEmpty()
        ->and($paid->whereNull('opened_at'))->not->toBeEmpty()
        // Every click is to the game's page, which names the game before the page runs, as capture does.
        ->and($paid->whereNull('game_id'))->toBeEmpty()
        ->and($paid->whereNotNull('campaign_id')->pluck('campaign_id')->unique()->count())->toBeGreaterThan(5)
        // The site's own arrivals, by every kind of source, tagged and not.
        ->and($visits->pluck('source_key')->unique()->all())->toContain('direct', 'google:organic', 'google:paid', 'reddit:organic', 'facebook:paid', 'email:email', 'shared', 'internal')
        ->and($visits->where('landing_key', 'feed'))->not->toBeEmpty()
        ->and($visits->where('landing_key', 'posts.show'))->not->toBeEmpty()
        // A session's later visit carries its first's keys, inside the site.
        ->and($later)->not->toBeEmpty()
        ->and($later->filter(fn (TrafficVisit $visit): bool => $visit->source_key !== $visits[$visit->entry_visit_id]->source_key
            || $visit->landing_key !== $visits[$visit->entry_visit_id]->landing_key
            || $visit->utm_campaign !== $visits[$visit->entry_visit_id]->utm_campaign
            || $visits[$visit->entry_visit_id]->entry_visit_id !== $visit->entry_visit_id
            || $visit->channel->value !== 'internal'))->toBeEmpty()
        // Browsers came back.
        ->and($visits->pluck('visitor_hash')->unique()->count())->toBeLessThan($visits->count())
        ->and($visits->where('known', true))->not->toBeEmpty()
        ->and($visits->whereNotNull('city'))->not->toBeEmpty();

    // The game: a second version, the traffic split between them by period, and for the first ad network — on the pages that ran.
    $game = $visits->whereNotNull('game_id')->whereNotNull('opened_at');

    expect(GameVersion::query()->count())->toBe(2)
        ->and(GameTrafficPeriod::query()->whereNotNull('source_id')->count())->toBe(1)
        ->and(GameTrafficPeriod::query()->count())->toBeGreaterThanOrEqual(5)
        ->and($game->pluck('version_id')->unique()->count())->toBe(2)
        ->and($game->whereNull('version_id'))->toBeEmpty()
        ->and(GameAttempt::query()->count())->toBe($game->whereNotNull('started_at')->count())
        ->and(GameAttempt::query()->whereNotIn('traffic_visit_id', $game->whereNotNull('started_at')->keys())->count())->toBe(0)
        ->and(GameAssignment::query()->count())->toBeGreaterThan(0)->toBeLessThan($game->count())
        ->and(GameEvent::query()->where('type', 'view')->count())->toBe($game->count())
        ->and(GameEvent::query()->where('type', 'result_viewed')->count())->toBe($game->whereNotNull('completed_at')->count());

    // The accounts: each credited to the visit it signed up on, confirmed ones with the days they came back.
    $accounts = AccountAttribution::query()->get();

    expect($accounts)->not->toBeEmpty()
        ->and($accounts->whereNotNull('confirmed_at')->count())->toBe($visits->whereNotNull('account_at')->count())
        ->and($accounts->filter(fn (AccountAttribution $account): bool => $visits[$account->credited_visit_id]->signup_at?->toDateTimeString() !== $account->signed_up_at->toDateTimeString()))->toBeEmpty()
        ->and(AccountBrowser::query()->count())->toBe($accounts->count())
        ->and(UserActivityDay::query()->where('day_number', '>=', 1)->exists())->toBeTrue()
        ->and($visits->whereNotNull('signup_at')->whereNull('signup_form_at'))->toBeEmpty()
        // Posts read, links and segments.
        ->and(TrafficVisitPost::query()->count())->toBeGreaterThan(500)
        ->and(TrafficVisitPost::query()->whereNotNull('shared_at')->exists())->toBeTrue()
        ->and(TrackingLink::query()->count())->toBe(15)
        ->and(TrafficSegment::query()->count())->toBe(2)
        ->and(TrafficCampaign::query()->count())->toBe(12);

    // The ids came from each table's own sequence: the next row made the usual way follows them.
    expect(TrafficVisit::factory()->unpaid()->create()->id)->toBeGreaterThan($visits->keys()->max());

    $counts = fn (): array => [
        'visits' => TrafficVisit::query()->count(),
        'links' => TrackingLink::query()->count(),
        'campaigns' => TrafficCampaign::query()->count(),
        'segments' => TrafficSegment::query()->count(),
        'accounts' => AccountAttribution::query()->count(),
        'users' => User::query()->count(),
    ];
    $before = $counts();
    (new AnalyticsVolumeSeeder(visits: 3000, days: 7))->__invoke();

    expect($counts())->toBe($before);
});

it('writes less of the window before, and the game’s own rows for the last days only, when asked', function () {
    (new AnalyticsVolumeSeeder(visits: 2000, days: 7, before: 0.2, gameDays: 2))->__invoke();

    $since = now()->startOfDay()->subDays(6);
    $latest = TrafficVisit::query()->where('arrived_at', '>=', $since)->count();
    $before = TrafficVisit::query()->where('arrived_at', '<', $since)->count();
    $played = TrafficVisit::query()->whereNotNull('started_at')->where('arrived_at', '<', now()->startOfDay()->subDay())->count();

    expect($latest)->toBeGreaterThanOrEqual(2000)->toBeLessThan(2020)
        ->and($before)->toBeGreaterThan(150)->toBeLessThan(500)
        // Every game visit whose page ran still says what was played; only those of the last two days have their attempt.
        ->and(TrafficVisit::query()->whereNotNull('game_id')->whereNotNull('opened_at')->whereNull('version_id')->count())->toBe(0)
        ->and(GameAttempt::query()->where('started_at', '<', now()->startOfDay()->subDay())->count())->toBe(0)
        ->and(GameAttempt::query()->count())->toBe(TrafficVisit::query()->whereNotNull('started_at')->where('arrived_at', '>=', now()->startOfDay()->subDay())->count())
        ->and(GameAssignment::query()->where('assigned_at', '<', now()->startOfDay()->subDay())->count())->toBe(0)
        ->and(GameEvent::query()->where('occurred_at', '<', now()->startOfDay()->subDay())->count())->toBe(0)
        ->and($played)->toBeGreaterThan(0);
});
