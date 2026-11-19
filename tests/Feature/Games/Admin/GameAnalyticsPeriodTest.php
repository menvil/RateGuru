<?php

use App\Enums\TrafficChannel;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Filament\Support\ReportPeriodMemory;
use App\Models\GameAttempt;
use App\Models\ProjectSettings;
use App\Models\TrafficVisit;
use Livewire\Livewire;

/*
 * A game's Analytics tab and its period picker: Today first among the
 * presets — today's figures alone, against the day before up to the same
 * time, the way to Traffic sources opening its own Today — and the period the
 * administrator last chose, which every game's Analytics opens on while its
 * address names none: an address naming one wins and is kept in turn, a
 * period that can no longer be read is the default.
 */

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->actingAs($this->draft['admin']);
});

it('offers Today first: today’s figures alone, compared with the day before up to the same time, Traffic sources opened on its own Today', function () {
    $version = liveGame($this->draft);
    // Two today; one the day before in the same hours; two the day before later than it now is, which today hasn't had yet.
    foreach (['2026-11-18 09:00:00', '2026-11-18 10:00:00', '2026-11-17 08:00:00', '2026-11-17 20:00:00', '2026-11-17 22:00:00'] as $at) {
        GameAttempt::factory()->create(['version_id' => $version->id, 'started_at' => $at, 'last_activity_at' => $at]);
    }
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => $this->draft['game']->id, 'version_id' => $version->id, 'opened_at' => now(), 'arrived_at' => now(), 'network_day' => '2026-11-18']);
    $open = fn (array $query) => Livewire::withQueryParams($query)->test(GameAnalytics::class, ['record' => $this->draft['game']->id]);

    $today = $open(['period' => 'today'])
        ->assertSet('period', 'today')
        ->assertViewHas('datesText', '18 Nov 2026 · compared with 17 Nov 2026 up to the same time')
        ->assertViewHas('periodPicker', fn (array $picker): bool => array_key_first($picker['presets']) === 'today' && $picker['label'] === 'Today' && $picker['dates'] === '18 Nov 2026' && [$picker['from'], $picker['to']] === ['2026-11-18', '2026-11-18']);
    $started = collect($today->viewData('kpis'))->firstWhere('label', 'Game started');

    expect($started['value'])->toBe('2')
        ->and($started['change']['text'])->toBe('▲ 100% vs previous period')
        ->and($today->viewData('sources')['reportUrl'])->toContain('period=today');

    // The way to Traffic sources names its period even when it is that page's default: an address naming none opens on the period last chosen there.
    expect($open(['period' => '30d'])->viewData('sources')['reportUrl'])->toContain('period=30d')
        ->and($open(['period' => 'all'])->viewData('sources')['reportUrl'])->toContain('period=30d');
});

it('opens every game’s Analytics on the period last chosen while the address names none, an address naming one winning and kept in turn', function () {
    liveGame($this->draft);
    $other = publishableGameDraft(1, $this->draft['admin'])['game'];
    $open = fn (array $query = [], ?int $game = null) => Livewire::withQueryParams($query)->test(GameAnalytics::class, ['record' => $game ?? $this->draft['game']->id]);

    // Nothing chosen yet: the default. A preset chosen on one game is what every game's Analytics opens on, and so is a range.
    $open()->assertSet('period', '30d')->call('choosePeriod', 'today');
    $open()->assertSet('period', 'today');
    $open([], $other->id)->assertSet('period', 'today')->call('applyRange', '2026-11-01', '2026-11-10');
    $open()->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-10')
        ->assertViewHas('datesText', '01 Nov — 10 Nov 2026 · compared with 22 Oct — 31 Oct 2026');

    // An address naming a period wins and is kept in turn — all time, or the days a link from Experiments carries.
    $open(['period' => 'all'])->assertSet('period', 'all');
    $open()->assertSet('period', 'all')->assertViewHas('datesText', 'All time · nothing earlier to compare with');
    $open(['from' => '2026-11-05', 'to' => '2026-11-12'])->assertSet('from', '2026-11-05');
    $open()->assertSet('from', '2026-11-05')->assertSet('to', '2026-11-12');

    // Traffic sources and Growth → Analytics keep their own.
    Livewire::withQueryParams(['period' => '7d'])->test(TrafficSourcesPage::class);
    Livewire::withQueryParams(['period' => '90d'])->test(GrowthAnalyticsPage::class);
    $open()->assertSet('from', '2026-11-05')->assertSet('to', '2026-11-12');

    // A range now older than the figures are kept, or a preset the page doesn't offer: the default, silently.
    $this->travel(GameAnalytics::retentionDays())->days();
    $open()->assertSet('period', '30d')->assertSet('from', '')->assertSet('to', '');
    ReportPeriodMemory::remember(GameAnalytics::PERIOD_MEMORY, 'yesterday', '', '');
    $open()->assertSet('period', '30d');
});
