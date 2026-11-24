<?php

use App\Actions\Traffic\ApplyTrafficBlocksAction;
use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Actions\Traffic\ConnectTrafficApiAction;
use App\Actions\Traffic\QueueMissingTrafficBlocksAction;
use App\Actions\Traffic\RecordBlockedHitAction;
use App\Actions\Traffic\SyncTrafficNetworkAction;
use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnector;
use App\Enums\TrafficConnectorFailure;
use App\Enums\TrafficSpendOrigin;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficBlockListing;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignPrice;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Traffic\TrafficBlocksQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/** Calls to PopAds that move money: none of them may ever be made. */
const POPADS_MONEY_CALLS = ['campaign_top_up', 'campaign_empty_budget', 'campaign_runout'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    Http::preventStrayRequests();
    Sleep::fake();
    $this->admin = User::factory()->admin()->create();
    $this->popads = TrafficSource::factory()->create(['link_key' => 'pop', 'connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site]);
});

afterEach(function () {
    Http::assertNotSent(fn (Request $request): bool => collect(POPADS_MONEY_CALLS)->contains(fn (string $call): bool => str_contains($request->url(), $call)));
});

it('connects PopAds with a key it checked, kept encrypted', function () {
    Http::fake(['www.popads.net/api/user_status*' => Http::response(['user' => ['username' => 'owner']])]);

    $connection = app(ConnectTrafficApiAction::class)->connect($this->admin, $this->popads, ['api_key' => 'secret-popads-key']);

    expect($connection->status)->toBe(TrafficConnectionStatus::Connected)
        ->and($connection->account_label)->toBe('owner')
        ->and($connection->credentials)->toBe(['api_key' => 'secret-popads-key'])
        ->and((string) DB::table('traffic_api_connections')->value('credentials'))->not->toContain('secret-popads-key')
        ->and($connection->toArray())->not->toHaveKey('credentials');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept', 'application/json') && str_contains($request->url(), 'key=secret-popads-key'));
});

it('refuses a key the network refused, saying so without the network’s words', function () {
    Http::fake(['www.popads.net/*' => Http::response('403 Forbidden (API Key Not Valid)', 403)]);

    expect(fn () => app(ConnectTrafficApiAction::class)->connect($this->admin, $this->popads, ['api_key' => 'wrong']))
        ->toThrow(CannotManageTrafficException::class, 'The network refused the key. Enter a new one with Reconnect.');
    expect(TrafficApiConnection::query()->count())->toBe(0);
});

it('refuses an ExoClick account that isn’t in dollars, and a token that needs a second step', function () {
    $exo = TrafficSource::factory()->create(['connector' => TrafficConnector::ExoClick]);
    Http::fake([
        'api.exoclick.com/v2/login' => Http::sequence()->push(['token' => 's1', 'type' => 'Bearer', 'expires_in' => 3600])->push(['token' => 's2', 'type' => 'TwoFactor']),
        'api.exoclick.com/v2/user' => Http::response(['result' => ['username' => 'exo', 'currency' => 'EUR']]),
    ]);

    expect(fn () => app(ConnectTrafficApiAction::class)->connect($this->admin, $exo, ['api_token' => 't']))->toThrow(CannotManageTrafficException::class, 'isn’t in US dollars')
        ->and(fn () => app(ConnectTrafficApiAction::class)->connect($this->admin, $exo, ['api_token' => 't']))->toThrow(CannotManageTrafficException::class, 'second sign-in step');
});

it('refuses an ExoClick account whose answer doesn’t say its currency', function () {
    $exo = TrafficSource::factory()->create(['connector' => TrafficConnector::ExoClick]);
    Http::fake([
        'api.exoclick.com/v2/login' => Http::response(['token' => 's1', 'type' => 'Bearer', 'expires_in' => 3600]),
        'api.exoclick.com/v2/user' => Http::response(['result' => ['username' => 'exo']]),
    ]);

    expect(fn () => app(ConnectTrafficApiAction::class)->connect($this->admin, $exo, ['api_token' => 't']))->toThrow(CannotManageTrafficException::class);
    expect(TrafficApiConnection::query()->count())->toBe(0);
});

it('reads a day of PopAds spend for the campaigns RateGuru saw, and the campaigns’ own details', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'report_advertiser')) {
            $byWebsite = str_contains($request->body(), 'website');

            return Http::response(['rows' => $byWebsite
                ? [['campaign_id' => '881204', 'website_id' => '40123', 'cost' => '1.25', 'impressions' => 2500, 'datetime' => '2026-11-16'], ['campaign_id' => '999', 'website_id' => '1', 'cost' => '9', 'datetime' => '2026-11-16']]
                : [['campaign_id' => '881204', 'cost' => '1.25'], ['campaign_id' => '999', 'cost' => '9']]]);
        }

        return Http::response(['data' => ['general_information' => ['name' => 'US popunder'], 'budget' => ['max_bid' => '0.0005', 'max_per_day' => '20'], 'countries' => ['codes' => ['us']]]]);
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    $yesterday = TrafficSpendDay::query()->where('network_day', '2026-11-16')->where('origin', TrafficSpendOrigin::Api)->get();

    expect($yesterday)->toHaveCount(1)
        ->and($yesterday[0]->campaign_id)->toBe($campaign->id)
        ->and($yesterday[0]->site_ref)->toBe('40123')
        ->and($yesterday[0]->amount)->toBe('1.25000000')
        ->and($campaign->fresh()->name)->toBe('US popunder')
        ->and($campaign->fresh()->countries)->toBe(['US'])
        ->and($campaign->fresh()->bid_mobile)->toBe('0.000500')
        ->and(TrafficCampaignPrice::query()->where('campaign_id', $campaign->id)->count())->toBe(2)
        ->and($connection->fresh()->next_sync_at->diffInMinutes(now(), true))->toEqualWithDelta(5, 0.01)
        ->and($connection->fresh()->spend_closed_through)->toBe('2026-11-16');
});

it('reads the campaigns’ own details on every turn, five minutes apart, and the same spend again changes nothing', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204', 'name' => null]);
    $name = 'US popunder';
    Http::fake(function (Request $request) use (&$name) {
        if (str_contains($request->url(), 'report_advertiser')) {
            return Http::response(['rows' => [['campaign_id' => '881204', 'website_id' => '40123', 'cost' => '1.25', 'datetime' => '2026-11-16']]]);
        }

        return Http::response(['data' => ['general_information' => ['name' => $name], 'budget' => ['max_bid' => '0.0005', 'max_per_day' => '20'], 'countries' => ['codes' => ['us']]]]);
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    $fact = TrafficSpendDay::query()->where('network_day', '2026-11-16')->sole();
    TrafficSpendDay::query()->whereKey($fact->id)->update(['allocated_at' => now()]);

    expect($campaign->fresh()->name)->toBe('US popunder');

    // Renamed in the network a few minutes later: the next turn, five minutes on, reads it.
    $name = 'US popunder · night';
    $this->travel(5)->minutes();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());

    expect($campaign->fresh()->name)->toBe('US popunder · night')
        // The network's spend for the day came back the same: the day isn't shared out over its visits again.
        ->and(TrafficSpendDay::query()->where('network_day', '2026-11-16')->sole())->id->toBe($fact->id)->allocated_at->not->toBeNull();
});

it('keeps a day’s spend when PopAds answers with anything but a report', function (Closure $answer, TrafficConnectorFailure $failure = TrafficConnectorFailure::UnexpectedResponse) {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $closed = $connection->spend_closed_through;
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $figure = TrafficSpendDay::factory()->create(['source_id' => $this->popads->id, 'campaign_id' => $campaign->id, 'network_day' => '2026-11-17', 'origin' => TrafficSpendOrigin::Api, 'site_ref' => '40123', 'amount' => '1.25']);
    Http::fake(fn (Request $request) => str_contains($request->url(), 'report_advertiser') ? $answer($request) : Http::response(['data' => []]));

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    $kept = TrafficSpendDay::query()->where('origin', TrafficSpendOrigin::Api)->sole();

    expect($kept->id)->toBe($figure->id)
        ->and($kept->amount)->toBe('1.25000000')
        ->and($connection->fresh()->failures)->toBe(1)
        ->and($connection->fresh()->last_error)->toBe($failure->value)
        ->and($connection->fresh()->spend_closed_through)->toBe($closed);
})->with([
    'a message' => [fn () => fn () => Http::response(['message' => 'unexpected report format'])],
    'an error list, refused whatever its status' => [fn () => fn () => Http::response(['errors' => [['status' => 400, 'code' => 1, 'title' => 'Bad request']]]), TrafficConnectorFailure::Rejected],
    'a message under its rows' => [fn () => fn () => Http::response(['data' => ['message' => 'unexpected report format']])],
    'rows with no cost' => [fn () => fn () => Http::response(['rows' => [['campaign_id' => '881204', 'website_id' => '40123', 'datetime' => '2026-11-17']]])],
    'one row of two with no cost' => [fn () => fn () => Http::response(['rows' => [
        ['campaign_id' => '881204', 'website_id' => '40123', 'datetime' => '2026-11-17', 'cost' => '0.50'],
        ['campaign_id' => '881204', 'website_id' => '40124', 'datetime' => '2026-11-17'],
    ]])],
    'a blank cost' => [fn () => fn () => Http::response(['rows' => [['campaign_id' => '881204', 'website_id' => '40123', 'datetime' => '2026-11-17', 'cost' => '']]])],
    'totals with a cost in words' => [fn () => fn (Request $request) => Http::response(['rows' => [str_contains($request->body(), 'website')
        ? ['campaign_id' => '881204', 'website_id' => '40123', 'datetime' => '2026-11-17', 'cost' => '0.50']
        : ['campaign_id' => '881204', 'datetime' => '2026-11-17', 'cost' => 'unavailable']]])],
    'a count in words' => [fn () => fn () => Http::response(['rows' => [['campaign_id' => '881204', 'website_id' => '40123', 'datetime' => '2026-11-17', 'cost' => '0.50', 'impressions' => 'unavailable']]])],
    'totals that are a message' => [fn () => fn (Request $request) => str_contains($request->body(), 'website')
        ? Http::response(['rows' => [['campaign_id' => '881204', 'website_id' => '40123', 'datetime' => '2026-11-17', 'cost' => '0.50']]])
        : Http::response(['message' => 'unexpected report format'])],
]);

it('still takes an empty report for a day with no spend', function (array $answer) {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficSpendDay::factory()->create(['source_id' => $this->popads->id, 'campaign_id' => $campaign->id, 'network_day' => '2026-11-17', 'origin' => TrafficSpendOrigin::Api, 'site_ref' => '40123', 'amount' => '1.25']);
    Http::fake(fn (Request $request) => str_contains($request->url(), 'report_advertiser') ? Http::response($answer) : Http::response(['data' => []]));

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect(TrafficSpendDay::query()->where('origin', TrafficSpendOrigin::Api)->count())->toBe(0)
        ->and($connection->fresh()->failures)->toBe(0);
})->with([
    'bare' => [[]],
    'no rows' => [['rows' => []]],
    'no data' => [['data' => []]],
    'null rows' => [['rows' => null]],
    'null rows beside what a report carries' => [['fdow' => 'mon', 'campaigns' => [['id' => 881204]], 'time' => ['zone' => 'UTC'], 'websites' => [], 'mode' => 'advertiser', 'countries' => [], 'groups' => ['campaign', 'website', 'datetime:day'], 'orders' => [], 'generated' => 1795000000, 'rows' => null]],
    'as PopAds sends a period with no data' => [['fdow' => 'mon', 'campaigns' => [['id' => 881204]], 'time' => ['zone' => 'UTC'], 'websites' => [], 'mode' => 'advertiser', 'countries' => [], 'groups' => ['campaign', 'website', 'datetime:day'], 'orders' => [], 'generated' => 1795000000, 'rows' => []]],
]);

