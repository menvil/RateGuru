<?php

use App\Enums\GameAttemptStatus;
use App\Enums\TrafficChannel;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Filament\Support\ReportPeriodMemory;
use App\Models\GameAttempt;
use App\Models\ProjectSettings;
use App\Models\TrafficVisit;
use App\Support\Traffic\Reports\ReportPeriod;
use Livewire\Livewire;

/*
 * A game's Analytics tab and its period picker, with every report page's
 * presets: Today first — today's figures alone, against the visits and plays
 * begun the day before up to the same time with whatever came of them later,
 * the way to Traffic sources opening its own Today —
 * Yesterday against the day before, This month against as far into last
 * month, Last month against the whole month before, each leading to Traffic
 * sources on the same preset; and the period the administrator last chose,
 * which every game's Analytics opens on while its address names none: an
 * address naming one wins and is kept in turn, a period that can no longer
 * be read is the default.
 */

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->actingAs($this->draft['admin']);
});

it('offers Today first: today’s figures alone, compared with the plays begun the day before up to the same time — finished whenever — Traffic sources opened on its own Today', function () {
    $version = liveGame($this->draft);
    // Two today; one the day before in the same hours; two the day before later than it now is, which today hasn't had yet.
    $plays = [];
    foreach (['2026-11-18 09:00:00', '2026-11-18 10:00:00', '2026-11-17 08:00:00', '2026-11-17 20:00:00', '2026-11-17 22:00:00'] as $at) {
        $plays[$at] = GameAttempt::factory()->create(['version_id' => $version->id, 'started_at' => $at, 'last_activity_at' => $at]);
    }
    // Finished: one of today's; the day before's begun by this time, though only at 18:00, after it; and one begun after this time.
    foreach (['2026-11-18 09:00:00' => '2026-11-18 09:20:00', '2026-11-17 08:00:00' => '2026-11-17 18:00:00', '2026-11-17 20:00:00' => '2026-11-17 20:20:00'] as $start => $end) {
        $plays[$start]->forceFill(['status' => GameAttemptStatus::Completed, 'completed_at' => $end, 'last_activity_at' => $end])->save();
    }
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => $this->draft['game']->id, 'version_id' => $version->id, 'opened_at' => now(), 'arrived_at' => now(), 'network_day' => '2026-11-18']);
    $open = fn (array $query) => Livewire::withQueryParams($query)->test(GameAnalytics::class, ['record' => $this->draft['game']->id]);

    $today = $open(['period' => 'today'])
        ->assertSet('period', 'today')
        ->assertViewHas('datesText', '18 Nov 2026 · compared with 17 Nov 2026, visits and plays begun by the same time')
        ->assertViewHas('periodPicker', fn (array $picker): bool => array_key_first($picker['presets']) === 'today' && $picker['label'] === 'Today' && $picker['dates'] === '18 Nov 2026' && [$picker['from'], $picker['to']] === ['2026-11-18', '2026-11-18']);
    $started = collect($today->viewData('kpis'))->firstWhere('label', 'Game started');
    $completed = collect($today->viewData('kpis'))->firstWhere('label', 'Completed');

    expect($started['value'])->toBe('2')
        ->and($started['change']['text'])->toBe('▲ 100% vs previous period')
        // The day before counts the play begun by this time as finished, though it finished after: plays begun, not steps taken, by then.
        ->and([$completed['value'], $completed['change']['text']])->toBe(['1', 'Same as previous period'])
        ->and($today->viewData('sources')['reportUrl'])->toContain('period=today');

    // The way to Traffic sources names its period even when it is that page's default: an address naming none opens on the period last chosen there. All time is that page's all time.
    expect($open(['period' => '30d'])->viewData('sources')['reportUrl'])->toContain('period=30d')
        ->and($open(['period' => 'all'])->viewData('sources')['reportUrl'])->toContain('period=all');
});

it('offers every report page’s presets: Yesterday against the day before, This month against as far into last month, Last month against the whole month before', function () {
    $version = liveGame($this->draft);
    // Yesterday and the day before; this month; early and late last month — the late one past how far this month has gone; the month before.
    foreach (['2026-11-17 09:00:00', '2026-11-16 09:00:00', '2026-11-05 10:00:00', '2026-10-05 10:00:00', '2026-10-25 10:00:00', '2026-09-10 10:00:00'] as $at) {
        GameAttempt::factory()->create(['version_id' => $version->id, 'started_at' => $at, 'last_activity_at' => $at]);
    }
    // A recorded visit yesterday and one last month, so the sources card leads to Traffic sources for each.
    foreach (['2026-11-17 09:00:00', '2026-10-25 10:00:00'] as $at) {
        TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => $this->draft['game']->id, 'version_id' => $version->id, 'opened_at' => $at, 'arrived_at' => $at, 'network_day' => substr($at, 0, 10)]);
    }
    $open = fn (array $query) => Livewire::withQueryParams($query)->test(GameAnalytics::class, ['record' => $this->draft['game']->id]);
    $cases = [
        'yesterday' => ['17 Nov 2026', ['2026-11-17', '2026-11-17'], '17 Nov 2026 · compared with 16 Nov 2026', '1', 'Same as previous period'],
        'this-month' => ['01 Nov — 18 Nov 2026', ['2026-11-01', '2026-11-18'], '01 Nov — 18 Nov 2026 · compared with 01 Oct — 18 Oct 2026, visits and plays begun by the same time', '3', '▲ 200% vs previous period'],
        'last-month' => ['01 Oct — 31 Oct 2026', ['2026-10-01', '2026-10-31'], '01 Oct — 31 Oct 2026 · compared with 01 Sep — 30 Sep 2026', '2', '▲ 100% vs previous period'],
    ];

    foreach ($cases as $preset => [$dates, $days, $text, $value, $change]) {
        $page = $open(['period' => $preset])
            ->assertSet('period', $preset)
            ->assertViewHas('datesText', $text)
            ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['presets'] === ReportPeriod::PRESETS && $picker['value'] === $preset && $picker['label'] === ReportPeriod::PRESETS[$preset] && $picker['dates'] === $dates && [$picker['from'], $picker['to']] === $days);
        $started = collect($page->viewData('kpis'))->firstWhere('label', 'Game started');

        expect([$started['value'], $started['change']['text']])->toBe([$value, $change], $preset)
            ->and($page->viewData('window'))->toBe(strtolower(ReportPeriod::PRESETS[$preset]))
            ->and($page->viewData('sources')['reportUrl'])->toContain('period='.$preset);
    }

    // All time: every attempt, with nothing to compare.
    $all = collect($open(['period' => 'all'])->viewData('kpis'))->firstWhere('label', 'Game started');
    expect($all['value'])->toBe('6');
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
    ReportPeriodMemory::remember(GameAnalytics::PERIOD_MEMORY, '365d', '', '');
    $open()->assertSet('period', '30d');

    // Every preset the picker offers is kept and opened on again.
    foreach (['yesterday', 'this-month', 'last-month'] as $preset) {
        $open()->call('choosePeriod', $preset);
        $open()->assertSet('period', $preset)->assertSet('from', '')->assertSet('to', '');
    }
});
