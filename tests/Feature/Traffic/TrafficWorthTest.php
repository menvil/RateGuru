<?php

use App\Actions\Traffic\RevalueOpenVisitsAction;
use App\Actions\Traffic\SaveTrafficPricesAction;
use App\Actions\Traffic\SetTrafficStepValueAction;
use App\Actions\Traffic\WorkOutChancesAction;
use App\Enums\TrafficStepValueMode;
use App\Enums\TrafficValueBasis;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\Game;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPriceSet;
use App\Models\TrafficStepChance;
use App\Models\TrafficStepValue;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Traffic\TrafficChanceSampleQuery;
use App\Services\Traffic\TrafficVisitWorth;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->now = CarbonImmutable::parse('2026-11-17 12:00:00');
    $this->travelTo($this->now);
});

/** A matured paid visit of a new visitor that opened the page, and what it reached. */
function maturedVisit(array $attributes = []): TrafficVisit
{
    $arrived = now()->subDays(20);

    return TrafficVisit::factory()->create([
        'arrived_at' => $arrived,
        'holds_until' => $arrived->copy()->addDays(7),
        'opened_at' => $arrived,
        'country' => 'US',
        ...$attributes,
    ]);
}

it('samples only matured visits of new visitors whose page ran', function () {
    maturedVisit(['started_at' => now()->subDays(20), 'account_at' => now()->subDays(19), 'account_after_seconds' => 1800]);
    maturedVisit();
    // Not yet matured, never opened, a known visitor, too old.
    TrafficVisit::factory()->create(['arrived_at' => now()->subDays(3), 'holds_until' => now()->addDays(4), 'opened_at' => now()->subDays(3)]);
    maturedVisit(['opened_at' => null]);
    maturedVisit(['known' => true]);
    maturedVisit(['arrived_at' => now()->subDays(130), 'holds_until' => now()->subDays(123), 'opened_at' => now()->subDays(130)]);
    // An unpaid visit matures 14 days after it arrived.
    TrafficVisit::factory()->unpaid()->create(['arrived_at' => now()->subDays(15), 'opened_at' => now()->subDays(15)]);

    $rows = collect(app(TrafficChanceSampleQuery::class)->rows($this->now));

    expect($rows->sum('visits'))->toBe(3)
        ->and($rows->sum('accounts'))->toBe(1)
        ->and($rows->sum('started_n'))->toBe(1)
        ->and($rows->sum('started_x'))->toBe(1)
        ->and($rows->sum('curve_1'))->toBe(1)
        ->and($rows->sum('curve_0'))->toBe(0);
});

it('keeps a run of the chances it works out, with the curve of confirmations', function () {
    $game = Game::factory()->create();

    foreach (range(1, 40) as $i) {
        maturedVisit(['game_id' => $game->id, 'started_at' => now()->subDays(20), 'account_at' => $i <= 4 ? now()->subDays(19) : null, 'account_after_seconds' => $i <= 4 ? 7200 : null]);
    }

    $run = app(WorkOutChancesAction::class)->handle('nightly', $this->now);
    $site = TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->get()->keyBy('step');

    expect($run->status)->toBe('finished')
        ->and($run->visits_scanned)->toBe(40)
        ->and($run->confirmation_curve['accounts'])->toBe(4)
        ->and((float) $site['opened']->chance)->toEqualWithDelta(0.1, 1e-8)
        ->and($site['opened']->thin)->toBeTrue()
        ->and(TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', 'g'.$game->id)->exists())->toBeTrue();
});

it('works out an empty run while nothing has matured, and every step is then worth 0', function () {
    $run = app(WorkOutChancesAction::class)->handle('nightly', $this->now);
    $visit = TrafficVisit::factory()->create(['opened_at' => now()]);

    expect($run->visits_scanned)->toBe(0)
        ->and(TrafficStepChance::query()->count())->toBe(0)
        ->and((string) app(TrafficVisitWorth::class)->step($visit, 'started')['value'])->toBe('0.00000000');
});

it('saves new prices as a new set, leaving each visit on the set it came under', function () {
    $first = TrafficPriceSet::current();
    $visit = TrafficVisit::factory()->create(['price_set_id' => $first->id]);

    $second = app(SaveTrafficPricesAction::class)->prices($this->admin, ['account_price' => '2', 'post_bonus' => '1', 'returned_bonus' => '0.2'], $first->id);

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->account_price)->toBe('0.5000')
        ->and($second->account_price)->toBe('2.0000')
        ->and($second->country_groups)->toBe($first->country_groups)
        ->and((string) app(TrafficVisitWorth::class)->step($visit, 'account')['value'])->toBe(number_format(0.5 * $visit->worth_percent / 100, 8, '.', ''))
        ->and(fn () => app(SaveTrafficPricesAction::class)->prices($this->admin, ['account_price' => '3', 'post_bonus' => '1', 'returned_bonus' => '0.2'], $first->id))
        ->toThrow(CannotManageTrafficException::class, 'Someone saved the prices after you opened it.');
});

