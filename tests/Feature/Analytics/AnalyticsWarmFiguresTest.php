<?php

use App\Console\Commands\AnalyticsWarmFiguresCommand;
use App\Enums\GameStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Analytics\AnalyticsReadCache;
use App\Filament\Pages\Analytics\AnalyticsTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\TrafficSetting;
use App\Models\User;
use App\Support\Analytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

/*
 * The figures of Growth → Analytics read ahead of the administrators
 * (analytics:warm-figures): every tab slow to read, with what the filters
 * offer, for the page's default view and the periods administrators last
 * chose, kept under the very keys the page then asks for — so opening one
 * reads none of its figures, and they are what a read now gives. Nothing is
 * read while nobody opened the page for a day, nor while the tabs read the
 * visits themselves; figures kept past the next run are not read again;
 * every five minutes, in the background, never two runs at once.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    TrafficSetting::current()->forceFill(['capture_enabled' => true, 'site_capture_enabled' => true])->save();
    TrafficSetting::forgetCaptureFlags();
    analyticsVisit('ann', ['country' => 'DE', 'city' => 'Berlin', 'landing_key' => 'feed', 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'utm_campaign' => 'spring', 'utm_source' => 'reddit']);
    analyticsVisit('bob', ['network_day' => '2026-11-03', 'country' => 'US', 'landing_key' => 'posts.show', 'account_at' => '2026-11-03 10:05:00', 'signup_at' => '2026-11-03 10:04:00']);
    // An account whose first week is over, so Retention has shares to draw.
    retentionAccount('2026-11-02 10:00:00', [1, 7]);
    fillAnalyticsRollup();

    // How many times a tab's figures were read.
    $this->reads = 0;
    app()->resolving(AnalyticsTab::class, function (): void {
        $this->reads++;
    });
});

it('reads every tab slow to read ahead for the page’s default view, under the keys the page asks for', function () {
    // Opened by an administrator: noted, and nothing read before the page is in a browser.
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);

    expect($this->reads)->toBe(0);

    $this->artisan('analytics:warm-figures')->expectsOutputToContain('Read '.(count(GrowthAnalyticsPage::READ_CACHED) + 2).' figures ahead for 1 views')->assertSuccessful();

    expect($this->reads)->toBe(count(GrowthAnalyticsPage::READ_CACHED));

    // Opened as it opens with no address: nothing of the tab's own, nor what the filters offer, is read.
    $this->reads = 0;
    DB::enableQueryLog();
    $page = Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load');
    $statements = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($this->reads)->toBe(0)
        ->and($page->viewData('readAt'))->toBe('12:00')
        ->and(array_column($page->viewData('sourceOptions'), 'value'))->toBe(['', 'direct', 'reddit:organic'])
        ->and($statements->filter(fn (string $sql): bool => preg_match('/traffic_visits|analytics_visits|analytics_cells|analytics_game_days/', $sql) === 1 && preg_match('/min\(.arrived_at.\)/', $sql) !== 1)->values()->all())->toBe([]);

    // Every tab, clicked on the strip, is kept already — and is what a read now gives.
    foreach (GrowthAnalyticsPage::READ_CACHED as $tab) {
        $page->set('tab', $tab);
        $kept = $page->viewData('data');

        expect($this->reads)->toBe(0, "{$tab} was read again")
            ->and($page->viewData('readAt'))->toBe('12:00');

        $page->call('refreshFigures');

        // Nothing but plain values: an object would come back from a store as no class at all.
        expect($page->viewData('data'))->toEqual($kept, "{$tab} was kept other than it reads")
            ->and(array_filter(Arr::dot($page->viewData('data')), 'is_object'))->toBe([], "{$tab} keeps an object");
        $this->reads = 0;
    }
});

it('reads ahead the period each administrator last chose, a range too, which the page opens on', function () {
    Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class);
    // A minute later, so the range is the latest choice.
    $this->travel(1)->minutes();
    $other = User::factory()->admin()->create();
    $this->actingAs($other);
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('applyRange', '2026-11-01', '2026-11-16');

    $this->artisan('analytics:warm-figures')->assertSuccessful();

    // The default, then the latest choice first — Experiments once, of no period.
    expect($this->reads)->toBe(3 * (count(GrowthAnalyticsPage::READ_CACHED) - 1) + 1);

    // Opened again in the order chosen, a minute apart: the range stays the latest.
    $this->reads = 0;
    $this->actingAs($this->admin);
    $mine = Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->assertSet('period', '7d')->call('load');
    $this->travel(1)->minutes();
    $this->actingAs($other);
    $theirs = Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-16')->call('load');

    expect($this->reads)->toBe(0)
        ->and($mine->viewData('readAt'))->toBe('12:01')
        ->and($theirs->viewData('readAt'))->toBe('12:01');

    // No more views than it reads at once: the default and the latest choice; the 7 days chosen earlier keeps what it read before.
    config(['analytics.read_ahead.views' => 2]);
    $this->reads = 0;
    $this->travel(5)->minutes();
    fillAnalyticsRollup();
    $this->artisan('analytics:warm-figures')->assertSuccessful();

    expect($this->reads)->toBe(2 * (count(GrowthAnalyticsPage::READ_CACHED) - 1) + 1);

    $this->reads = 0;
    $readAt = fn (array $query): mixed => Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')->viewData('readAt');

    expect($readAt(['period' => AnalyticsPeriod::DEFAULT]))->toBe('12:07')
        ->and($readAt(['from' => '2026-11-01', 'to' => '2026-11-16']))->toBe('12:07')
        ->and($readAt(['period' => '7d']))->toBe('12:01')
        ->and($this->reads)->toBe(0);
});

it('reads the figures again only once they would lapse before the next run', function () {
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);
    $this->artisan('analytics:warm-figures')->assertSuccessful();
    $this->reads = 0;

    $this->travel(1)->minutes();
    $this->artisan('analytics:warm-figures')->expectsOutputToContain('Read 0 figures ahead')->assertSuccessful();

    expect($this->reads)->toBe(0);

    // Kept for ten minutes, read five minutes ago: they would lapse before the run after this one is through.
    analyticsVisit('cid');
    $this->travel(4)->minutes();
    fillAnalyticsRollup();
    $this->artisan('analytics:warm-figures')->assertSuccessful();

    expect($this->reads)->toBe(count(GrowthAnalyticsPage::READ_CACHED));

    $page = Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load');

    expect($page->viewData('readAt'))->toBe('12:05')
        ->and($page->viewData('data')['kpis'][0]['value'])->toBe('3');
});

it('reads nothing once no administrator opened the page for a day', function () {
    $this->artisan('analytics:warm-figures')->expectsOutputToContain('No administrator opened Analytics in the last 24 hours')->assertSuccessful();

    expect($this->reads)->toBe(0);

    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);
    $this->travel(24)->hours();
    $this->travel(1)->minutes();
    fillAnalyticsRollup();
    $this->artisan('analytics:warm-figures')->expectsOutputToContain('No administrator opened Analytics')->assertSuccessful();

    expect($this->reads)->toBe(0);
});

it('reads nothing while the tabs read the visits themselves', function () {
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);

    // Not brought up to date for longer than the rows may lag.
    $this->travel((int) config('analytics.rollup.stale_minutes') + 1)->minutes();
    app()->forgetScopedInstances();
    $this->artisan('analytics:warm-figures')->expectsOutputToContain('reads the visits themselves')->assertSuccessful();

    // Or the setting says so.
    fillAnalyticsRollup();
    config(['analytics.rollup.read' => false]);
    app()->forgetScopedInstances();
    $this->artisan('analytics:warm-figures')->expectsOutputToContain('reads the visits themselves')->assertSuccessful();

    expect($this->reads)->toBe(0);
});

it('builds the figures at the site’s address the page was opened at, and leaves every address as it was', function () {
    URL::useOrigin('https://admin.example');
    URL::forceScheme('https');
    app(AnalyticsReadCache::class)->opened((int) $this->admin->id, AnalyticsPeriod::DEFAULT, '', '');
    URL::useOrigin(null);
    URL::forceScheme(null);
    $configured = url('/');

    $this->artisan('analytics:warm-figures')->assertSuccessful();

    expect(url('/'))->toBe($configured);

    $this->reads = 0;
    URL::useOrigin('https://admin.example');
    URL::forceScheme('https');
    $page = Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($this->reads)->toBe(0)
        ->and($page->viewData('data')['journeys'])->toStartWith('https://admin.example/');
});

it('logs how long a run took and what it read', function () {
    Log::spy();
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);

    $this->artisan('analytics:warm-figures')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'analytics.figures.read_ahead'
        && $context['views'] === 1 && $context['read'] === count(GrowthAnalyticsPage::READ_CACHED) + 2 && $context['failed'] === 0 && is_int($context['ms']))->once();
});

it('refuses a time it can’t read, before reading anything', function () {
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);

    $this->artisan('analytics:warm-figures', ['--seconds' => '0'])->assertFailed();
    $this->artisan('analytics:warm-figures', ['--seconds' => 'all'])->assertFailed();

    expect($this->reads)->toBe(0);
});

it('runs every five minutes in the background, never two runs at once, keeping each run’s figures past the next', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'analytics:warm-figures'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/5 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->expiresAt)->toBe(10)
        ->and($events[0]->runInBackground)->toBeTrue()
        ->and(AnalyticsWarmFiguresCommand::EVERY_MINUTES)->toBe(5)
        ->and((int) config('analytics.read_cache_minutes'))->toBeGreaterThan(AnalyticsWarmFiguresCommand::EVERY_MINUTES + 1);
});

it('reads ahead only from the command: no request to the page can ask for it', function () {
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('readAhead', 'overview', '30d', '', '');
})->throws(MethodNotFoundException::class);

it('reads the Games tab ahead as the administrator who opened the page reads it, though the schedule signs nobody in', function () {
    Game::factory()->status(GameStatus::Live)->create();
    // Not recording: the game's sign-ups lead to the switch, for whoever may manage traffic sources.
    TrafficSetting::current()->forceFill(['capture_enabled' => false])->save();
    TrafficSetting::forgetCaptureFlags();
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);

    // As the schedule runs it: nobody signed in.
    Auth::forgetGuards();
    $this->artisan('analytics:warm-figures')->assertSuccessful();

    expect(Auth::user())->toBeNull();

    $this->actingAs($this->admin);
    $this->reads = 0;
    $page = Livewire::withQueryParams(['tab' => 'games'])->test(GrowthAnalyticsPage::class)->call('load');
    $kept = $page->viewData('data');

    expect($this->reads)->toBe(0)
        ->and($kept['signups']['trafficUrl'])->toBe(TrafficSourcesPage::getUrl(['tab' => 'sources']));

    $page->call('refreshFigures');

    expect($page->viewData('data'))->toEqual($kept);
});

it('reads nothing ahead as an administrator who may no longer open the page', function () {
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class);
    $this->admin->forceFill(['role' => UserRole::Moderator])->save();
    Auth::forgetGuards();

    $this->artisan('analytics:warm-figures')->assertSuccessful();

    expect($this->reads)->toBe(0);
});
