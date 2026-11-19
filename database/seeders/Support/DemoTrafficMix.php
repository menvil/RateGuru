<?php

namespace Database\Seeders\Support;

use App\Actions\Traffic\SaveTrafficSourceAction;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficDevice;
use App\Enums\TrafficSourceType;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Geo\GeoLocation;
use App\Support\Traffic\Capture\VisitorSignals;
use App\Support\Traffic\Presets\SourceDefaults;
use Carbon\CarbonImmutable;

/**
 * Where the demo game's visitors come from: the paid sources — the ad
 * networks of the presets and a few banners and links bought at a fixed
 * price, beside the ones TrafficDemoSeeder made — each with its campaigns,
 * sites and zones, how far into the world it reaches and what a click of it
 * costs, and the unpaid ways in. Two zones are full of robots: they open the
 * game, race through it and sign up with addresses nobody ever confirms.
 *
 * Every pick is made with mt_rand, so a seeded run draws the same mix.
 */
final class DemoTrafficMix
{
    /**
     * The networks added beside TrafficDemoSeeder's two: preset, name, link
     * key (null: the preset's), the weight of its share of paid clicks, how
     * much of it comes from Tier 1 countries, how much from phones, how many
     * of its clicks run the page, and a Tier 1 click's price.
     *
     * @var list<array{0: string, 1: string, 2: string|null, 3: int, 4: int, 5: int, 6: int, 7: float}>
     */
    private const array NETWORKS = [
        ['popads', 'PopAds tier 2', 'pop2', 6, 10, 70, 62, 0.009],
        ['adsterra', 'Adsterra social bar', null, 9, 35, 80, 74, 0.016],
        ['clickadu', 'Clickadu popunder', null, 8, 25, 72, 66, 0.017],
        ['juicyads', 'JuicyAds banners', null, 5, 55, 55, 81, 0.03],
        ['propellerads', 'PropellerAds push', null, 10, 30, 85, 70, 0.022],
        ['trafficjunky', 'TrafficJunky native', null, 5, 65, 60, 84, 0.04],
        ['trafficstars', 'TrafficStars tests', null, 6, 50, 65, 79, 0.024],
        ['custom', 'HilltopAds', 'hilltop', 4, 20, 75, 68, 0.012],
    ];

    /**
     * The banners and links added beside TrafficDemoSeeder's: name, link
     * key, the site, the price and how often it is paid, the creatives.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<string>}>
     */
    private const array PLACEMENTS = [
        ['Pet forum header', 'petforum', 'petforum.example', '8', 'month', ['header', 'signature']],
        ['Weekly cats newsletter', 'catletter', 'catletter.example', '2', 'week', ['issue-link']],
    ];

    /**
     * Countries by group, with cities and how often each comes. Tier 1 and
     * Tier 2 are the price set's groups; the rest is "every other country".
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: int}>>
     */
    private const array COUNTRIES = [
        't1' => [['US', 'TX', 'Austin', 5], ['US', 'MA', 'Boston', 4], ['US', 'IL', 'Chicago', 4], ['US', 'WA', 'Seattle', 3], ['GB', 'ENG', 'London', 5], ['GB', 'ENG', 'Manchester', 2], ['CA', 'ON', 'Toronto', 3], ['AU', 'NSW', 'Sydney', 2], ['DE', 'BE', 'Berlin', 3], ['DE', 'BY', 'Munich', 2], ['NL', 'NH', 'Amsterdam', 2]],
        't2' => [['ES', 'MD', 'Madrid', 3], ['FR', 'IDF', 'Paris', 3], ['FR', 'ARA', 'Lyon', 1], ['IT', 'LOM', 'Milan', 2], ['PL', 'MZ', 'Warsaw', 2]],
        'rest' => [['BR', 'SP', 'São Paulo', 4], ['IN', 'MH', 'Mumbai', 4], ['IN', 'DL', 'Delhi', 3], ['MX', 'CMX', 'Mexico City', 3], ['PH', '00', 'Manila', 3], ['ID', 'JK', 'Jakarta', 2], ['TR', '34', 'Istanbul', 2], ['VN', 'HN', 'Hanoi', 1]],
    ];

    /** @var list<array{0: string, 1: string|null, 2: int}> unpaid channel, origin, weight */
    private const array UNPAID = [
        ['search', 'google', 26], ['search', 'bing', 5], ['search', 'duckduckgo', 2],
        ['shared', 'facebook.com', 9], ['shared', 'twitter.com', 4], ['shared', 'result page', 6],
        ['referral', 'reddit.com', 7], ['referral', 'catforum.example', 3], ['referral', 'in-app browser', 2],
        ['direct', null, 18],
        ['internal', 'feed', 12], ['internal', 'post', 6],
    ];

    /** @var list<array{source: TrafficSource, weight: int, t1: int, mobile: int, opens: int, price: float, sites: list<string>, zones: list<string>, bots: list<string>, campaigns: list<string>}> */
    private array $paid = [];

    public function __construct(private readonly User $admin) {}