it('takes a cost of zero as a figure', function (mixed $zero) {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => str_contains($request->url(), 'report_advertiser')
        ? Http::response(['rows' => [['campaign_id' => '881204', 'website_id' => '40123', 'datetime' => '2026-11-17', 'cost' => $zero]]])
        : Http::response(['data' => []]));

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($connection->fresh()->failures)->toBe(0)
        ->and(TrafficSpendDay::query()->where('origin', TrafficSpendOrigin::Api)->where('network_day', '2026-11-17')->sole()->amount)->toBe('0.00000000');
})->with([
    'a number' => [0],
    'a string' => ['0.00'],
]);

it('reads every day back to the one before yesterday before it stops reading back', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => '2026-11-14']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => str_contains($request->url(), 'report_advertiser') ? Http::response(['rows' => []]) : Http::response(['data' => []]));

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    foreach (['2026-11-14', '2026-11-15'] as $day) {
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'report_advertiser') && ($request->data()['start'] ?? null) === $day.' 00:00');
    }

    expect($connection->fresh()->backfill_cursor)->toBeNull();
});

it('leaves out a campaign PopAds doesn’t know, and an archived one, without failing the sync', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $known = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '999999']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '777', 'status' => 'archived']);
    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), 'report_advertiser') => Http::response(['rows' => []]),
            str_contains($request->url(), 'details/999999') => Http::response(['error' => 'Not found'], 404),
            default => Http::response(['data' => ['general_information' => ['name' => 'US popunder']]]),
        };
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'details/777'));
    expect($known->fresh()->name)->toBe('US popunder')
        ->and($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->failures->toBe(0)->campaigns_synced_at->not->toBeNull();
});

it('waits longer after each failure, and asks for the administrator when the key is refused', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id]);
    Http::fake(['www.popads.net/*' => Http::sequence()->push('', 503)->push('', 403)]);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    expect($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->failures->toBe(1)->last_error->toBe('unavailable')
        ->and($connection->fresh()->next_sync_at->diffInMinutes(now(), true))->toEqualWithDelta(15, 0.01);

    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    expect($connection->fresh())->status->toBe(TrafficConnectionStatus::NeedsAttention)->last_error->toBe('auth');
});

it('leaves a connection reconnected during a turn as it was reconnected, whatever the old turn met', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'failures' => 2, 'last_error' => 'unavailable', 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id]);
    $reconnected = false;
    Http::fake(function () use ($connection, &$reconnected) {
        if (! $reconnected) {
            $reconnected = true;
            TrafficApiConnection::query()->find($connection->id)->forceFill(['credentials' => ['api_key' => 'new-key'], 'status' => TrafficConnectionStatus::Connected, 'failures' => 0, 'last_error' => null, 'backfill_cursor' => '2026-10-18'])->save();
        }

        // The old key, refused.
        return Http::response('', 403);
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->failures->toBe(0)->last_error->toBeNull()->backfill_cursor->toBe('2026-10-18')
        ->and($connection->fresh()->credentials)->toBe(['api_key' => 'new-key']);
});

it('leaves a connection disconnected during a turn disconnected', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $disconnected = false;
    Http::fake(function (Request $request) use (&$disconnected) {
        if (! $disconnected) {
            $disconnected = true;
            app(ConnectTrafficApiAction::class)->disconnect($this->admin, $this->popads);
        }

        return str_contains($request->url(), 'report_advertiser') ? Http::response(['rows' => []]) : Http::response(['data' => []]);
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($connection->fresh())->status->toBe(TrafficConnectionStatus::Disconnected)->credentials->toBeNull()->next_sync_at->toBeNull();
});

it('asks for the key again when it can no longer be read', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()]);
    DB::table('traffic_api_connections')->where('id', $connection->id)->update(['credentials' => 'not-encrypted-by-this-app']);
    Http::fake();

    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());

    expect(TrafficApiConnection::query()->find($connection->id))->status->toBe(TrafficConnectionStatus::NeedsAttention)->last_error->toBe('credentials_unreadable');
    Http::assertNothingSent();
});

it('queues blocks for the network, applies them by rewriting the exclusion list, and logs the batch', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $posted = null;
    Http::fake(function (Request $request) use (&$posted) {
        if ($request->method() === 'POST') {
            $posted = $request->body();

            return Http::response(['id' => '881204', 'mode' => 'exclude', 'items' => explode("\n", $request->body())]);
        }

        return Http::response(['id' => '881204', 'mode' => 'exclude', 'items' => ['111']]);
    });

    expect(app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123', '40124', '40123'], null, true))->toBe(2)
        ->and(TrafficBlock::query()->pluck('network_state')->unique()->all())->toBe([TrafficBlockNetworkState::Pending]);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($posted)->toBe("111\n40123\n40124")
        ->and(TrafficBlock::query()->pluck('network_state')->unique()->all())->toBe([TrafficBlockNetworkState::Applied])
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->sole())->ok->toBeTrue()->note->toBe('1 of 1 campaigns');
});

it('judges each PopAds site by the list read back, so the one the network dropped fails alone, reading the list back when the answer has none', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $reads = 0;
    Http::fake(function (Request $request) use (&$reads) {
        if ($request->method() === 'POST') {
            return Http::response(['result' => '2']);
        }

        $reads++;

        // The read back holds one of the two sites queued.
        return Http::response(['mode' => 'global', 'global_mode' => 'exclude', 'global_id' => 7, 'global_items' => $reads === 1 ? ['111'] : ['111', '40123']]);
    });

    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123', '40124'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($reads)->toBe(2)
        ->and(TrafficBlock::query()->orderBy('ref')->get()->map(fn (TrafficBlock $block): array => [$block->ref, $block->network_state, $block->network_error])->all())->toBe([
            ['40123', TrafficBlockNetworkState::Applied, null],
            ['40124', TrafficBlockNetworkState::Failed, 'rejected'],
        ])
        // Neither all nor nothing: the batch's line says it went part of the way.
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->sole())->ok->toBeFalse()->partial->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/website_targeting/7'));
});

it('calls a site blocked on every campaign’s list blocked, on some partly blocked and on none not blocked, keeping where it stands on each', function (array $refusing, TrafficBlockNetworkState $state, ?string $error, array $standing, bool $ok, bool $partial, string $note) {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $campaigns = collect(['881204', '881205'])->mapWithKeys(fn (string $id): array => [TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => $id])->id => $id]);
    $lists = ['881204' => ['111'], '881205' => ['111']];
    popAdsWithLists($lists, $refusing);

    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    $block = TrafficBlock::query()->sole();

    expect($block)->network_state->toBe($state)->network_error->toBe($error)
        ->and($block->listings()->get()->mapWithKeys(fn (TrafficBlockListing $listing): array => [$campaigns[$listing->campaign_id] => [$listing->listed, $listing->error]])->sortKeys()->all())->toBe($standing)
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->sole())->ok->toBe($ok)->partial->toBe($partial)->note->toBe($note);
})->with([
    'every campaign took it' => [[], TrafficBlockNetworkState::Applied, null, [881204 => [true, null], 881205 => [true, null]], true, false, '2 of 2 campaigns'],
    'one of two refused it' => [['881205'], TrafficBlockNetworkState::Partial, 'rejected', [881204 => [true, null], 881205 => [false, 'rejected']], false, true, '1 of 2 campaigns'],
    'both refused it' => [['881204', '881205'], TrafficBlockNetworkState::Failed, 'rejected', [881204 => [false, 'rejected'], 881205 => [false, 'rejected']], false, false, '0 of 2 campaigns'],
]);

it('takes a partly blocked site off only the campaigns whose lists hold it', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $taking = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $refusing = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'rejected']);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $taking->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $refusing->id, 'listed' => false, 'error' => 'rejected']);
    $lists = ['881204' => ['111', '40123'], '881205' => ['111']];
    popAdsWithLists($lists);

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block);

    expect($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Removing);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '881205'));
    expect($block->fresh())->toBeNull()
        ->and($lists['881204'])->toBe(['111'])
        ->and(TrafficBlockListing::query()->count())->toBe(0)
        ->and(TrafficBlockEvent::query()->where('kind', 'removed')->sole())->ok->toBeTrue()->note->toBe('1 of 1 campaigns');
});

it('takes a block off a list its add failed on only because the network was slow, since the write may have landed, and still drops a refused one at once', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $list = ['111'];
    $writes = 0;
    $down = false;
    $refusing = false;
    Http::fake(function (Request $request) use (&$list, &$writes, &$down, &$refusing) {
        if ($request->method() === 'POST') {
            if ($refusing) {
                return Http::response(['errors' => [['status' => 500, 'code' => 0, 'title' => 'Campaign is under review']]], 500);
            }

            // Written, its answer without the list: the list is read back — and the first read back meets PopAds down.
            $list = array_values(array_filter(explode("\n", $request->body()), fn (string $line): bool => $line !== ''));
            $down = ++$writes === 1;

            return Http::response(['result' => '1']);
        }

        if ($down) {
            $down = false;

            return Http::response('', 503);
        }

        return Http::response(['mode' => 'exclude', 'items' => $list]);
    });
    $blocks = app(BlockTrafficRefsAction::class);

    $blocks->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();
    $block = TrafficBlock::query()->sole();

    // Failed for all RateGuru could tell, yet on the list.
    expect($block)->network_state->toBe(TrafficBlockNetworkState::Failed)->network_error->toBe('unavailable')
        ->and($list)->toBe(['111', '40123']);

    $blocks->unblock($this->admin, $block);

    expect($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Removing);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($block->fresh())->toBeNull()
        ->and($list)->toBe(['111'])
        ->and(TrafficBlockEvent::query()->where('kind', 'removed')->sole())->ok->toBeTrue()->note->toBe('1 of 1 campaigns');

    // A campaign that refused the add never took it: unblocked, the block simply goes, and nothing is sent.
    $refusing = true;
    $blocks->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40124'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();
    $refused = TrafficBlock::query()->sole();

    expect($refused)->network_state->toBe(TrafficBlockNetworkState::Failed)->network_error->toBe('rejected');

    $sent = count(Http::recorded());
    $blocks->unblock($this->admin, $refused);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($refused->fresh())->toBeNull()
        ->and(count(Http::recorded()))->toBe($sent)
        ->and($list)->toBe(['111']);
});

it('keeps a site an unblock took off some campaigns only partly blocked, still on the lists that refused', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $campaigns = collect(['881204', '881205'])->map(fn (string $id) => TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => $id]));
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    $campaigns->each(fn (TrafficCampaign $campaign) => TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true]));
    $lists = ['881204' => ['111', '40123'], '881205' => ['111', '40123']];
    popAdsWithLists($lists, ['881205']);

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Partial)->network_error->toBe('rejected')
        ->and($block->listings()->orderBy('campaign_id')->get()->map(fn (TrafficBlockListing $listing): array => [$listing->listed, $listing->error])->all())->toBe([[false, null], [true, 'rejected']])
        ->and($lists)->toBe(['881204' => ['111'], '881205' => ['111', '40123']])
        ->and(TrafficBlockEvent::query()->where('kind', 'removed')->sole())->ok->toBeFalse()->partial->toBeTrue();
});

