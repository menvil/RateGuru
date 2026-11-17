<?php

use App\Actions\Traffic\ArchiveTrafficCampaignAction;
use App\Actions\Traffic\ChangeTrafficCampaignStageAction;
use App\Actions\Traffic\SaveTrafficCampaignAction;
use App\Actions\Traffic\SaveTrafficSourceAction;
use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignEvent;
use App\Models\TrafficCampaignPrice;
use App\Models\TrafficCampaignStagePeriod;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Traffic\Presets\SourceDefaults;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/** A new source's draft from a preset, as the editor first holds it. */
function presetDraft(string $key, array $taken = []): array
{
    $catalog = app(TrafficPresetCatalog::class);
    $preset = $catalog->find($key);

    return SourceDefaults::forPreset($key, $preset, $catalog->connector($preset['connector'] ?? null) !== null, $taken);
}

/** A saved source's draft, as its editor holds it. */
function sourceDraft(TrafficSource $source): array
{
    return $source->only(['type', 'name', 'link_key', 'status', 'attribution_days', 'click_id_ttl_hours', 'target_cpa', 'reporting_timezone', 'link_params', 'response_rules',
        'spend_mode', 'spend_estimate', 'cost_unit', 'rate_unit', 'rate_mobile', 'rate_desktop', 'fixed_amount', 'fixed_period', 'fixed_from', 'fixed_to',
        'block_level', 'block_auto', 'block_daily_cap', 'place_site', 'place_position', 'creatives']);
}

function draftValues(array $draft): array
{
    return array_map(fn ($value) => $value instanceof BackedEnum ? $value->value : $value, $draft);
}

it('creates a PopAds source from its preset, active at once, with its goal rule kept off', function () {
    $draft = presetDraft('popads');
    $source = app(SaveTrafficSourceAction::class)->handle($this->admin, null, $draft['source'], $draft['rules']);

    expect($source->status)->toBe(TrafficSourceStatus::Active)
        ->and($source->link_key)->toBe('pop')
        ->and($source->preset_key)->toBe('popads')
        ->and($source->connector?->value)->toBe('popads')
        ->and($source->attribution_days)->toBe(2)
        ->and($source->link_params[0]['token'])->toBe('[IMPRESSIONID]')
        ->and($source->created_by_user_id)->toBe($this->admin->id)
        ->and(TrafficPostbackRule::query()->where('source_id', $source->id)->sole()->enabled)->toBeFalse();
});

it('refuses a rule switched on while its address still has gaps, and saves it once filled in', function () {
    $draft = presetDraft('popads');
    $draft['rules'][0]['enabled'] = true;

    expect(fn () => app(SaveTrafficSourceAction::class)->handle($this->admin, null, $draft['source'], $draft['rules']))
        ->toThrow(ValidationException::class, 'Fill in the parts marked … with your account’s values.');

    $draft['rules'][0]['url_template'] = 'https://serve.popads.net/cpixel.php?s2s=1&aid=4f1&id={click_id}&value={value}';
    $source = app(SaveTrafficSourceAction::class)->handle($this->admin, null, $draft['source'], $draft['rules']);

    expect(TrafficPostbackRule::query()->where('source_id', $source->id)->sole()->enabled)->toBeTrue();
});

