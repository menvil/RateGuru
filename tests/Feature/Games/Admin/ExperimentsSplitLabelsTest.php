<?php

use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Filament\Pages\ExperimentsPage;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Filament\Resources\Games\Pages\GameTraffic;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * Each split for a source is a comparison period of its own, named by its
 * scope wherever periods are listed — Growth → Experiments, a period's page
 * and its switcher, Analytics → Experiments, a game's Traffic history and its
 * Analytics periods: “Trust Your Taste · Anonymous visitors · ExoClick
 * tests”, “… · PopAds main · Campaign 881377”. The general split's period
 * that ran beside a split reads as “Everyone else”; one that never did reads
 * as it always has.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->actingAs(User::factory()->admin()->create());
    $this->game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste']);
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $pop = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $pop->id, 'external_id' => '881377', 'name' => null]);

    // The general split before any split, then beside them; a split of ExoClick, then of one PopAds campaign; logged-in players never split.
    $this->before = comparisonPeriod($this->game, [1 => 50, 2 => 50], '2026-10-01 10:00:00', '2026-10-20 10:00:00');
    $this->general = comparisonPeriod($this->game, [1 => 50, 2 => 50], '2026-10-20 10:00:00');
    $this->exo = comparisonPeriod($this->game, [2 => 50, 3 => 50], '2026-11-01 10:00:00');
    $this->exo->forceFill(['source_id' => $exo->id])->save();
    $this->campaign = comparisonPeriod($this->game, [1 => 70, 3 => 30], '2026-11-05 10:00:00');
    $this->campaign->forceFill(['source_id' => $pop->id, 'campaign_id' => $campaign->id])->save();
    $this->players = comparisonPeriod($this->game, [1 => 50, 2 => 50], '2026-10-20 10:00:00', null, GameAudience::Authenticated);
});

it('names each split in Growth → Experiments, and the general split beside one everyone else', function () {
    $page = Livewire::test(ExperimentsPage::class)->call('load')
        ->assertSee('PopAds main · Campaign 881377')
        ->assertSee('Everyone else');

    expect(collect($page->viewData('rows'))->mapWithKeys(fn (array $row): array => [$row['id'] => $row['audience'].' | '.($row['scope'] ?? '—')])->all())->toBe([
        $this->campaign->id => 'Anonymous visitors | PopAds main · Campaign 881377',
        $this->exo->id => 'Anonymous visitors | ExoClick tests',
        // Started together, the later made first.
        $this->players->id => 'Logged-in players | —',
        $this->general->id => 'Anonymous visitors | Everyone else',
        $this->before->id => 'Anonymous visitors | —',
    ]);
});

it('titles a split’s page by its scope, and switches only between the periods of that scope', function (string $period, string $title, string $group, array $switcher) {
    $page = Livewire::withQueryParams(['period' => $this->{$period}->id])->test(ExperimentsPage::class)->call('load');

    expect($page->viewData('title'))->toBe($title)
        ->and($page->viewData('group'))->toBe($group)
        ->and(collect($page->viewData('switcher'))->pluck('mix')->all())->toBe($switcher);
})->with([
    'a campaign’s split' => ['campaign', 'Trust Your Taste · Anonymous visitors · PopAds main · Campaign 881377', 'anonymous visitors · PopAds main · Campaign 881377', ['V1 70% · V3 30%']],
    'a source’s split' => ['exo', 'Trust Your Taste · Anonymous visitors · ExoClick tests', 'anonymous visitors · ExoClick tests', ['V2 50% · V3 50%']],
    'the general split beside them' => ['general', 'Trust Your Taste · Anonymous visitors · Everyone else', 'anonymous visitors', ['V1 50% · V2 50%', 'V1 50% · V2 50%']],
    'the general split before them' => ['before', 'Trust Your Taste · Anonymous visitors', 'anonymous visitors', ['V1 50% · V2 50%', 'V1 50% · V2 50%']],
]);

it('names the split in Analytics → Experiments, its chip and its header', function () {
    $data = Livewire::withQueryParams(['tab' => 'experiments', 'exp' => (string) $this->exo->id])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');

    expect(collect($data['choices'])->pluck('name')->all())->toBe([
        'Trust Your Taste · Anonymous visitors · PopAds main · Campaign 881377',
        'Trust Your Taste · Anonymous visitors · ExoClick tests',
        'Trust Your Taste · Logged-in players',
        'Trust Your Taste · Anonymous visitors · Everyone else',
        'Trust Your Taste · Anonymous visitors',
    ])->and($data['subtitle'])->toBe('Trust Your Taste · Anonymous visitors · ExoClick tests');
});

it('shows each period’s scope in the game’s Traffic history and in its Analytics periods', function () {
    $traffic = Livewire::test(GameTraffic::class, ['record' => $this->game->id]);

    expect(collect($traffic->viewData('periods'))->pluck('audience')->all())->toBe([
        'Anonymous visitors · PopAds main · Campaign 881377',
        'Anonymous visitors · ExoClick tests',
        'Logged-in players',
        'Anonymous visitors · Everyone else',
        'Anonymous visitors',
    ]);

    $analytics = Livewire::test(GameAnalytics::class, ['record' => $this->game->id]);

    expect(collect($analytics->viewData('periodRows'))->pluck('scope')->all())->toBe([
        'PopAds main · Campaign 881377',
        'ExoClick tests',
        'Everyone else',
        null,
    ]);
});

it('reads the general split as everyone else only while a split ran beside it', function () {
    GameTrafficPeriod::query()->whereKey([$this->exo->id, $this->campaign->id])->update(['ended_at' => '2026-10-20 10:00:00', 'started_at' => '2026-10-10 10:00:00']);

    $page = Livewire::test(ExperimentsPage::class)->call('load');

    // Both splits ran while the earlier general period did, and ended as the later one began.
    expect(collect($page->viewData('rows'))->mapWithKeys(fn (array $row): array => [$row['id'] => $row['scope']])->only([$this->before->id, $this->general->id])->all())
        ->toBe([$this->general->id => null, $this->before->id => 'Everyone else']);
});