it('logs an unblock by the campaigns each block was sent to, not those the batch called for another', function (array $refusing, bool $partial, string $note) {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    // Each on one campaign's list, refused by the other's.
    $block = function (string $ref, TrafficCampaign $holding, TrafficCampaign $refused): TrafficBlock {
        $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'rejected']);
        TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $holding->id, 'listed' => true]);
        TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $refused->id, 'listed' => false, 'error' => 'rejected']);

        return $block;
    };
    $onFirst = $block('40123', $first, $second);
    $onSecond = $block('40124', $second, $first);
    $lists = ['881204' => ['40123'], '881205' => ['40124']];
    popAdsWithLists($lists, $refusing);

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $onFirst);
    app(BlockTrafficRefsAction::class)->unblock($this->admin, $onSecond);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect(TrafficBlockEvent::query()->where('kind', 'removed')->sole())->ok->toBeFalse()->partial->toBe($partial)->note->toBe($note);
})->with([
    'both refuse' => [['881204', '881205'], false, '0 of 2 campaigns'],
    'the first block’s campaign refuses' => [['881204'], true, '1 of 2 campaigns · 1 still on the lists, 1 taken off'],
]);

it('pushes a partly blocked site again to every campaign, and blocks again one the network refused', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $taking = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $refusing = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $partial = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'auto', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'rejected']);
    TrafficBlockListing::factory()->create(['block_id' => $partial->id, 'campaign_id' => $taking->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $partial->id, 'campaign_id' => $refusing->id, 'listed' => false, 'error' => 'rejected']);
    $failed = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40124', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Failed, 'network_error' => 'unavailable']);
    $lists = ['881204' => ['40123'], '881205' => []];
    popAdsWithLists($lists);

    expect(app(BlockTrafficRefsAction::class)->push($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123']))->toBe(1)
        ->and($partial->fresh())->network_state->toBe(TrafficBlockNetworkState::Pending)->network_error->toBeNull()->method->toBe(TrafficBlockMethod::Auto);

    // Blocked again through the API: back on its way to the network, not left as it failed.
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40124'], null, true);

    expect($failed->fresh()->network_state)->toBe(TrafficBlockNetworkState::Pending);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($partial->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and($partial->listings()->pluck('listed')->all())->toBe([true, true])
        ->and($failed->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and($lists)->toBe(['881204' => ['40123', '40124'], '881205' => ['40123', '40124']])
        ->and(TrafficBlockEvent::query()->where('kind', 'queued')->where('ref', '40123')->sole())->actor_user_id->toBe($this->admin->id);
});

it('sends a block unblocked while it was queued for a new campaign to every campaign again once it is pushed or blocked again', function (Closure $again) {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $taking = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $refusing = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'rejected']);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $taking->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $refusing->id, 'listed' => false, 'error' => 'rejected']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881206']);
    $lists = ['881204' => ['40123'], '881205' => [], '881206' => []];
    popAdsWithLists($lists);

    // Queued for the new campaign alone, then unblocked before it went.
    expect(app(QueueMissingTrafficBlocksAction::class)->handle((int) $this->popads->id, [TrafficBlockLevel::Site]))->toBe(1);
    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block->fresh());

    $again($this->admin, $this->popads);

    expect($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Pending);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => ['40123'], '881205' => ['40123'], '881206' => ['40123']])
        ->and($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied);
})->with([
    'pushed again' => [fn (User $admin, TrafficSource $source): int => app(BlockTrafficRefsAction::class)->push($admin, $source, TrafficBlockLevel::Site, ['40123'])],
    'blocked again through the API' => [fn (User $admin, TrafficSource $source): int => app(BlockTrafficRefsAction::class)->block($admin, $source, TrafficBlockLevel::Site, ['40123'], null, true)],
]);

it('counts a block pushed again, or blocked again, once — on the lists, in its listings, the pane and the rule’s cap', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $lists = ['881204' => [], '881205' => []];
    popAdsWithLists($lists);
    $blocks = app(BlockTrafficRefsAction::class);

    $blocks->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();
    $blocks->push($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123']);
    $blocks->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();
    $blocks->push($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123']);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => ['40123'], '881205' => ['40123']])
        ->and(TrafficBlock::query()->sole())->network_state->toBe(TrafficBlockNetworkState::Applied)
        ->and(TrafficBlockListing::query()->count())->toBe(2)
        ->and(app(TrafficBlocksQuery::class)->counts((int) $this->popads->id, now()))->toMatchArray(['sites' => 1, 'by_hand' => 1, 'applied' => 1, 'pending' => 0, 'rule_today' => 0]);
});

it('counts a click from a partly blocked site as still sent only through a campaign whose list holds it', function () {
    $taking = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $refusing = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'blocked_at' => now()->subHours(2)]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $taking->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $refusing->id, 'listed' => false, 'error' => 'rejected']);
    $click = fn (TrafficCampaign $campaign): TrafficVisit => TrafficVisit::factory()->create(['source_id' => $this->popads->id, 'campaign_id' => $campaign->id, 'site_ref' => '40123', 'arrived_at' => now()]);

    app(RecordBlockedHitAction::class)->handle($through = $click($refusing));

    expect($through->fresh()->blocked_hit)->toBeFalse()
        ->and($block->fresh()->clicks_after)->toBe(0);

    app(RecordBlockedHitAction::class)->handle($held = $click($taking));

    expect($held->fresh()->blocked_hit)->toBeTrue()
        ->and($block->fresh()->clicks_after)->toBe(1);
});

it('reads each campaign’s list during the sync, never writing it, and records the sites blocked in the PopAds panel', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    // Marked by hand in RateGuru and put on the list in the panel: RateGuru's own block, left as it is.
    $marked = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '503', 'network_state' => TrafficBlockNetworkState::ByHand]);
    $lists = ['881204' => ['501', '502', '503'], '881205' => ['501']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), 'targeting'));
    $found = TrafficBlock::query()->where('method', TrafficBlockMethod::Panel)->orderBy('ref')->get();

    expect($found->pluck('ref')->all())->toBe(['501', '502'])
        ->and($found->pluck('network_state')->unique()->all())->toBe([TrafficBlockNetworkState::Applied])
        ->and($found->pluck('campaign_id')->unique()->all())->toBe([null])
        ->and($found[0]->listings()->orderBy('campaign_id')->get()->map(fn (TrafficBlockListing $listing): array => [$listing->campaign_id, $listing->listed])->all())->toBe([[$first->id, true], [$second->id, true]])
        // A site found in the panel is kept against the lists that hold it, not every campaign's.
        ->and($found[1]->listings()->orderBy('campaign_id')->get()->map(fn (TrafficBlockListing $listing): array => [$listing->campaign_id, $listing->listed])->all())->toBe([[$first->id, true]])
        ->and($marked->fresh())->network_state->toBe(TrafficBlockNetworkState::ByHand)->method->toBe(TrafficBlockMethod::Manual)
        ->and($marked->listings()->count())->toBe(0)
        ->and($connection->fresh()->block_lists_read_at?->toDateTimeString())->toBe('2026-11-17 12:00:00')
        ->and(TrafficBlockEvent::query()->where('kind', 'found')->sole())->ref_count->toBe(2)->method->toBe(TrafficBlockMethod::Panel)->ok->toBeTrue();

    // Read again, the lists as they were: nothing new to record, the campaign that never held it still not one it is kept against.
    $this->travel(6)->hours();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());

    expect($found[1]->listings()->get()->map(fn (TrafficBlockListing $listing): array => [$listing->campaign_id, $listing->listed])->all())->toBe([[$first->id, true]])
        ->and(TrafficBlockEvent::query()->where('kind', 'found')->count())->toBe(1);
});

it('reads the campaigns’ lists again six hours later, not on every turn', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $lists = ['881204' => []];
    popAdsWithLists($lists);
    $reads = fn (): int => collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'campaign_website_targeting'))->count();

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    expect($reads())->toBe(1);

    $this->travel(5)->minutes();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    expect($reads())->toBe(1);

    $this->travel(6)->hours();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    expect($reads())->toBe(2);
});

it('forgets a site taken off in the panel, and marks RateGuru’s own block taken off there instead of putting it back', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = function (string $ref, TrafficBlockMethod $method, array $on): TrafficBlock {
        $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'method' => $method, 'network_state' => TrafficBlockNetworkState::Applied]);

        foreach ($on as $campaign) {
            TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true]);
        }

        return $block;
    };
    $panel = $block('501', TrafficBlockMethod::Panel, [$first]);
    $offOne = $block('40123', TrafficBlockMethod::Api, [$first, $second]);
    $offBoth = $block('40124', TrafficBlockMethod::Auto, [$first, $second]);
    $kept = $block('40125', TrafficBlockMethod::Api, [$first, $second]);
    $lists = ['881204' => ['40125'], '881205' => ['40123', '40125']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), 'targeting'));
    expect($panel->fresh())->toBeNull()
        ->and($offOne->fresh()->network_state)->toBe(TrafficBlockNetworkState::Partial)
        ->and($offOne->listings()->where('campaign_id', $first->id)->sole())->listed->toBeFalse()->error->toBe('taken_off')
        ->and($offBoth->fresh()->network_state)->toBe(TrafficBlockNetworkState::TakenOff)
        ->and($kept->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and($lists)->toBe(['881204' => ['40125'], '881205' => ['40123', '40125']]);

    // One line for what is off every list now, one for what is off some only — both done in the network's panel.
    $gone = TrafficBlockEvent::query()->where('kind', 'gone')->orderBy('id')->get();

    expect($gone->map(fn (TrafficBlockEvent $event): array => [$event->ref_count, $event->partial, $event->actor])->all())->toBe([[2, false, TrafficActor::NetworkPanel], [1, true, TrafficActor::NetworkPanel]])
        ->and($gone[0]->refs)->toEqualCanonicalizing(['501', '40124'])
        ->and($gone[1]->refs)->toBe(['40123']);
});

it('leaves a site taken off every list in the network’s panel to the owner for 30 days, as one an administrator unblocked, and lets an administrator block it again', function () {
    config(['traffic_presets.connectors.popads.verified' => true]);
    // Its sites have a few dozen clicks each: it judges bots from 30, not the general 100.
    $this->popads->forceFill(['block_auto' => true, 'block_daily_cap' => 10, 'block_bot_clicks' => 30])->save();
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = function (string $ref, TrafficBlockMethod $method, array $on): TrafficBlock {
        $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'method' => $method, 'network_state' => TrafficBlockNetworkState::Applied]);

        foreach ($on as $campaign) {
            TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true]);
        }

        return $block;
    };
    // Found in the panel; RateGuru's own, to be taken off both lists there; RateGuru's own, to be taken off one.
    $panel = $block('s1', TrafficBlockMethod::Panel, [$first]);
    $offBoth = $block('s2', TrafficBlockMethod::Auto, [$first, $second]);
    $offOne = $block('s3', TrafficBlockMethod::Api, [$first, $second]);

    // Bots, every one, by the rule's reckoning — s4 never blocked — beside a sound site, every click opening the page.
    foreach (['s1', 's2', 's3', 's4'] as $site) {
        TrafficVisit::factory()->count(40)->create(['source_id' => $this->popads->id, 'site_ref' => $site, 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'opened_at' => null, 'spend' => '0.01']);
    }

    TrafficVisit::factory()->count(160)->create(['source_id' => $this->popads->id, 'site_ref' => 'sound', 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'opened_at' => now()->subHour(), 'spend' => '0.01']);
    $lists = ['881204' => [], '881205' => ['s3']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($panel->fresh())->toBeNull()
        ->and($offBoth->fresh()->network_state)->toBe(TrafficBlockNetworkState::TakenOff)
        ->and($offOne->fresh()->network_state)->toBe(TrafficBlockNetworkState::Partial)
        // Off every list: left to the owner, as a site an administrator unblocked is; off one list only, still blocked on the other.
        ->and(app(TrafficBlocksQuery::class)->unblockedLately((int) $this->popads->id, TrafficBlockLevel::Site, now()))->toEqualCanonicalizing(['s1', 's2']);

    $this->artisan('traffic:block-losers')->assertSuccessful();

    expect(TrafficBlock::query()->where('method', TrafficBlockMethod::Auto)->where('network_state', TrafficBlockNetworkState::Pending)->pluck('ref')->all())->toBe(['s4'])
        // An administrator can still block it again.
        ->and(app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['s1'], null, true))->toBe(1);

    $this->actingAs($this->admin);
    Livewire::withQueryParams(['tab' => 'logs', 'log' => 'blocks'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Taken off some lists in the panel', 's3', 'Still on other campaigns', 'Taken off in the panel', 's1, s2', 'the block rule leaves it alone for 30 days']);

    $this->travel(31)->days();

    expect(app(TrafficBlocksQuery::class)->unblockedLately((int) $this->popads->id, TrafficBlockLevel::Site, now()))->toBe([]);
});