it('keys the editor’s errors by its fields', function () {
    TrafficSource::factory()->create(['link_key' => 'pop']);
    $draft = presetDraft('popads');
    $draft['source']['link_key'] = 'pop';
    $draft['source']['link_params'][1]['param'] = 'sort';

    try {
        app(SaveTrafficSourceAction::class)->handle($this->admin, null, $draft['source'], $draft['rules']);
        $this->fail('Saved a source with a taken key.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['link_key', 'link_params.1.param']);
    }
});

it('keeps the key and the time zone once visits came through them', function () {
    $source = TrafficSource::factory()->create(['link_key' => 'exo']);
    TrafficVisit::factory()->create(['source_id' => $source->id]);
    $draft = draftValues(sourceDraft($source));

    expect(fn () => app(SaveTrafficSourceAction::class)->handle($this->admin, $source->id, [...$draft, 'link_key' => 'exo2'], [], $source->lock_version))
        ->toThrow(ValidationException::class, 'Visits have come through this key')
        ->and(fn () => app(SaveTrafficSourceAction::class)->handle($this->admin, $source->id, [...$draft, 'reporting_timezone' => 'Europe/Berlin'], [], $source->lock_version))
        ->toThrow(ValidationException::class, 'Visits are already filed by this time zone’s days');
});

it('cancels the postbacks waiting to be sent when the source is paused', function () {
    $source = TrafficSource::factory()->create();
    $waiting = TrafficPostback::factory()->create(['source_id' => $source->id, 'status' => TrafficPostbackStatus::Pending]);
    $sent = TrafficPostback::factory()->create(['source_id' => $source->id, 'status' => TrafficPostbackStatus::Delivered]);

    $saved = app(SaveTrafficSourceAction::class)->handle($this->admin, $source->id, [...draftValues(sourceDraft($source)), 'status' => 'paused'], [], $source->lock_version);

    expect($saved->status)->toBe(TrafficSourceStatus::Paused)
        ->and($saved->lock_version)->toBe($source->lock_version + 1)
        ->and($waiting->fresh()->status)->toBe(TrafficPostbackStatus::Cancelled)
        ->and($sent->fresh()->status)->toBe(TrafficPostbackStatus::Delivered);
});

it('replaces the rules with the ones the editor lists', function () {
    $source = TrafficSource::factory()->create();
    $kept = TrafficPostbackRule::factory()->create(['source_id' => $source->id]);
    $dropped = TrafficPostbackRule::factory()->create(['source_id' => $source->id]);
    $rules = [
        ['id' => $kept->id, 'event' => 'started', 'goal_code' => '11', 'url_template' => 'https://pb.example.com/c?id={click_id}', 'enabled' => true],
        ['id' => null, 'event' => 'goal', 'goal_code' => '12', 'url_template' => 'https://pb.example.com/c?id={click_id}&v={value}', 'enabled' => true],
    ];

    app(SaveTrafficSourceAction::class)->handle($this->admin, $source->id, draftValues(sourceDraft($source)), $rules, $source->lock_version);

    expect(TrafficPostbackRule::query()->where('source_id', $source->id)->orderBy('position')->pluck('goal_code')->all())->toBe(['11', '12'])
        ->and(TrafficPostbackRule::query()->find($dropped->id))->toBeNull();
});

it('refuses a stale editor, and anyone but an active administrator', function () {
    $source = TrafficSource::factory()->create();

    expect(fn () => app(SaveTrafficSourceAction::class)->handle($this->admin, $source->id, draftValues(sourceDraft($source)), [], $source->lock_version - 1))
        ->toThrow(CannotManageTrafficException::class, 'Someone saved this source after you opened it.')
        ->and(fn () => app(SaveTrafficSourceAction::class)->handle(User::factory()->moderator()->create(), $source->id, draftValues(sourceDraft($source)), [], $source->lock_version))
        ->toThrow(CannotManageTrafficException::class, 'You can’t manage traffic sources any more');
});

it('creates a banner bought at a fixed price, with no parameters and no rules', function () {
    $draft = SourceDefaults::forPlacement();
    $source = app(SaveTrafficSourceAction::class)->handle($this->admin, null, [...$draft['source'], 'name' => 'Cat blog sidebar', 'link_key' => 'catblog', 'fixed_amount' => '120', 'creatives' => ['sidebar']], []);

    expect($source->fixed_amount)->toBe('120.00')
        ->and($source->creatives)->toBe(['sidebar'])
        ->and($source->link_params)->toBe([]);
});

it('moves a campaign through its stages as periods, with its history', function () {
    $campaign = TrafficCampaign::factory()->create();

    app(ChangeTrafficCampaignStageAction::class)->handle($this->admin, $campaign, TrafficCampaignStage::Test);
    $this->travel(2)->days();
    app(ChangeTrafficCampaignStageAction::class)->handle($this->admin, $campaign, TrafficCampaignStage::Scale);

    $periods = TrafficCampaignStagePeriod::query()->where('campaign_id', $campaign->id)->orderBy('id')->get();

    expect($campaign->fresh()->status)->toBe(TrafficCampaignStatus::Active)
        ->and($campaign->fresh()->stage)->toBe(TrafficCampaignStage::Scale)
        ->and($periods->pluck('stage')->all())->toBe([TrafficCampaignStage::Test, TrafficCampaignStage::Scale])
        ->and($periods[0]->ended_at)->not->toBeNull()
        ->and($periods[1]->ended_at)->toBeNull()
        ->and(TrafficCampaignEvent::query()->where('campaign_id', $campaign->id)->orderBy('id')->pluck('summary')->all())->toBe(['Stage set to Test', 'Stage changed from Test to Scale']);
});

it('archives a campaign and restores it as it was', function () {
    $campaign = TrafficCampaign::factory()->create(['status' => TrafficCampaignStatus::Active]);

    app(ArchiveTrafficCampaignAction::class)->archive($this->admin, $campaign);
    expect($campaign->fresh()->status)->toBe(TrafficCampaignStatus::Archived);

    app(ArchiveTrafficCampaignAction::class)->restore($this->admin, $campaign);
    expect($campaign->fresh()->status)->toBe(TrafficCampaignStatus::Active)
        ->and($campaign->fresh()->archived_at)->toBeNull();
});

it('prices a campaign from a day on, keeping the price the days before had', function () {
    $campaign = TrafficCampaign::factory()->create();
    $save = fn (array $input) => app(SaveTrafficCampaignAction::class)->handle($this->admin, $campaign->fresh(), $input, $campaign->fresh()->lock_version);

    $save(['name' => 'US popunder', 'spend_mode' => 'price', 'bid_model' => 'cpm', 'bid_mobile' => '0.9', 'bid_desktop' => null, 'price_from' => '2026-11-01']);
    $save(['name' => 'US popunder', 'spend_mode' => 'price', 'bid_model' => 'cpm', 'bid_mobile' => '1.2', 'bid_desktop' => null, 'price_from' => '2026-11-10']);
    $save(['name' => 'US popunder', 'spend_mode' => 'price', 'bid_model' => 'cpm', 'bid_mobile' => '1.2', 'bid_desktop' => null, 'price_from' => '2026-11-12']);

    expect(TrafficCampaignPrice::query()->where('campaign_id', $campaign->id)->orderBy('effective_from')->get()->map(fn ($price) => [$price->effective_from->toDateString(), $price->price])->all())
        ->toBe([['2026-11-01', '0.900000'], ['2026-11-10', '1.200000']])
        ->and($campaign->fresh()->name)->toBe('US popunder');
});

it('archives the campaigns no click came through for 30 days, by itself', function () {
    $quiet = TrafficCampaign::factory()->create(['status' => TrafficCampaignStatus::Active, 'first_click_at' => now()->subDays(40)]);
    $busy = TrafficCampaign::factory()->create(['status' => TrafficCampaignStatus::Active, 'first_click_at' => now()->subDays(40)]);
    TrafficVisit::factory()->create(['source_id' => $busy->source_id, 'campaign_id' => $busy->id, 'arrived_at' => now()->subDays(3)]);
    $young = TrafficCampaign::factory()->create(['first_click_at' => now()->subDays(5)]);

    $this->artisan('traffic:archive-quiet-campaigns')->assertSuccessful();

    expect($quiet->fresh()->status)->toBe(TrafficCampaignStatus::Archived)
        ->and($quiet->fresh()->archived_automatically)->toBeTrue()
        ->and($quiet->fresh()->status_before_archive)->toBe(TrafficCampaignStatus::Active)
        ->and($busy->fresh()->status)->toBe(TrafficCampaignStatus::Active)
        ->and($young->fresh()->status)->toBe(TrafficCampaignStatus::New);
});

it('keeps the targeting a network reported when the campaign panel saves only its own fields', function () {
    $campaign = TrafficCampaign::factory()->create(['countries' => ['US', 'CA'], 'devices' => ['mobile'], 'format' => 'popunder']);

    app(SaveTrafficCampaignAction::class)->handle($this->admin, $campaign, ['name' => 'US popunder'], $campaign->lock_version);

    expect($campaign->fresh())->name->toBe('US popunder')->countries->toBe(['US', 'CA'])->devices->toBe(['mobile'])->format->toBe('popunder');
});
