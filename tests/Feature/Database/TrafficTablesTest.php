<?php

use App\Enums\TrafficEvent;
use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\GameAttempt;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The tracker's tables keep their own rules alike on every engine: one visit
 * per network click id, one conversion per visit and event, one postback per
 * conversion and rule, one block per site or zone, one attribution per
 * account; children go with their parents, pointers to a purged visit or a
 * deleted rule are let go, and nothing a user or a game attempt is named by
 * can be deleted from under it.
 */
function refusesToStore(callable $write): bool
{
    try {
        DB::transaction($write);
    } catch (QueryException) {
        return true;
    }

    return false;
}

it('starts with the starting prices in force', function () {
    $prices = TrafficPriceSet::current();

    expect([$prices->account_price, $prices->post_bonus, $prices->returned_bonus])->toBe(['0.5000', '1.0000', '0.2000'])
        ->and(array_column($prices->country_groups, 'key'))->toBe(['t1', 't2', 'rest'])
        ->and($prices->groupOf('DE'))->toBe(['key' => 't1', 'percent' => 100])
        ->and($prices->groupOf('BR'))->toBe(['key' => 'rest', 'percent' => 100])
        ->and($prices->groupOf(null))->toBe(['key' => 'rest', 'percent' => 100]);
});

it('keeps one of each thing the tracker counts once', function () {
    $visit = TrafficVisit::factory()->create();
    $conversion = TrafficConversion::factory()->create(['visit_id' => $visit->id, 'event' => TrafficEvent::Started]);
    $rule = TrafficPostbackRule::factory()->create(['source_id' => $visit->source_id]);
    TrafficPostback::factory()->create(['conversion_id' => $conversion->id, 'rule_id' => $rule->id]);
    $block = TrafficBlock::factory()->create();
    $campaign = TrafficCampaign::factory()->create();
    $attribution = AccountAttribution::factory()->create();
    $browser = AccountBrowser::factory()->create();

    expect(refusesToStore(fn () => TrafficVisit::factory()->create(['source_id' => $visit->source_id, 'click_hash' => $visit->click_hash])))->toBeTrue()
        ->and(refusesToStore(fn () => TrafficConversion::factory()->create(['visit_id' => $visit->id, 'event' => TrafficEvent::Started])))->toBeTrue()
        ->and(refusesToStore(fn () => TrafficPostback::factory()->create(['conversion_id' => $conversion->id, 'rule_id' => $rule->id])))->toBeTrue()
        ->and(refusesToStore(fn () => TrafficBlock::factory()->create(['source_id' => $block->source_id, 'level' => $block->level, 'ref' => $block->ref])))->toBeTrue()
        ->and(refusesToStore(fn () => TrafficCampaign::factory()->create(['source_id' => $campaign->source_id, 'external_id' => $campaign->external_id])))->toBeTrue()
        ->and(refusesToStore(fn () => TrafficSource::factory()->create(['link_key' => TrafficSource::query()->value('link_key')])))->toBeTrue()
        ->and(refusesToStore(fn () => AccountAttribution::factory()->create(['user_id' => $attribution->user_id])))->toBeTrue()
        ->and(refusesToStore(fn () => AccountBrowser::factory()->create(['user_id' => $browser->user_id, 'visitor_hash' => $browser->visitor_hash])))->toBeTrue();
});

it('lets any number of unpaid visits and clicks without an id share a source', function () {
    $source = TrafficSource::factory()->create();
    TrafficVisit::factory()->count(2)->create(['source_id' => $source->id, 'click_hash' => null]);
    TrafficVisit::factory()->unpaid()->count(2)->create();

    expect(TrafficVisit::query()->count())->toBe(4);
});

it('takes a visit\'s details, conversions and their postbacks with it, and lets an attribution\'s pointer go', function () {
    $visit = TrafficVisit::factory()->create();
    TrafficVisitDetail::query()->forceCreate(['visit_id' => $visit->id, 'landing_path' => '/games/x', 'created_at' => now()]);
    $conversion = TrafficConversion::factory()->create(['visit_id' => $visit->id]);
    $postback = TrafficPostback::factory()->create(['conversion_id' => $conversion->id]);
    $attribution = AccountAttribution::factory()->create(['credited_visit_id' => $visit->id]);

    $visit->delete();

    expect(TrafficVisitDetail::query()->count())->toBe(0)
        ->and(TrafficConversion::query()->count())->toBe(0)
        ->and(TrafficPostback::query()->find($postback->id))->toBeNull()
        ->and($attribution->fresh()->credited_visit_id)->toBeNull();
});

it('keeps a postback when its rule is deleted, without the rule', function () {
    $postback = TrafficPostback::factory()->create();
    $rule = TrafficPostbackRule::factory()->create(['source_id' => $postback->source_id]);
    $postback->forceFill(['rule_id' => $rule->id])->save();

    $rule->delete();

    expect($postback->fresh()->rule_id)->toBeNull();
});

it('never lets a user or a game attempt go from under what names it', function () {
    $user = User::factory()->create();
    AccountAttribution::factory()->create(['user_id' => $user->id]);
    $attempt = GameAttempt::factory()->create();
    TrafficConversion::factory()->create(['attempt_id' => $attempt->id]);

    expect(refusesToStore(fn () => DB::table('users')->where('id', $user->id)->delete()))->toBeTrue()
        ->and(refusesToStore(fn () => DB::table('game_attempts')->where('id', $attempt->id)->delete()))->toBeTrue();
});