it('sends RateGuru’s own blocks to a campaign that came after them, never again to one that refused them or had them taken off in the panel', function () {
    // The lists were read an hour ago: only the campaign's first click brings it.
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null, 'block_lists_read_at' => now()->subHour()]);
    [$first, $second, $third] = collect(['881204', '881205', '881206'])->map(fn (string $id): TrafficCampaign => TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => $id]))->all();
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881209', 'status' => TrafficCampaignStatus::Archived]);
    $block = function (string $ref, TrafficBlockMethod $method, TrafficBlockNetworkState $state, array $standing, ?int $campaignId = null): TrafficBlock {
        $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'campaign_id' => $campaignId, 'method' => $method, 'network_state' => $state, 'network_error' => $state === TrafficBlockNetworkState::Partial ? 'rejected' : null]);

        foreach ($standing as $campaignId => [$listed, $error]) {
            TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaignId, 'listed' => $listed, 'error' => $error]);
        }

        return $block;
    };
    // Refused by the second campaign; taken off the third in the panel; marked by hand; found in the panel; meant for the first campaign alone.
    $refused = $block('40123', TrafficBlockMethod::Api, TrafficBlockNetworkState::Partial, [$first->id => [true, null], $second->id => [false, 'rejected'], $third->id => [true, null]]);
    $takenOff = $block('40124', TrafficBlockMethod::Auto, TrafficBlockNetworkState::Partial, [$first->id => [true, null], $second->id => [true, null], $third->id => [false, 'taken_off']]);
    $marked = $block('40125', TrafficBlockMethod::Manual, TrafficBlockNetworkState::ByHand, []);
    $panel = $block('501', TrafficBlockMethod::Panel, TrafficBlockNetworkState::Applied, [$first->id => [true, null]]);
    $named = $block('40126', TrafficBlockMethod::Api, TrafficBlockNetworkState::Applied, [$first->id => [true, null]], $first->id);
    $lists = ['881204' => ['40123', '40124', '501', '40126'], '881205' => ['40124'], '881206' => ['40123'], '881207' => ['777']];
    popAdsWithLists($lists);
    // The first click through a campaign that came after them.
    $new = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881207']);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($refused->fresh()->network_state)->toBe(TrafficBlockNetworkState::Pending)
        ->and(TrafficBlockEvent::query()->where('kind', 'queued')->sole())->actor->toBe(TrafficActor::System)->ref_count->toBe(2);

    $this->artisan('traffic:sync-networks')->assertSuccessful();
    $standing = fn (TrafficBlock $block): array => $block->listings()->get()->mapWithKeys(fn (TrafficBlockListing $listing): array => [$listing->campaign_id => [$listing->listed, $listing->error]])->all();

    // Written only to the new campaign's list, and only with what it lacked.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'targeting') && ! str_contains($request->url(), '881207'));
    expect($lists)->toBe(['881204' => ['40123', '40124', '501', '40126'], '881205' => ['40124'], '881206' => ['40123'], '881207' => ['777', '40123', '40124']])
        // Still partly blocked for what the other campaigns did, not for the new one.
        ->and($refused->fresh())->network_state->toBe(TrafficBlockNetworkState::Partial)->network_error->toBe('rejected')
        ->and($standing($refused))->toBe([$first->id => [true, null], $second->id => [false, 'rejected'], $third->id => [true, null], $new->id => [true, null]])
        ->and($takenOff->fresh()->network_state)->toBe(TrafficBlockNetworkState::Partial)
        ->and($standing($takenOff))->toBe([$first->id => [true, null], $second->id => [true, null], $third->id => [false, 'taken_off'], $new->id => [true, null]])
        ->and($marked->fresh()->network_state)->toBe(TrafficBlockNetworkState::ByHand)
        ->and($standing($panel))->toBe([$first->id => [true, null]])
        ->and($standing($named))->toBe([$first->id => [true, null]])
        // The batch went all the way: the new campaign took both.
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->sole())->ok->toBeTrue()->note->toBe('1 of 1 campaigns');

    // Nothing more to send at the next turn.
    $this->travel(5)->minutes();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'targeting'))->count())->toBe(1)
        ->and(TrafficBlockEvent::query()->where('kind', 'queued')->count())->toBe(1);
});

it('sends a block to a running campaign whose list was read without it, nothing refused there, and one that had no campaign to go to once one comes', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    // From before the lists were kept, on the first list only; sent while the source had no campaign; refused by the second campaign, the API down.
    $old = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    $none = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40124', 'method' => 'auto', 'network_state' => TrafficBlockNetworkState::Failed, 'network_error' => 'no_campaign']);
    $refused = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40125', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Failed, 'network_error' => 'unavailable']);
    $lists = ['881204' => ['40123', '40125'], '881205' => []];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => ['40123', '40125', '40124'], '881205' => ['40123', '40124']])
        ->and($old->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBeNull()
        ->and($none->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBeNull()
        // What it said of the second campaign stands: never sent there again by itself.
        ->and($refused->fresh())->network_state->toBe(TrafficBlockNetworkState::Partial)->network_error->toBe('unavailable');
});

it('sends a block again, once a read of the lists, to a campaign that missed it only because the network was slow or down, never to one that refused it', function () {
    // The lists are due to be read; the second campaign missed both blocks two hours ago — one timed out, one refused — and its list has neither.
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null, 'block_lists_read_at' => now()->subHours(7)]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $timedOut = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'timeout']);
    $refused = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40124', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'rejected']);

    foreach ([[$timedOut, 'timeout'], [$refused, 'rejected']] as [$block, $error]) {
        TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $first->id, 'listed' => true, 'error' => null, 'checked_at' => now()->subHours(2)]);
        TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $second->id, 'listed' => false, 'error' => $error, 'checked_at' => now()->subHours(2)]);
    }

    $lists = ['881204' => ['40123', '40124'], '881205' => []];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists['881205'])->toBe(['40123'])
        ->and($timedOut->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)
        ->and($refused->fresh())->network_state->toBe(TrafficBlockNetworkState::Partial)->network_error->toBe('rejected');

    // The network slow again: noted after the last read, so not tried again until the next one.
    $timedOut->listings()->where('campaign_id', $second->id)->update(['listed' => false, 'error' => 'timeout', 'checked_at' => now()]);
    TrafficBlock::query()->whereKey($timedOut->id)->update(['network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'timeout']);
    $this->travel(5)->minutes();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());

    expect($timedOut->fresh()->network_state)->toBe(TrafficBlockNetworkState::Partial)
        ->and(TrafficBlockEvent::query()->where('kind', 'queued')->count())->toBe(1);
});

it('never sends a block again to a campaign the network was slow to take it on while that campaign’s list can’t be read, and takes it off there when it is unblocked', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null, 'block_lists_read_at' => now()->subHours(7)]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'unavailable']);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $first->id, 'listed' => true, 'error' => null, 'checked_at' => now()->subHours(5)]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $second->id, 'listed' => false, 'error' => 'unavailable', 'checked_at' => now()->subHours(5)]);
    // The add the network answered with a 503 landed all the same; the second campaign's list can't be read now.
    $lists = ['881204' => ['40123'], '881205' => ['40123']];
    $down = true;
    Http::fake(function (Request $request) use (&$lists, &$down) {
        if (! preg_match('#/api/campaign_website_targeting/(\w+)#', $request->url(), $match)) {
            return Http::response(str_contains($request->url(), 'report_advertiser') ? ['rows' => []] : ['data' => []]);
        }

        if ($request->method() === 'GET' && $match[1] === '881205' && $down) {
            return Http::response('', 503);
        }

        if ($request->method() === 'POST') {
            $lists[$match[1]] = str_contains($request->url(), 'mode=none') ? [] : array_values(array_filter(explode("\n", $request->body())));
        }

        return Http::response($lists[$match[1]] === [] ? ['mode' => 'none', 'items' => []] : ['mode' => 'exclude', 'items' => $lists[$match[1]]]);
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Partial)
        ->and($block->listings()->where('campaign_id', $second->id)->sole())->listed->toBeFalse()->error->toBe('unavailable');

    $down = false;
    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block->fresh());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => [], '881205' => []])
        ->and($block->fresh())->toBeNull();
});

it('takes a block waiting for a new campaign off the lists holding it when it is unblocked meanwhile, never sending it to the new one', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null, 'block_lists_read_at' => now()->subHour()]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $first->id, 'listed' => true]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $lists = ['881204' => ['111', '40123'], '881205' => ['111']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block->fresh());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '881205'));
    expect($lists)->toBe(['881204' => ['111'], '881205' => ['111']])
        ->and($block->fresh())->toBeNull();
});

it('never sends a new campaign a block taken off every list in the panel, nor one whose unblock failed, and waits for the read to know where an older one stands', function () {
    // The lists were read an hour ago: the next read is five hours away.
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null, 'block_lists_read_at' => now()->subHour()]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = function (string $ref, TrafficBlockMethod $method, TrafficBlockNetworkState $state, ?string $error, array $standing): TrafficBlock {
        $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'method' => $method, 'network_state' => $state, 'network_error' => $error]);

        foreach ($standing as $campaignId => [$listed, $listingError]) {
            TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaignId, 'listed' => $listed, 'error' => $listingError]);
        }

        return $block;
    };
    // Taken off the only list it was on in the panel; still on it after an unblock the network refused; from before the lists were kept.
    $takenOff = $block('40123', TrafficBlockMethod::Auto, TrafficBlockNetworkState::TakenOff, null, [$first->id => [false, 'taken_off']]);
    $stuck = $block('40124', TrafficBlockMethod::Api, TrafficBlockNetworkState::Applied, 'rejected', [$first->id => [true, 'rejected']]);
    $older = $block('40125', TrafficBlockMethod::Api, TrafficBlockNetworkState::Applied, null, []);
    $lists = ['881204' => ['40124', '40125'], '881205' => []];
    popAdsWithLists($lists);
    $new = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $posts = fn (): int => collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'targeting'))->count();

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($posts())->toBe(0)
        ->and(TrafficBlockEvent::query()->where('kind', 'queued')->exists())->toBeFalse()
        ->and($takenOff->fresh()->network_state)->toBe(TrafficBlockNetworkState::TakenOff)
        ->and($stuck->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and($older->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied);

    // The next read finds none of them on the new campaign's list: the older block alone goes there.
    $this->travel(6)->hours();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => ['40124', '40125'], '881205' => ['40125']])
        ->and($posts())->toBe(1)
        ->and(TrafficBlockEvent::query()->where('kind', 'queued')->sole())->ref->toBe('40125')
        ->and($older->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and($takenOff->fresh()->network_state)->toBe(TrafficBlockNetworkState::TakenOff)
        ->and($stuck->listings()->where('campaign_id', $new->id)->sole())->listed->toBeFalse()->error->toBeNull();
});

