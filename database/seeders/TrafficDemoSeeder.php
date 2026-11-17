<?php

namespace Database\Seeders;

use App\Actions\Traffic\SaveTrafficSourceAction;
use App\Actions\Traffic\WorkOutChancesAction;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendOrigin;
use App\Models\Game;
use App\Models\TrafficCampaign;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Traffic\Presets\SourceDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Traffic sources to look at locally: PopAds and ExoClick from their presets
 * and a banner, a few campaigns, six weeks of paid and unpaid visits across
 * devices, countries, sites and zones — some opened, played, signed up and
 * confirmed — their spend, conversions and postbacks, and the chances worked
 * out from the older ones. Visits are recorded, postbacks stay off.
 *
 * Needs the demo administrator (DemoAdminSeeder) and is best run after
 * GamesDemoSeeder, so the visits have a game. Not part of DatabaseSeeder:
 *
 *     php artisan db:seed --class=TrafficDemoSeeder
 *
 * Safe to run again: once the PopAds source exists, nothing is added.
 */
final class TrafficDemoSeeder extends Seeder
{
    private const array COUNTRIES = [['US', 'Austin'], ['US', 'Boston'], ['GB', 'London'], ['DE', 'Berlin'], ['BR', 'São Paulo'], ['IN', 'Mumbai']];

    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@rateguru.test')->first();

        if ($admin === null) {
            $this->command?->warn('Run DemoAdminSeeder first: the sources are saved by the demo administrator.');

            return;
        }

        if (TrafficSource::query()->where('link_key', 'pop')->exists()) {
            $this->command?->info('Traffic demo already there.');

            return;
        }

        mt_srand(7);
        $settings = TrafficSetting::query()->find(1) ?? (new TrafficSetting)->forceFill(['id' => 1]);
        $settings->forceFill(['capture_enabled' => true, 'postbacks_enabled' => false])->save();

        $catalog = app(TrafficPresetCatalog::class);
        $save = app(SaveTrafficSourceAction::class);
        $sources = [];

        foreach (['popads' => 'PopAds main', 'exoclick' => 'ExoClick tests'] as $key => $name) {
            $preset = $catalog->find($key);
            $draft = SourceDefaults::forPreset($key, $preset, $catalog->connector($preset['connector'] ?? null) !== null, TrafficSource::query()->pluck('link_key')->all());
            $draft['source']['spend_mode'] = 'click_cost';
            $sources[$key] = $save->handle($admin, null, [...$draft['source'], 'name' => $name], $draft['rules']);
        }

        $banner = SourceDefaults::forPlacement();
        $sources['banner'] = $save->handle($admin, null, [...$banner['source'], 'name' => 'Cat blog sidebar', 'link_key' => 'catblog', 'fixed_amount' => '120', 'fixed_period' => 'month', 'fixed_from' => now()->subDays(40)->toDateString(), 'creatives' => ['sidebar']], []);
        TrafficPostbackRule::query()->create(['source_id' => $sources['popads']->id, 'event' => TrafficEvent::Goal, 'url_template' => 'https://serve.popads.net/cpixel.php?s2s=1&aid=demo&id={click_id}&value={value}', 'enabled' => true, 'position' => 1]);

        $campaigns = [];

        foreach ([['popads', '881204', 'US popunder', TrafficCampaignStatus::Active], ['popads', '881377', null, TrafficCampaignStatus::New], ['exoclick', '5521904', 'Tier 1 native', TrafficCampaignStatus::Active]] as [$source, $external, $name, $status]) {
            $campaigns[] = TrafficCampaign::query()->create(['source_id' => $sources[$source]->id, 'external_id' => $external, 'name' => $name, 'status' => $status, 'first_click_at' => now()->subDays(40), 'archived_automatically' => false, 'lock_version' => 1]);
        }

        $game = Game::query()->orderBy('id')->first();
        $prices = TrafficPriceSet::current();