    /**
     * Adds the sources, as the administrator does from the presets, and a
     * few campaigns of each network set up by hand; collects TrafficDemoSeeder's
     * sources beside them, so every one of them brings visitors.
     */
    public function addSources(CarbonImmutable $from): void
    {
        $catalog = app(TrafficPresetCatalog::class);
        $save = app(SaveTrafficSourceAction::class);

        foreach (self::NETWORKS as [$preset, $name, $key, $weight, $t1, $mobile, $opens, $price]) {
            // No network's API is connected in the demo: spend comes from the price in the links, or a rate.
            $defaults = SourceDefaults::forPreset($preset, $catalog->find($preset), false, TrafficSource::query()->pluck('link_key')->all());
            $draft = [...$defaults['source'], 'name' => $name];

            if ($key !== null) {
                $draft['link_key'] = $key;
            }

            // A network without a preset: its tokens filled in from its help pages, as the preset asks.
            if ($preset === 'custom') {
                $draft['link_params'] = array_map(fn (array $param): array => [...$param, 'token' => '{'.$param['kind'].'_id}'], $draft['link_params']);
            }

            // A network without a price in its links is counted at a rate per click.
            if ($draft['spend_mode'] === 'rate') {
                $draft = [...$draft, 'rate_unit' => 'click', 'rate_mobile' => number_format($price * 0.6, 6, '.', ''), 'rate_desktop' => number_format($price * 0.9, 6, '.', '')];
            }

            $source = $save->handle($this->admin, null, $draft, $defaults['rules']);
            $this->collect($source, $weight, $t1, $mobile, $opens, $price, $this->campaigns($source, $from));
        }

        foreach (self::PLACEMENTS as [$name, $key, $site, $amount, $period, $creatives]) {
            $draft = SourceDefaults::forPlacement()['source'];
            $source = $save->handle($this->admin, null, [
                ...$draft,
                'name' => $name,
                'link_key' => $key,
                'fixed_amount' => $amount,
                'fixed_period' => $period,
                'fixed_from' => $from->toDateString(),
                'place_site' => $site,
                'creatives' => $creatives,
            ], []);
            $this->collect($source, 5, 60, 50, 92, 0.0, []);
        }

        // TrafficDemoSeeder's own: its campaigns are already there.
        foreach ([['pop', 9, 15, 75, 63, 0.008], ['exo', 7, 70, 60, 80, 0.03], ['catblog', 4, 65, 45, 93, 0.0]] as [$key, $weight, $t1, $mobile, $opens, $price]) {
            $source = TrafficSource::query()->where('link_key', $key)->first();

            if ($source !== null) {
                $this->collect($source, $weight, $t1, $mobile, $opens, $price, TrafficCampaign::query()->where('source_id', $source->id)->orderBy('id')->pluck('external_id')->all());
            }
        }
    }

    /**
     * A paid click: the source, its click's link parameters as the network
     * fills them in, how likely it is to run the page, whether it came from
     * one of the robots' zones, and the browser behind it.
     *
     * @return array{source: TrafficSource, query: array<string, string>, opens: int, bot: bool, country: array{0: string, 1: string, 2: string, 3: string}, device: TrafficDevice}
     */
    public function paidClick(int $serial): array
    {
        $pick = $this->paid[self::weighted(array_column($this->paid, 'weight'))];
        $source = $pick['source'];
        $country = $this->country($pick['t1']);
        $device = mt_rand(1, 100) <= $pick['mobile'] ? TrafficDevice::Mobile : TrafficDevice::Desktop;
        $bot = $pick['bots'] !== [] && mt_rand(1, 100) <= 22;
        $zone = $bot ? $pick['bots'][mt_rand(0, count($pick['bots']) - 1)] : ($pick['zones'] === [] ? null : $pick['zones'][self::skewed(count($pick['zones']))]);
        $site = $pick['sites'] === [] ? null : $pick['sites'][self::skewed(count($pick['sites']))];
        // Now and then a campaign started in the network without a word here: its first click adds it.
        $campaign = $pick['campaigns'] === [] ? null : (mt_rand(1, 1000) <= 15 ? (string) (990000 + $source->id * 10 + mt_rand(1, 2)) : $pick['campaigns'][self::skewed(count($pick['campaigns']))]);
        $price = $pick['price'] * ['t1' => 1.0, 't2' => 0.55, 'rest' => 0.25][$country[3]] * (mt_rand(80, 125) / 100) * ($device === TrafficDevice::Desktop ? 1.3 : 1.0);
        $values = [
            'click' => 'dm'.$serial.'x'.mt_rand(100000, 999999),
            'campaign' => $campaign,
            'site' => $site,
            'zone' => $zone,
            'creative' => (string) mt_rand(1, 6),
            'cost' => $price > 0 ? number_format($price * ($source->cost_unit->value === 'cpm' ? 1000 : 1), 6, '.', '') : null,
        ];
        $query = [];

        foreach ($source->link_params ?? [] as $param) {
            if ($param['param'] !== '' && ($values[$param['kind']] ?? null) !== null) {
                $query[$param['param']] = (string) $values[$param['kind']];
            }
        }

        if ($source->type === TrafficSourceType::Placement) {
            $creatives = $source->creatives ?? [];
            $query['rg_cr'] = $creatives === [] ? 'banner' : (string) $creatives[mt_rand(0, count($creatives) - 1)];
        }

        return ['source' => $source, 'query' => $query, 'opens' => $bot ? 55 : $pick['opens'], 'bot' => $bot, 'country' => $country, 'device' => $device];
    }