it('sends a block to a new campaign once: one that refuses it keeps its refusal and is never sent it again by itself', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null, 'block_lists_read_at' => now()->subHour()]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'auto', 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $first->id, 'listed' => true]);
    $lists = ['881204' => ['40123'], '881205' => []];
    popAdsWithLists($lists, ['881205']);
    $new = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $posts = fn (): int => collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'targeting/881205'))->count();

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($posts())->toBe(1)
        ->and($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Partial)->network_error->toBe('rejected')
        ->and($block->listings()->where('campaign_id', $new->id)->sole())->listed->toBeFalse()->error->toBe('rejected');

    // The turns after it, the next read of the lists among them: nothing more goes there.
    foreach ([5, 360, 5] as $minutes) {
        $this->travel($minutes)->minutes();
        app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
        $this->artisan('traffic:sync-networks')->assertSuccessful();
    }

    expect($posts())->toBe(1)
        ->and(TrafficBlockEvent::query()->where('kind', 'queued')->count())->toBe(1)
        ->and($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Partial)->network_error->toBe('rejected')
        ->and($block->listings()->where('campaign_id', $new->id)->sole())->listed->toBeFalse()->error->toBe('rejected');
});

it('forgets a block was on its way to a campaign archived before it went, logging nothing, and sends it there once the campaign runs again', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null, 'block_lists_read_at' => now()->subHour()]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $first->id, 'listed' => true]);
    $lists = ['881204' => ['40123'], '881205' => []];
    popAdsWithLists($lists);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    expect($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Pending);

    $second->forceFill(['status' => TrafficCampaignStatus::Archived])->save();
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    // Nothing was sent, so nothing is said to have been: where it stands is what it was.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'targeting'));
    expect($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBeNull()
        ->and($block->listings()->pluck('campaign_id')->all())->toBe([$first->id])
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->exists())->toBeFalse();

    $second->forceFill(['status' => TrafficCampaignStatus::Active])->save();
    $this->travel(5)->minutes();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => ['40123'], '881205' => ['40123']])
        ->and($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->sole())->ok->toBeTrue()->note->toBe('1 of 1 campaigns');
});

it('locks the source before the blocks when it sends blocks to the campaigns they are missing from, as every writer of blocks does', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite takes no row locks.');
    }

    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $sql = [];
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    expect(app(QueueMissingTrafficBlocksAction::class)->handle((int) $this->popads->id, [TrafficBlockLevel::Site]))->toBe(1);

    $at = function (string $pattern) use (&$sql): ?int {
        $index = collect($sql)->search(fn (string $statement): bool => preg_match($pattern, $statement) === 1);

        return $index === false ? null : $index;
    };
    $source = $at('/from .?traffic_sources.? .*for update/');

    expect($source)->not->toBeNull()
        ->and($source)->toBeLessThan($at('/from .?traffic_blocks.? .*for update/'))
        ->and($source)->toBeLessThan($at('/^insert into .?traffic_block_listings/'))
        ->and($source)->toBeLessThan($at('/^update .?traffic_blocks.? set/'));
});

it('never takes a site blocked in the panel off the network unless an administrator unblocks that very row', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $panel = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '501', 'method' => TrafficBlockMethod::Panel, 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $panel->id, 'campaign_id' => $campaign->id, 'listed' => true]);
    $own = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Removing]);
    TrafficBlockListing::factory()->create(['block_id' => $own->id, 'campaign_id' => $campaign->id, 'listed' => true]);
    $lists = ['881204' => ['501', '40123']];
    popAdsWithLists($lists);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists['881204'])->toBe(['501'])
        ->and($own->fresh())->toBeNull()
        ->and($panel->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied);

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $panel);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists['881204'])->toBe([])
        ->and($panel->fresh())->toBeNull();
});

it('keeps a site blocked in the panel whose unblock a campaign refused blocked there, and forgets it once taken off that list in the panel', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour(), 'backfill_cursor' => null]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $panel = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '501', 'method' => TrafficBlockMethod::Panel, 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $panel->id, 'campaign_id' => $first->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $panel->id, 'campaign_id' => $second->id, 'listed' => true]);
    $lists = ['881204' => ['501'], '881205' => ['501', '777']];
    popAdsWithLists($lists, ['881205']);

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $panel);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    // Still blocked in the panel, on the one list that kept it — never "partly blocked", which is for RateGuru's own blocks.
    expect($panel->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBe('rejected')->method->toBe(TrafficBlockMethod::Panel)
        ->and($lists)->toBe(['881204' => [], '881205' => ['501', '777']]);

    $lists['881205'] = ['777'];
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());

    expect($panel->fresh())->toBeNull();
});

it('counts a click from a site blocked in the panel as still sent only through a campaign whose list holds it', function () {
    $holding = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $other = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $panel = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '501', 'method' => TrafficBlockMethod::Panel, 'network_state' => TrafficBlockNetworkState::Applied, 'blocked_at' => now()->subHours(2)]);
    TrafficBlockListing::factory()->create(['block_id' => $panel->id, 'campaign_id' => $holding->id, 'listed' => true]);
    $click = fn (TrafficCampaign $campaign): TrafficVisit => TrafficVisit::factory()->create(['source_id' => $this->popads->id, 'campaign_id' => $campaign->id, 'site_ref' => '501', 'arrived_at' => now()]);

    app(RecordBlockedHitAction::class)->handle($through = $click($other));

    expect($through->fresh()->blocked_hit)->toBeFalse()
        ->and($panel->fresh()->clicks_after)->toBe(0);

    app(RecordBlockedHitAction::class)->handle($held = $click($holding));

    expect($held->fresh()->blocked_hit)->toBeTrue()
        ->and($panel->fresh()->clicks_after)->toBe(1);
});

it('counts no click whose campaign it can’t name against a site blocked in the panel, and still counts it against RateGuru’s own block', function () {
    recordTraffic();
    $holding = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = function (string $ref, TrafficBlockMethod $method) use ($holding): TrafficBlock {
        $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'method' => $method, 'network_state' => TrafficBlockNetworkState::Applied, 'blocked_at' => now()->subHours(2)]);
        TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $holding->id, 'listed' => true]);

        return $block;
    };
    $panel = $block('501', TrafficBlockMethod::Panel);
    $own = $block('502', TrafficBlockMethod::Api);

    // A link without the campaign's macro: the click names no campaign.
    clickOn('/?rg_src=pop&site=501')->assertOk();
    clickOn('/?rg_src=pop&site=502', 'browserBbrowserBbrowserBbrowserBbrowserB')->assertOk();

    expect(TrafficVisit::query()->where('site_ref', '501')->sole())->campaign_id->toBeNull()->blocked_hit->toBeFalse()
        ->and($panel->fresh()->clicks_after)->toBe(0)
        ->and(TrafficVisit::query()->where('site_ref', '502')->sole())->campaign_id->toBeNull()->blocked_hit->toBeTrue()
        ->and($own->fresh()->clicks_after)->toBe(1);
});

it('no longer holds a block to a campaign that ended without taking it', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $running = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $ended = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205', 'status' => TrafficCampaignStatus::Archived]);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'rejected']);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $running->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $ended->id, 'listed' => false, 'error' => 'rejected']);
    $lists = ['881204' => ['40123']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBeNull()
        ->and($block->listings()->pluck('campaign_id')->all())->toBe([$running->id]);
});

it('keeps a block taken off a campaign in the panel off it once that campaign ends, and never sends it to another for that', function (bool $otherHeldIt) {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $running = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $ended = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205', 'status' => TrafficCampaignStatus::Archived]);
    // Taken off the ended campaign's list in the panel; the running one holds it, or came after and was rightly never sent it.
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => $otherHeldIt ? TrafficBlockNetworkState::Partial : TrafficBlockNetworkState::TakenOff]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $running->id, 'listed' => $otherHeldIt, 'error' => null]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $ended->id, 'listed' => false, 'error' => TrafficBlockListing::TAKEN_OFF]);
    $lists = ['881204' => $otherHeldIt ? ['40123'] : ['111'], '881205' => ['111']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    // Back from the archive with a click.
    $ended->forceFill(['status' => TrafficCampaignStatus::Active])->save();
    $this->travel(7)->hours();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => $otherHeldIt ? ['40123'] : ['111'], '881205' => ['111']])
        ->and($block->listings()->where('campaign_id', $ended->id)->sole())->listed->toBeFalse()->error->toBe(TrafficBlockListing::TAKEN_OFF);
})->with([
    'another campaign holding it' => true,
    'another campaign that came after' => false,
]);

it('keeps every site an administrator unblocked lately apart, however alike their IDs read as numbers', function () {
    foreach (['1', '01'] as $ref) {
        TrafficBlockEvent::query()->create(['source_id' => $this->popads->id, 'kind' => 'unblock', 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'ref_count' => 1, 'refs' => [$ref], 'method' => TrafficBlockMethod::Api, 'actor' => TrafficActor::User, 'actor_user_id' => $this->admin->id, 'ok' => true, 'occurred_at' => now()]);
    }
    TrafficBlockEvent::query()->create(['source_id' => $this->popads->id, 'kind' => 'unblock', 'level' => TrafficBlockLevel::Site, 'ref' => '1', 'ref_count' => 1, 'refs' => ['1'], 'method' => TrafficBlockMethod::Api, 'actor' => TrafficActor::User, 'actor_user_id' => $this->admin->id, 'ok' => true, 'occurred_at' => now()]);

    expect(app(TrafficBlocksQuery::class)->unblockedLately((int) $this->popads->id, TrafficBlockLevel::Site, now()))->toEqualCanonicalizing(['1', '01']);
});

it('never takes a list read before a block went on it for one the block was taken off', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $block = TrafficBlock::query()->sole();
    $lists = ['881204' => [], '881205' => []];
    $overlapping = true;
    Http::fake(function (Request $request) use (&$lists, &$overlapping, $connection) {
        if (! preg_match('#/api/campaign_website_targeting/(\w+)#', $request->url(), $match)) {
            return Http::response(str_contains($request->url(), 'report_advertiser') ? ['rows' => []] : ['data' => []]);
        }

        if ($request->method() === 'POST') {
            $lists[$match[1]] = str_contains($request->url(), 'mode=none') ? [] : array_values(array_filter(explode("\n", $request->body())));
        } elseif ($match[1] === '881205' && $overlapping) {
            // A second sync, its lock run out, sends the block while this one reads the lists: 881204's was read before it.
            $overlapping = false;
            app(ApplyTrafficBlocksAction::class)->handle($connection->fresh());
        }

        return Http::response($lists[$match[1]] === [] ? ['mode' => 'none', 'items' => []] : ['mode' => 'exclude', 'items' => $lists[$match[1]]]);
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($lists)->toBe(['881204' => ['40123'], '881205' => ['40123']])
        ->and($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and(TrafficBlockEvent::query()->where('kind', 'gone')->exists())->toBeFalse();
});

it('never takes a site a block unblocked while the lists were read was on for one blocked in the panel', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $first = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $second = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Removing]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $first->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $second->id, 'listed' => true]);
    $lists = ['881204' => ['40123'], '881205' => ['40123']];
    $overlapping = true;
    Http::fake(function (Request $request) use (&$lists, &$overlapping, $connection) {
        if (! preg_match('#/api/campaign_website_targeting/(\w+)#', $request->url(), $match)) {
            return Http::response(str_contains($request->url(), 'report_advertiser') ? ['rows' => []] : ['data' => []]);
        }

        if ($request->method() === 'POST') {
            $lists[$match[1]] = str_contains($request->url(), 'mode=none') ? [] : array_values(array_filter(explode("\n", $request->body())));
        } elseif ($match[1] === '881205' && $overlapping) {
            // Another sync takes the block off both lists, and deletes it, after this one read 881204's.
            $overlapping = false;
            app(ApplyTrafficBlocksAction::class)->handle($connection->fresh());
        }

        return Http::response($lists[$match[1]] === [] ? ['mode' => 'none', 'items' => []] : ['mode' => 'exclude', 'items' => $lists[$match[1]]]);
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($lists)->toBe(['881204' => [], '881205' => []])
        ->and(TrafficBlock::query()->exists())->toBeFalse()
        ->and(TrafficBlockEvent::query()->where('kind', 'found')->exists())->toBeFalse();
});