it('saves country groups, the last holding every other country', function () {
    $current = TrafficPriceSet::current();

    $saved = app(SaveTrafficPricesAction::class)->groups($this->admin, [
        ['key' => 'us', 'name' => 'United States', 'percent' => 100, 'countries' => ['US']],
        ['key' => 'rest', 'name' => 'Everyone else', 'percent' => 40, 'countries' => ['DE']],
    ], $current->id);

    expect($saved->country_groups[1]['countries'])->toBe([])
        ->and($saved->groupOf('DE'))->toBe(['key' => 'rest', 'percent' => 40])
        ->and(fn () => app(SaveTrafficPricesAction::class)->groups($this->admin, [
            ['key' => 'a', 'name' => 'A', 'percent' => 100, 'countries' => ['US']],
            ['key' => 'b', 'name' => 'B', 'percent' => 100, 'countries' => ['US']],
            ['key' => 'rest', 'name' => 'Rest', 'percent' => 50, 'countries' => []],
        ], $saved->id))->toThrow(ValidationException::class, 'United States is already in another group.');
});

it('counts a value set by hand at once, always or while the data is thin', function () {
    $game = Game::factory()->create();
    $visit = TrafficVisit::factory()->create(['game_id' => $game->id, 'opened_at' => now()]);

    app(SetTrafficStepValueAction::class)->set($this->admin, $game, 'started', '0.03', TrafficStepValueMode::Thin, 'Until data comes in');
    $worth = app(TrafficVisitWorth::class)->step($visit, 'started');

    expect((string) $worth['value'])->toBe('0.03000000')
        ->and($worth['basis'])->toBe(TrafficValueBasis::Manual)
        ->and(fn () => app(SetTrafficStepValueAction::class)->set($this->admin, $game, 'account', '1', TrafficStepValueMode::Always))->toThrow(ValidationException::class);

    app(SetTrafficStepValueAction::class)->clear($this->admin, $game, 'started');

    expect(TrafficStepValue::query()->count())->toBe(0);
});

it('brings the open visits’ worth up to date, and a closed visit to nothing', function () {
    $game = Game::factory()->create();
    app(SetTrafficStepValueAction::class)->set($this->admin, $game, 'started', '0.04', TrafficStepValueMode::Always);
    $open = TrafficVisit::factory()->create(['game_id' => $game->id, 'arrived_at' => now()->subHour(), 'opened_at' => now()->subHour(), 'started_at' => now()->subHour(), 'value_expected' => 0]);
    $closed = TrafficVisit::factory()->create(['game_id' => $game->id, 'arrived_at' => now()->subDays(15), 'holds_until' => now()->subDays(8), 'opened_at' => now()->subDays(15), 'started_at' => now()->subDays(15), 'value_expected' => '0.02']);

    $changed = app(RevalueOpenVisitsAction::class)->handle($this->now);

    expect($changed)->toBe(2)
        ->and($open->fresh()->value_expected)->toBe('0.04000000')
        ->and($closed->fresh()->value_expected)->toBe('0.00000000');
});

it('works the worth out again only when the country groups changed', function () {
    $this->artisan('traffic:work-out-worth --when-groups-changed')->assertSuccessful();
    expect(TrafficChanceRun::query()->count())->toBe(0);

    $this->artisan('traffic:work-out-worth')->assertSuccessful();
    $this->artisan('traffic:work-out-worth --when-groups-changed')->assertSuccessful();
    expect(TrafficChanceRun::query()->count())->toBe(1);

    app(SaveTrafficPricesAction::class)->groups($this->admin, [['key' => 'all', 'name' => 'All', 'percent' => 100, 'countries' => []]], TrafficPriceSet::current()->id);
    $this->artisan('traffic:work-out-worth --when-groups-changed')->assertSuccessful();
    expect(TrafficChanceRun::query()->count())->toBe(2);
});