    /**
     * An unpaid arrival: its channel, where it came from, and the browser.
     *
     * @return array{channel: string, origin: string|null, country: array{0: string, 1: string, 2: string, 3: string}, device: TrafficDevice}
     */
    public function unpaidArrival(): array
    {
        [$channel, $origin] = self::UNPAID[self::weighted(array_column(self::UNPAID, 2))];

        return ['channel' => $channel, 'origin' => $origin, 'country' => $this->country(45), 'device' => mt_rand(1, 100) <= 62 ? TrafficDevice::Mobile : TrafficDevice::Desktop];
    }

    /**
     * Where a visitor is, by the share of Tier 1 the source brings, the rest
     * split between Tier 2 and every other country.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} country, region, city, group
     */
    public function country(int $t1): array
    {
        $roll = mt_rand(1, 100);
        $group = $roll <= $t1 ? 't1' : ($roll <= $t1 + (int) round((100 - $t1) * 0.3) ? 't2' : 'rest');
        $places = self::COUNTRIES[$group];
        [$code, $region, $city] = $places[self::weighted(array_column($places, 3))];

        return [$code, $region, $city, $group];
    }

    /**
     * What the request of a visitor in this place on this device says.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string}  $country
     */
    public static function signals(array $country, TrafficDevice $device, ?string $referrerHost = null): VisitorSignals
    {
        $mobile = $device === TrafficDevice::Mobile;

        return new VisitorSignals(
            device: $device,
            botFlags: 0,
            location: new GeoLocation($country[0], null, $country[1], $country[2]),
            ipHash: null,
            os: $mobile ? (crc32($country[2]) % 3 === 0 ? 'iOS' : 'Android') : (crc32($country[2]) % 4 === 0 ? 'macOS' : 'Windows'),
            browser: $mobile ? 'Mobile Safari' : 'Chrome',
            language: 'en',
            referrer: $referrerHost === null ? null : 'https://'.$referrerHost.'/',
            referrerHost: $referrerHost,
            path: '/games/trust-your-taste',
        );
    }

    /** A random index by weight. @param  list<int>  $weights */
    public static function weighted(array $weights): int
    {
        $roll = mt_rand(1, (int) array_sum($weights));

        foreach ($weights as $index => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $index;
            }
        }

        return count($weights) - 1;
    }

    /** A random index of a list where the first entries come far more often than the last: a few sites and zones bring most clicks. */
    public static function skewed(int $count): int
    {
        return min($count - 1, (int) floor($count * (mt_rand(0, 1000) / 1000) ** 2.2));
    }

    /**
     * Two or three campaigns of a network, named and switched on by hand as
     * TrafficDemoSeeder's are.
     *
     * @return list<string> their ids in the network
     */
    private function campaigns(TrafficSource $source, CarbonImmutable $from): array
    {
        $names = ['Tier 1 mobile', 'Worldwide test', 'Retarget players', 'Cats only', 'Night slots'];
        $ids = [];

        for ($n = 0, $count = mt_rand(2, 3); $n < $count; $n++) {
            $external = (string) (mt_rand(100, 899) * 1000 + $source->id * 10 + $n);
            TrafficCampaign::query()->create([
                'source_id' => $source->id,
                'external_id' => $external,
                'name' => $names[($source->id + $n) % count($names)],
                'status' => TrafficCampaignStatus::Active,
                'first_click_at' => $from,
                'set_up_at' => $from,
                'archived_automatically' => false,
                'lock_version' => 1,
            ]);
            $ids[] = $external;
        }

        return $ids;
    }

    /** @param  list<string>  $campaigns */
    private function collect(TrafficSource $source, int $weight, int $t1, int $mobile, int $opens, float $price, array $campaigns): void
    {
        $kinds = array_column($source->link_params ?? [], 'param', 'kind');
        $base = 40000 + $source->id * 1000;
        $range = fn (int $from, int $count): array => array_map(fn (int $n): string => (string) ($from + $n), range(1, $count));

        $this->paid[] = [
            'source' => $source,
            'weight' => $weight,
            't1' => $t1,
            'mobile' => $mobile,
            'opens' => $opens,
            'price' => $price,
            'sites' => isset($kinds['site']) ? $range($base, mt_rand(6, 14)) : [],
            'zones' => isset($kinds['zone']) ? $range($base + 500, mt_rand(10, 28)) : [],
            // The robots live in a few zones of the push and popunder networks.
            'bots' => isset($kinds['zone']) && in_array($source->preset_key, ['propellerads', 'clickadu'], true) ? [(string) ($base + 900), (string) ($base + 901)] : [],
            'campaigns' => $campaigns,
        ];
    }
}