it('keeps an ended campaign among those an unblock takes a block off while the network may have put it there', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $running = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $ended = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205', 'status' => TrafficCampaignStatus::Archived]);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'timeout']);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $running->id, 'listed' => true]);
    // The add timed out, and landed all the same, before the campaign ended.
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $ended->id, 'listed' => false, 'error' => 'timeout']);
    $lists = ['881204' => ['40123'], '881205' => ['40123']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($block->listings()->where('campaign_id', $ended->id)->sole())->listed->toBeFalse()->error->toBe('timeout');

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block->fresh());
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists)->toBe(['881204' => [], '881205' => []])
        ->and($block->fresh())->toBeNull();
});

it('judges a PopAds change by the sites its answer lists, an entry in it that is no site ID left out', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour(), 'block_lists_read_at' => now()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(['mode' => 'exclude', 'items' => ['111', '40123', null]])
        : Http::response(['mode' => 'exclude', 'items' => ['111']]));
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect(TrafficBlock::query()->sole())->network_state->toBe(TrafficBlockNetworkState::Applied)
        ->and(TrafficBlockListing::query()->sole())->listed->toBeTrue()->error->toBeNull();
});

it('keeps what a block from before the lists were kept said when the lists are first read', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    // An unblock the network refused, the block still on both lists.
    $stuck = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied, 'network_error' => 'rejected']);
    // Not every campaign took it, the API being down: one list holds it.
    $failed = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40124', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Failed, 'network_error' => 'unavailable']);
    $lists = ['881204' => ['40123', '40124'], '881205' => ['40123']];
    popAdsWithLists($lists);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($stuck->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBe('rejected')
        ->and($failed->fresh())->network_state->toBe(TrafficBlockNetworkState::Partial)->network_error->toBe('unavailable')
        ->and($failed->listings()->get()->map(fn (TrafficBlockListing $listing): array => [$listing->listed, $listing->error])->all())->toBe([[true, null], [false, 'unavailable']]);
});

it('takes a block from before the lists were kept off the campaign it names, even one that has ended', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $ended = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204', 'status' => TrafficCampaignStatus::Archived]);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'campaign_id' => $ended->id, 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Removing]);
    $lists = ['881204' => ['111', '40123']];
    popAdsWithLists($lists);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($lists['881204'])->toBe(['111'])
        ->and($block->fresh())->toBeNull();
});

it('keeps the sync going when the campaigns’ block lists can’t be read, but not with a key the network refused', function (Closure $targeting, TrafficConnectionStatus $status, int $failures) {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), 'report_advertiser') => Http::response(['rows' => []]),
        str_contains($request->url(), 'campaign_website_targeting') => $targeting(),
        default => Http::response(['data' => []]),
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($connection->fresh())->status->toBe($status)->failures->toBe($failures)
        ->and(TrafficBlock::query()->count())->toBe(0);

    if ($status === TrafficConnectionStatus::Connected) {
        // The spend and campaigns were read, and the lists wait for their next read rather than every turn.
        expect($connection->fresh())->last_error->toBeNull()->campaigns_synced_at->not->toBeNull()
            ->block_lists_read_at?->toDateTimeString()->toBe('2026-11-17 12:00:00');
    }
})->with([
    'a list without its sites' => [fn () => Http::response(['mode' => 'exclude']), TrafficConnectionStatus::Connected, 0],
    'the API down' => [fn () => Http::response('Bad gateway', 502), TrafficConnectionStatus::Connected, 0],
    'a refused key' => [fn () => Http::response('Unauthorized', 401), TrafficConnectionStatus::NeedsAttention, 1],
    'a list of what are no site IDs' => [fn () => Http::response(['mode' => 'exclude', 'items' => [['id' => 40123]]]), TrafficConnectionStatus::Connected, 0],
]);

it('never reads a PopAds list with an empty entry as one its blocks were taken off', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true]);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), 'report_advertiser') => Http::response(['rows' => []]),
        str_contains($request->url(), 'campaign_website_targeting') => Http::response(['mode' => 'exclude', 'items' => [null]]),
        default => Http::response(['data' => []]),
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and($block->listings()->sole())->listed->toBeTrue()->error->toBeNull()
        ->and($connection->fresh())->block_lists_error->toBe('unexpected_response')->block_lists_error_campaign->toBe('881204')->failures->toBe(0);
});

it('says in the Blocking pane whose block list couldn’t be read, when and why, until a read goes through', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204', 'name' => 'US popunder']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205', 'name' => 'EU popunder']);
    $down = true;
    Http::fake(function (Request $request) use (&$down) {
        return match (true) {
            str_contains($request->url(), 'report_advertiser') => Http::response(['rows' => []]),
            $down && str_contains($request->url(), 'campaign_website_targeting/881205') => Http::response('Bad gateway', 502),
            str_contains($request->url(), 'campaign_website_targeting') => Http::response(['mode' => 'none', 'items' => []]),
            default => Http::response(['data' => []]),
        };
    });
    $this->actingAs($this->admin);
    $pane = fn () => Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $this->popads->id])->test(TrafficSourcesPage::class);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->last_error->toBeNull()
        ->block_lists_error->toBe('unavailable')->block_lists_error_campaign->toBe('881205');
    $pane()->assertSeeHtmlInOrder(['data-testid="traffic-source-blocking-status"', 'data-testid="traffic-source-block-lists-error"'])
        ->assertSee('Couldn’t read EU popunder’s block list at 17 Nov 12:00: The network’s API is down. RateGuru tries again later by itself.');

    // Disconnected, nothing reads the lists to try again: the pane says so instead.
    $connection->fresh()->forceFill(['status' => TrafficConnectionStatus::Disconnected])->save();
    $pane()->assertDontSee('Couldn’t read')->assertSee('No API connected');
    $connection->fresh()->forceFill(['status' => TrafficConnectionStatus::Connected])->save();

    // The next read goes through: nothing left to say.
    $down = false;
    $this->travel(6)->hours();
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());

    expect($connection->fresh())->block_lists_error->toBeNull()->block_lists_error_campaign->toBeNull();
    $pane()->assertDontSee('traffic-source-block-lists-error')->assertDontSee('Couldn’t read');
});

it('says the same of where each block stands in its badge, the Blocking pane, the Blocked segment, Worth blocking and Logs → Blocks', function () {
    $this->popads->forceFill(['block_bot_clicks' => 30])->save();
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour(), 'backfill_cursor' => null]);
    $us = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204', 'name' => 'US popunder']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205', 'name' => 'EU popunder']);
    $sites = ['601', '602', '603', '604', '605', '606', '607'];

    // Bots, every one, by the rule's reckoning — 40 clicks, 1 opening the page — so Worth blocking offers any not blocked.
    foreach ($sites as $site) {
        TrafficVisit::factory()->count(40)->sequence(fn ($sequence): array => ['opened_at' => $sequence->index < 1 ? now() : null])->create([
            'source_id' => $this->popads->id, 'campaign_id' => $us->id, 'site_ref' => $site, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'spend' => '0.01',
        ]);
    }

    $lists = ['881204' => [], '881205' => []];
    $refusing = [];
    popAdsWithLists($lists, function () use (&$refusing): array {
        return $refusing;
    });
    $block = function (string $site, array $refusingNow) use (&$refusing): void {
        $refusing = $refusingNow;
        app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, [$site], null, true);
        $this->artisan('traffic:sync-networks')->assertSuccessful();
    };
    $block('601', []);
    $block('602', ['881205']);
    $block('603', ['881204', '881205']);
    $block('604', []);
    // In the panel since: 604 taken off both lists, 605 blocked on one — what the next read of the lists sees.
    $lists = ['881204' => [...array_values(array_diff($lists['881204'], ['604'])), '605'], '881205' => array_values(array_diff($lists['881205'], ['604']))];
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now());
    // Waiting for the API; 607 left alone.
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['606'], null, true);

    // Each block: its badge, the pane's count it falls under, and the last line of the blocks log about it.
    $expected = [
        '601' => ['Blocked', 'applied', ['applied', true, false]],
        '602' => ['Partly blocked · 1 of 2 campaigns', 'partial', ['applied', false, true]],
        '603' => ['Not blocked on the network', 'failed', ['applied', false, false]],
        '604' => ['Taken off on the network', 'taken_off', ['gone', true, false]],
        '605' => ['Blocked on PopAds', 'in_panel', ['found', true, false]],
        '606' => ['Blocking…', 'pending', ['queued', true, false]],
    ];
    $this->actingAs($this->admin);
    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'source' => $this->popads->id])->test(TrafficSourcesPage::class)->call('load');
    $counts = app(TrafficBlocksQuery::class)->counts((int) $this->popads->id, now());
    $flags = collect($page->set('segment', 'blocked')->viewData('list')['rows'])->mapWithKeys(fn (array $row): array => [$row['ref'] => $row['flag']['label'] ?? null])->all();

    foreach ($expected as $site => [$label, $bucket, $line]) {
        $last = TrafficBlockEvent::query()->where('ref', $site)->orderByDesc('id')->first();

        expect($flags[$site] ?? null)->toBe($label)
            ->and($counts[$bucket])->toBe(1)
            ->and([$last?->kind, $last?->ok, $last?->partial])->toBe($line);
    }

    // Applied, partly blocked and failed are apart: each counted once, never twice.
    expect($counts)->toMatchArray(['sites' => 6, 'zones' => 0, 'applied' => 1, 'partial' => 1, 'failed' => 1, 'taken_off' => 1, 'in_panel' => 1, 'pending' => 1, 'removing' => 0, 'marked' => 0])
        ->and($page->viewData('list')['counts'])->toMatchArray(['blocked' => 6, 'block' => 1])
        ->and(array_keys($flags))->toEqualCanonicalizing(array_keys($expected))
        ->and($page->viewData('worthBlocking'))->toBe(1);

    Livewire::withQueryParams(['tab' => 'logs', 'log' => 'blocks'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Queued', 'Taken off in the panel', 'Found in the panel', 'Applied', 'Done', 'Applied', 'Failed', 'Applied', 'Partly done', 'Applied', 'Done']);
});

it('locks the source before the blocks when it writes where a batch stands, as every writer of blocks does', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite takes no row locks.');
    }

    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $lists = ['881204' => []];
    popAdsWithLists($lists);
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $sql = [];
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    $first = function (string $pattern) use (&$sql): ?int {
        $index = collect($sql)->search(fn (string $statement): bool => preg_match($pattern, $statement) === 1);

        return $index === false ? null : $index;
    };

    expect($first('/^update .?traffic_blocks.? set/'))->not->toBeNull()
        ->and($first('/from .?traffic_sources.? .*for update/'))->not->toBeNull()
        ->and($first('/from .?traffic_sources.? .*for update/'))->toBeLessThan($first('/^insert into .?traffic_block_listings/'))
        ->and($first('/from .?traffic_sources.? .*for update/'))->toBeLessThan($first('/^update .?traffic_blocks.? set/'));
});