        for ($i = 0; $i < 900; $i++) {
            $paid = mt_rand(1, 100) <= 75;
            $campaign = $paid ? $campaigns[mt_rand(0, count($campaigns) - 1)] : null;
            $arrived = CarbonImmutable::now()->subMinutes(mt_rand(10, 60 * 24 * 42));
            [$country, $city] = self::COUNTRIES[mt_rand(0, count(self::COUNTRIES) - 1)];
            $opened = mt_rand(1, 100) <= ($paid ? 70 : 100);
            $started = $opened && mt_rand(1, 100) <= 55;
            $completed = $started && mt_rand(1, 100) <= 70;
            $account = $completed && mt_rand(1, 100) <= 12;
            $group = $prices->groupOf($country);

            $visit = TrafficVisit::query()->create([
                'channel' => $paid ? TrafficChannel::Network : [TrafficChannel::Direct, TrafficChannel::Search, TrafficChannel::Shared][mt_rand(0, 2)],
                'source_id' => $campaign?->source_id,
                'campaign_id' => $campaign?->id,
                'site_ref' => $paid ? (string) mt_rand(40100, 40130) : null,
                'zone_ref' => $paid ? (string) mt_rand(44700, 44760) : null,
                'click_hash' => $paid ? hash('sha256', 'demo-click-'.$i) : null,
                'visitor_hash' => hash('sha256', 'demo-visitor-'.$i),
                'known' => mt_rand(1, 100) <= 5,
                'device' => mt_rand(1, 100) <= 70 ? TrafficDevice::Mobile : TrafficDevice::Desktop,
                'country' => $country,
                'city' => $city,
                'game_id' => $game?->id,
                'price_set_id' => $prices->id,
                'country_group' => $group['key'],
                'worth_percent' => $group['percent'],
                'bot_flags' => 0,
                'arrived_at' => $arrived,
                'network_day' => $arrived->toDateString(),
                'holds_until' => $paid ? $arrived->addDays($campaign->source_id === $sources['popads']->id ? 2 : 7) : null,
                'last_seen_at' => $arrived,
                'opened_at' => $opened ? $arrived : null,
                'started_at' => $started ? $arrived->addMinute() : null,
                'completed_at' => $completed ? $arrived->addMinutes(3) : null,
                'signup_at' => $account ? $arrived->addMinutes(5) : null,
                'account_at' => $account ? $arrived->addMinutes(9) : null,
                'account_after_seconds' => $account ? 540 : null,
                'signup_place' => $account ? 'game' : null,
                'rounds_answered' => $completed ? 5 : ($started ? mt_rand(1, 4) : 0),
                'cost' => $paid ? number_format(mt_rand(30, 90) / 100000, 8, '.', '') : null,
                'spend' => $paid ? number_format(mt_rand(30, 90) / 100000, 8, '.', '') : '0',
                'spend_basis' => $paid ? TrafficSpendBasis::ClickCost : TrafficSpendBasis::None,
                'value_real' => $account ? '0.50' : '0',
            ]);

            if ($paid) {
                TrafficVisitDetail::query()->forceCreate(['visit_id' => $visit->id, 'click_id' => 'demo-'.$i, 'landing_path' => '/games/trust-your-taste', 'created_at' => $arrived]);
            }

            if ($paid && $account && $campaign->source_id === $sources['popads']->id) {
                $conversion = TrafficConversion::query()->create(['visit_id' => $visit->id, 'event' => TrafficEvent::Account, 'occurred_at' => $visit->account_at, 'source_id' => $visit->source_id, 'game_id' => $game?->id, 'step' => 'account', 'value' => '0.5', 'value_basis' => 'outcome']);
                TrafficPostback::query()->create(['conversion_id' => $conversion->id, 'source_id' => $visit->source_id, 'event' => TrafficEvent::Account, 'url' => 'https://serve.popads.net/cpixel.php?s2s=1&aid=demo&id={click_id}&value=0.5', 'status' => mt_rand(1, 10) === 1 ? TrafficPostbackStatus::Failed : TrafficPostbackStatus::Delivered, 'attempts' => 1, 'run_after' => $visit->account_at, 'http_status' => 200, 'outcome' => 'ok', 'created_at' => $visit->account_at]);
            }
        }

        foreach (range(3, 20) as $daysAgo) {
            TrafficSpendDay::query()->create(['source_id' => $sources['exoclick']->id, 'network_day' => now()->subDays($daysAgo)->toDateString(), 'origin' => TrafficSpendOrigin::Manual, 'amount' => number_format(mt_rand(50, 300) / 100, 2, '.', '')]);
        }

        app(WorkOutChancesAction::class)->handle('demo', CarbonImmutable::now());
        $this->command?->info('Traffic demo seeded: 3 sources, 3 campaigns, 900 visits.');
    }
}
