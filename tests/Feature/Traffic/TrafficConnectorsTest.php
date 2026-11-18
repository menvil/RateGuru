<?php

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Actions\Traffic\ConnectTrafficApiAction;
use App\Actions\Traffic\SyncTrafficNetworkAction;
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
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignPrice;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    $this->popads->forceFill(['block_auto' => true, 'block_daily_cap' => 2])->save();

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