it('reads each ExoClick campaign’s blocked zones during the sync the same way, never rewriting them', function () {
    $exo = TrafficSource::factory()->create(['connector' => TrafficConnector::ExoClick, 'block_level' => TrafficBlockLevel::Zone]);
    $connection = TrafficApiConnection::factory()->create(['source_id' => $exo->id, 'connector' => TrafficConnector::ExoClick, 'credentials' => ['api_token' => 't'], 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $exo->id, 'external_id' => '555']);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/login') => Http::response(['token' => 's1', 'type' => 'Bearer', 'expires_in' => 3600]),
        str_contains($request->url(), '/statistics') => Http::response(['result' => [], 'result_total' => ['cost' => 0]]),
        str_contains($request->url(), '/campaigns/555') => Http::response(['result' => ['id' => 555, 'zone_targeting' => ['type' => 0], 'zones' => [['idzone' => 10], ['idzone' => 20]]]]),
        default => Http::response(['result' => []]),
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), '/campaigns'));
    $found = TrafficBlock::query()->orderBy('ref')->get();

    expect($found->pluck('ref')->all())->toBe(['10', '20'])
        ->and($found->pluck('level')->unique()->all())->toBe([TrafficBlockLevel::Zone])
        ->and($found->pluck('method')->unique()->all())->toBe([TrafficBlockMethod::Panel])
        ->and(TrafficBlockListing::query()->where('campaign_id', $campaign->id)->where('listed', true)->count())->toBe(2)
        ->and($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->failures->toBe(0);
});

it('names the ExoClick campaign whose blocked zones couldn’t be read, for the Blocking pane, failing nothing else', function () {
    $exo = TrafficSource::factory()->create(['connector' => TrafficConnector::ExoClick, 'block_level' => TrafficBlockLevel::Zone]);
    $connection = TrafficApiConnection::factory()->create(['source_id' => $exo->id, 'connector' => TrafficConnector::ExoClick, 'credentials' => ['api_token' => 't'], 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $read = TrafficCampaign::factory()->create(['source_id' => $exo->id, 'external_id' => '555']);
    TrafficCampaign::factory()->create(['source_id' => $exo->id, 'external_id' => '556']);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/login') => Http::response(['token' => 's1', 'type' => 'Bearer', 'expires_in' => 3600]),
        str_contains($request->url(), '/statistics') => Http::response(['result' => [], 'result_total' => ['cost' => 0]]),
        str_contains($request->url(), '/campaigns/555') => Http::response(['result' => ['id' => 555, 'zone_targeting' => ['type' => 0], 'zones' => [['idzone' => 10]]]]),
        str_contains($request->url(), '/campaigns/556') => Http::response('Bad gateway', 502),
        default => Http::response(['result' => []]),
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->failures->toBe(0)->last_error->toBeNull()
        ->block_lists_error->toBe('unavailable')->block_lists_error_campaign->toBe('556');

    // The list read before it is recorded all the same.
    $found = TrafficBlock::query()->where('source_id', $exo->id)->sole();

    expect($found)->ref->toBe('10')->method->toBe(TrafficBlockMethod::Panel)
        ->and($found->listings()->sole())->campaign_id->toBe($read->id)->listed->toBeTrue();
});

it('reads every other PopAds campaign’s list when one comes without its sites, naming that one', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $read = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), 'report_advertiser') => Http::response(['rows' => []]),
        str_contains($request->url(), 'campaign_website_targeting/881204') => Http::response(['mode' => 'exclude']),
        str_contains($request->url(), 'campaign_website_targeting/881205') => Http::response(['mode' => 'exclude', 'items' => ['777']]),
        default => Http::response(['data' => []]),
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'campaign_website_targeting/881205'));
    $found = TrafficBlock::query()->sole();

    expect($found)->ref->toBe('777')->method->toBe(TrafficBlockMethod::Panel)
        ->and($found->listings()->sole())->campaign_id->toBe($read->id)->listed->toBeTrue()
        ->and($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->failures->toBe(0)
        ->block_lists_error->toBe('unexpected_response')->block_lists_error_campaign->toBe('881204');
});

it('stops reading the lists once the network is down, slow or busy, recording those read before it', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $read = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881206']);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), 'report_advertiser') => Http::response(['rows' => []]),
        str_contains($request->url(), 'campaign_website_targeting/881204') => Http::response(['mode' => 'exclude', 'items' => ['777']]),
        str_contains($request->url(), 'campaign_website_targeting/881205') => Http::response('', 503),
        default => Http::response(['data' => []]),
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    // The rest wait for the next read rather than a call each to an API that is down.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'campaign_website_targeting/881206'));
    expect(TrafficBlock::query()->sole())->ref->toBe('777')
        ->and(TrafficBlockListing::query()->sole())->campaign_id->toBe($read->id)
        ->and($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)
        ->block_lists_error->toBe('unavailable')->block_lists_error_campaign->toBe('881205');
});

it('takes a PopAds block off only when the list read back shows it gone, never when the read back has no list', function (array $readBack, bool $removed) {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '501', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Removing]);
    $reads = 0;
    Http::fake(function (Request $request) use (&$reads, $readBack) {
        if ($request->method() === 'POST') {
            return Http::response(['result' => '1']);
        }

        return Http::response(++$reads === 1 ? ['mode' => 'exclude', 'items' => ['501']] : $readBack);
    });

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($reads)->toBe(2);
    $removed
        ? expect($block->fresh())->toBeNull()
        : expect($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBe('unexpected_response');
})->with([
    'a read back without the list' => [['mode' => 'exclude'], false],
    'a read back of an empty list' => [['mode' => 'exclude', 'items' => []], true],
    'a read back of the targeting off' => [['mode' => 'none'], true],
]);

it('unblocks a campaign’s last PopAds sites by turning its targeting off, never by sending an empty list to exclude', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $blocks = collect(['5086547', '1663301'])->map(fn (string $ref) => TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Removing]));
    Http::fake(function (Request $request) {
        if ($request->method() === 'POST') {
            // PopAds refuses a list to exclude without a site: a validation error, sent with HTTP 500.
            return str_contains($request->url(), 'mode=exclude') && trim($request->body()) === ''
                ? Http::response(['errors' => [['status' => 500, 'code' => 62, 'title' => 'Line "" unrecognized as website ID']]], 500)
                : Http::response(['id' => '881204', 'mode' => 'none', 'global_id' => null]);
        }

        return Http::response(['id' => '881204', 'mode' => 'exclude', 'global_id' => null, 'items' => ['5086547', '1663301']]);
    });

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'mode=none') && $request->body() === '');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'mode=exclude'));
    expect(TrafficBlock::query()->whereKey($blocks->pluck('id'))->exists())->toBeFalse()
        ->and(TrafficBlockEvent::query()->where('kind', 'removed')->sole())->ok->toBeTrue()->note->toBe('1 of 1 campaigns');
});

it('excludes only the sites it adds on a PopAds campaign with its targeting off, never the sites saved there, and writes nothing to take one off', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $posted = [];
    Http::fake(function (Request $request) use (&$posted) {
        if ($request->method() === 'POST') {
            $posted[] = $request->body();

            return Http::response(['mode' => 'exclude', 'items' => explode("\n", $request->body())]);
        }

        // Targeting turned off in the panel, the sites it excluded still saved.
        return Http::response(['mode' => 'none', 'items' => ['5', '6']]);
    });
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($posted)->toBe(['40123'])
        ->and(TrafficBlock::query()->sole()->network_state)->toBe(TrafficBlockNetworkState::Applied);

    TrafficBlock::query()->sole()->forceFill(['network_state' => TrafficBlockNetworkState::Removing])->save();
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($posted)->toBe(['40123'])
        ->and(TrafficBlock::query()->exists())->toBeFalse();
});

it('leaves blocked the last sites of a list other campaigns share, saying why, and sends it no empty list', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '501', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Removing]);
    Http::fake(fn (Request $request) => Http::response(['mode' => 'global', 'global_mode' => 'exclude', 'global_id' => 7, 'global_items' => ['501']]));

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    expect($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBe('shared_list');
});

it('takes an error PopAds explains for a refusal, not for an API that is down, whatever its status', function (int $status, array|string $answer, string $error) {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => $request->method() === 'POST'
        ? Http::response($answer, $status)
        : Http::response(['mode' => 'exclude', 'items' => ['111']]));

    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect(TrafficBlock::query()->sole())->network_state->toBe(TrafficBlockNetworkState::Failed)->network_error->toBe($error)
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->sole())->ok->toBeFalse()->http_status->toBe($status);
})->with([
    'a site it won’t take, sent with 500' => [500, ['errors' => [['status' => 500, 'code' => 62, 'title' => '"40123" is not an active website ID']]], 'rejected'],
    'a refusal it explains, sent with 403' => [403, ['errors' => [['status' => 403, 'code' => 0, 'title' => 'Campaign not found']]], 'rejected'],
    'a refusal it explains, sent with 429' => [429, ['errors' => [['status' => 429, 'code' => 0, 'title' => 'Too many website IDs']]], 'rejected'],
    'a key it refuses, unexplained' => [403, '403 Forbidden (API Key Not Valid)', 'auth'],
    'a call it wants slower, unexplained' => [429, '', 'rate_limited'],
    'an API that is down' => [503, '', 'unavailable'],
]);

it('sends no PopAds exclusion list rewritten from a targeting read without one', function (array $targeting) {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => Http::response($targeting));

    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    expect(TrafficBlock::query()->sole())->network_state->toBe(TrafficBlockNetworkState::Failed)->network_error->toBe('unexpected_response');
})->with([
    'a list without its sites' => [['mode' => 'exclude']],
    'an answer without its mode' => [['id' => '881204']],
]);

it('never takes a PopAds targeting answer without its mode for an empty list', function () {
    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now(), 'backfill_cursor' => null]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true, 'error' => null]);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), 'report_advertiser') => Http::response(['rows' => []]),
        str_contains($request->url(), 'campaign_website_targeting') => Http::response(['id' => '881204']),
        default => Http::response(['data' => []]),
    });

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'targeting'));
    expect($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Applied)->network_error->toBeNull()
        ->and($block->listings()->sole())->listed->toBeTrue()->error->toBeNull()
        ->and($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)
        ->block_lists_error->toBe('unexpected_response')->block_lists_error_campaign->toBe('881204');
});

