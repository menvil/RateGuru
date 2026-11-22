<?php

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Actions\Traffic\ConnectTrafficApiAction;
use App\Actions\Traffic\RecordBlockedHitAction;
use App\Actions\Traffic\SyncTrafficNetworkAction;
use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnector;
use App\Enums\TrafficSpendOrigin;
use App\Exceptions\Traffic\CannotManageTrafficException;
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
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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
        ->and($found[1]->listings()->orderBy('campaign_id')->get()->map(fn (TrafficBlockListing $listing): array => [$listing->campaign_id, $listing->listed])->all())->toBe([[$first->id, true], [$second->id, false]])
        ->and($marked->fresh())->network_state->toBe(TrafficBlockNetworkState::ByHand)->method->toBe(TrafficBlockMethod::Manual)
        ->and($marked->listings()->count())->toBe(0)
        ->and($connection->fresh()->block_lists_read_at?->toDateTimeString())->toBe('2026-11-17 12:00:00')
        ->and(TrafficBlockEvent::query()->where('kind', 'found')->sole())->ref_count->toBe(2)->method->toBe(TrafficBlockMethod::Panel)->ok->toBeTrue();
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
        ->and($lists)->toBe(['881204' => ['40125'], '881205' => ['40123', '40125']])
        ->and(TrafficBlockEvent::query()->where('kind', 'gone')->sole())->ref_count->toBe(3)->actor->toBe(TrafficActor::NetworkApi);
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

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true));
    $found = TrafficBlock::query()->orderBy('ref')->get();

    expect($found->pluck('ref')->all())->toBe(['10', '20'])
        ->and($found->pluck('level')->unique()->all())->toBe([TrafficBlockLevel::Zone])
        ->and($found->pluck('method')->unique()->all())->toBe([TrafficBlockMethod::Panel])
        ->and(TrafficBlockListing::query()->where('campaign_id', $campaign->id)->where('listed', true)->count())->toBe(2)
        ->and($connection->fresh())->status->toBe(TrafficConnectionStatus::Connected)->failures->toBe(0);
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

it('sends no PopAds exclusion list rewritten from a targeting read without one', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->popads->id, 'next_sync_at' => now()->addHour()]);
    TrafficCampaign::factory()->create(['source_id' => $this->popads->id, 'external_id' => '881204']);
    Http::fake(fn (Request $request) => Http::response(['mode' => 'exclude']));

    app(BlockTrafficRefsAction::class)->block($this->admin, $this->popads, TrafficBlockLevel::Site, ['40123'], null, true);
    $this->artisan('traffic:sync-networks')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    expect(TrafficBlock::query()->sole())->network_state->toBe(TrafficBlockNetworkState::Failed)->network_error->toBe('unexpected_response');
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
 * explains. Its reports and campaign details answer with nothing.
 *
 * @param  array<string, list<string>>  $lists  by campaign ID, changed as the rewrites change them
 * @param  list<string>  $refusing
 */
function popAdsWithLists(array &$lists, array $refusing = []): void
{
    Http::fake(function (Request $request) use (&$lists, $refusing) {
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