it('blocks ExoClick zones by rewriting the campaign’s list with the zones it read', function () {
    [$exo, $sent] = exoClickBlocking(['zones' => [['idzone' => 10], ['idzone' => 20]]]);

    app(BlockTrafficRefsAction::class)->block($this->admin, $exo, TrafficBlockLevel::Zone, ['30'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($sent())->toBe([10, 20, 30])
        ->and(TrafficBlock::query()->sole()->network_state)->toBe(TrafficBlockNetworkState::Applied);
});

it('sends no ExoClick list rewritten from campaign details without one', function () {
    [$exo, $sent] = exoClickBlocking([]);

    app(BlockTrafficRefsAction::class)->block($this->admin, $exo, TrafficBlockLevel::Zone, ['30'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($sent())->toBeNull()
        ->and(TrafficBlock::query()->sole())->network_state->toBe(TrafficBlockNetworkState::Failed)->network_error->toBe('unexpected_response');
});

it('takes blocks off the network and puts others on in one change, leaving one blocked again meanwhile waiting', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $block = fn (string $ref, TrafficBlockNetworkState $state) => TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'method' => 'api', 'network_state' => $state]);
    $adding = $block('40123', TrafficBlockNetworkState::Pending);
    $going = $block('501', TrafficBlockNetworkState::Removing);
    $again = $block('502', TrafficBlockNetworkState::Removing);
    Http::fake(function (Request $request) use ($again) {
        if ($request->method() === 'POST') {
            // Blocked again while the network was being called.
            TrafficBlock::query()->whereKey($again->id)->update(['network_state' => TrafficBlockNetworkState::Pending]);

            return Http::response(['mode' => 'exclude', 'items' => explode("\n", $request->body())]);
        }

        return Http::response(['mode' => 'exclude', 'items' => ['501', '502']]);
    });

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($adding->fresh()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and($going->fresh())->toBeNull()
        ->and($again->fresh()->network_state)->toBe(TrafficBlockNetworkState::Pending);
});

it('keeps a block unblocked and blocked again while the network was being called waiting for its own batch, which sends it to the campaign that refused it', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $block = TrafficBlock::query()->sole();
    $meanwhile = true;
    $posted = [];
    Http::fake(function (Request $request) use ($block, &$meanwhile, &$posted) {
        if ($request->method() !== 'POST') {
            return Http::response(['mode' => 'exclude', 'items' => []]);
        }

        $posted[] = basename((string) parse_url($request->url(), PHP_URL_PATH));

        if ($meanwhile) {
            $meanwhile = false;
            app(BlockTrafficRefsAction::class)->unblock($this->admin, $block);
            app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
        }

        // 881205 doesn't take it.
        return Http::response(['mode' => 'exclude', 'items' => end($posted) === '881205' ? [] : explode("\n", $request->body())]);
    });

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Pending)
        ->and($posted)->toBe(['881204', '881205']);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($posted)->toBe(['881204', '881205', '881204', '881205'])
        ->and($block->fresh()->network_state)->toBe(TrafficBlockNetworkState::Partial);
});

it('keeps a block blocked and unblocked again while the network was being called waiting to come off the list it may still be on', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    $taken = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    $slow = TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881205']);
    $block = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $taken->id, 'listed' => true]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $slow->id, 'listed' => true]);
    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block);
    $meanwhile = true;
    $posted = [];
    Http::fake(function (Request $request) use ($block, &$meanwhile, &$posted) {
        if ($request->method() !== 'POST') {
            return Http::response(['mode' => 'exclude', 'items' => ['40123', '7']]);
        }

        $posted[] = basename((string) parse_url($request->url(), PHP_URL_PATH));

        if ($meanwhile) {
            $meanwhile = false;
            app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
            app(BlockTrafficRefsAction::class)->unblock($this->admin, $block);
        }

        // 881205 is down for this batch only.
        return count($posted) === 2 ? Http::response('', 503) : Http::response(['mode' => 'exclude', 'items' => ['7']]);
    });

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($block->fresh()?->network_state)->toBe(TrafficBlockNetworkState::Removing)
        ->and($posted)->toBe(['881204', '881205']);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect($posted)->toBe(['881204', '881205', '881205'])
        ->and($block->fresh())->toBeNull();
});

it('leaves blocks waiting when the batch’s line of the log can’t be written, and logs them once when they go through', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => Http::response(['mode' => 'exclude', 'items' => $request->method() === 'POST' ? explode("\n", $request->body()) : []]));
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $failed = false;
    TrafficBlockEvent::creating(function () use (&$failed): void {
        if (! $failed) {
            $failed = true;

            throw new RuntimeException('The log is unavailable.');
        }
    });

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect(TrafficBlock::query()->sole()->network_state)->toBe(TrafficBlockNetworkState::Pending)
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->count())->toBe(0);

    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect(TrafficBlock::query()->sole()->network_state)->toBe(TrafficBlockNetworkState::Applied)
        ->and(TrafficBlockEvent::query()->where('kind', 'applied')->count())->toBe(1);
});

it('fails a block a campaign that lists sites to allow can’t take', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(['www.popads.net/*' => Http::response(['mode' => 'include', 'items' => ['5']])]);

    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    expect(TrafficBlock::query()->sole())->network_state->toBe(TrafficBlockNetworkState::Failed)->network_error->toBe('unsupported');
});

it('marks a block by hand, and counts the clicks the network still sends an hour later', function () {
    recordTraffic();
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, false);
    $block = TrafficBlock::query()->sole();

    expect($block->network_state)->toBe(TrafficBlockNetworkState::ByHand);

    $this->travel(61)->minutes();
    clickOn('/?rg_src=pop&cid=c9&site=40123')->assertOk();

    expect(TrafficVisit::query()->sole()->blocked_hit)->toBeTrue()
        ->and($block->fresh()->clicks_after)->toBe(1);

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $block);
    expect(TrafficBlock::query()->count())->toBe(0);
});

it('counts a click from a blocked site in a blocked zone on both blocks', function () {
    recordTraffic();
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, false);
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Zone, ['77'], null, false);

    $this->travel(61)->minutes();
    clickOn('/?rg_src=pop&cid=c10&site=40123&zone=77')->assertOk();

    expect(TrafficVisit::query()->sole()->blocked_hit)->toBeTrue()
        ->and(TrafficBlock::query()->orderBy('level')->pluck('clicks_after', 'level')->all())->toBe(['site' => 1, 'zone' => 1]);
});

it('counts a blocked click on top of one counted meanwhile, never over it', function () {
    recordTraffic();
    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, false);
    $block = TrafficBlock::query()->sole();
    $this->travel(61)->minutes();

    // Five more clicks of the site are counted while this one is on its way.
    $counted = false;
    DB::listen(function (QueryExecuted $query) use (&$counted, $block): void {
        if (! $counted && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'blocked_hit')) {
            $counted = true;
            DB::table('traffic_blocks')->where('id', $block->id)->increment('clicks_after', 5);
        }
    });
    clickOn('/?rg_src=pop&cid=c11&site=40123')->assertOk();

    expect($counted)->toBeTrue()
        ->and($block->fresh()->clicks_after)->toBe(6)
        ->and($block->fresh()->last_click_after_at->toDateTimeString())->toBe('2026-11-17 13:01:00');
});

it('takes a block off the network before it goes, leaves one already going alone, and keeps one blocked again', function () {
    $failed = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '501', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Failed]);
    $going = TrafficBlock::factory()->create(['source_id' => $this->popads->id, 'level' => TrafficBlockLevel::Site, 'ref' => '502', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Removing]);

    app(BlockTrafficRefsAction::class)->unblock($this->admin, $failed);
    app(BlockTrafficRefsAction::class)->unblock($this->admin, $going);

    // A block that failed part-way may be on some campaigns: it is taken off there, not just forgotten.
    expect($failed->fresh()->network_state)->toBe(TrafficBlockNetworkState::Removing)
        ->and($going->fresh()->network_state)->toBe(TrafficBlockNetworkState::Removing);

    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['502'], null, false);

    expect($going->fresh()->network_state)->toBe(TrafficBlockNetworkState::Pending);
});

it('blocks by itself the sites the rule calls bots, within the day’s cap, only with a verified API', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    // Its sites have a few dozen clicks each: it judges bots from 30, not the general 100.
    $this->popads->forceFill(['block_auto' => true, 'block_daily_cap' => 2, 'block_bot_clicks' => 30])->save();

    foreach (['s1' => 40, 's2' => 35, 's3' => 31] as $site => $clicks) {
        TrafficVisit::factory()->count($clicks)->create(['source_id' => $this->popads->id, 'site_ref' => $site, 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'opened_at' => null, 'spend' => '0.01']);
    }

    // A sound site, every click opening the page: the source's own average, which the bots fall far below.
    TrafficVisit::factory()->count(106)->create(['source_id' => $this->popads->id, 'site_ref' => 'sound', 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'opened_at' => now()->subHour(), 'spend' => '0.01']);

    $this->artisan('traffic:block-losers')->assertSuccessful();
    expect(TrafficBlock::query()->count())->toBe(0);

    config(['traffic_presets.connectors.popads.verified' => true]);
    $this->artisan('traffic:block-losers')->assertSuccessful();

    expect(TrafficBlock::query()->orderBy('ref')->pluck('ref')->all())->toBe(['s1', 's2'])
        ->and(TrafficBlock::query()->first())->method->toBe(TrafficBlockMethod::Auto)->network_state->toBe(TrafficBlockNetworkState::Pending);
});

/**
 * A connected ExoClick source blocking zones of its campaign 555, whose
 * details first answer with these fields, then with the zones last sent.
 *
 * @return array{0: TrafficSource, 1: Closure(): (list<int>|null)} the source, and the zones the last rewrite sent
 */
function exoClickBlocking(array $details): array
{
    $exo = TrafficSource::factory()->create(['connector' => TrafficConnector::ExoClick, 'block_level' => TrafficBlockLevel::Zone]);
    TrafficApiConnection::factory()->create(['source_id' => $exo->id, 'connector' => TrafficConnector::ExoClick, 'credentials' => ['api_token' => 't'], 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $exo->id, 'external_id' => '555']);
    $sent = null;
    Http::fake(function (Request $request) use ($details, &$sent) {
        if (str_contains($request->url(), '/login')) {
            return Http::response(['token' => 's1', 'type' => 'Bearer', 'expires_in' => 3600]);
        }

        if ($request->method() === 'PUT') {
            $sent = $request->data()['zones'];

            return Http::response(['result' => true]);
        }

        return Http::response(['result' => ['id' => 555, 'zone_targeting' => ['type' => 0], ...($sent === null ? $details : ['zones' => array_map(fn (int $zone): array => ['idzone' => $zone], $sent)])]]);
    });

    return [$exo, function () use (&$sent): ?array {
        return $sent;
    }];
}

/**
 * PopAds keeping each campaign's own exclusion list as PopAds would: read,
 * rewritten whole — the list turned off once it is empty — and read back. A
 * campaign named among the refusing refuses every rewrite with an error it
 * explains — those named when the call is made, where a test changes them
 * as it goes. Its reports and campaign details answer with nothing. Faked
 * once a test: the first fake answers every call.
 *
 * @param  array<string, list<string>>  $lists  by campaign ID, changed as the rewrites change them
 * @param  list<string>|Closure(): list<string>  $refusing
 */
function popAdsWithLists(array &$lists, array|Closure $refusing = []): void
{
    Http::fake(function (Request $request) use (&$lists, $refusing) {
        $refusing = $refusing instanceof Closure ? $refusing() : $refusing;

        if (str_contains($request->url(), 'report_advertiser')) {
            return Http::response(['rows' => []]);
        }

        if (! preg_match('#/api/campaign_website_targeting/(\w+)#', $request->url(), $match)) {
            return Http::response(['data' => []]);
        }

        $id = $match[1];

        if ($request->method() === 'POST') {
            if (in_array($id, $refusing, true)) {
                return Http::response(['errors' => [['status' => 500, 'code' => 0, 'title' => 'Campaign is under review']]], 500);
            }

            $lists[$id] = str_contains($request->url(), 'mode=none') ? [] : array_values(array_filter(explode("\n", $request->body()), fn (string $line): bool => $line !== ''));
        }

        return Http::response(($lists[$id] ?? []) === [] ? ['mode' => 'none', 'items' => []] : ['mode' => 'exclude', 'items' => $lists[$id]]);
    });
}
