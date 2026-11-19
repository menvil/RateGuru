<?php

namespace Database\Seeders;

use App\Enums\TrafficChannel;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendBasis;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\LandingRoute;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Load for the traffic reports: 1.5 million visits over 30 days across the
 * existing sources, inserted in chunks, so a level of the tree and Sites &
 * zones can be timed on a local PostgreSQL. Never in DatabaseSeeder, never on
 * a shared database; local and testing environments only:
 *
 *     php artisan db:seed --class=TrafficVolumeSeeder
 */
final class TrafficVolumeSeeder extends Seeder
{
    private const int VISITS = 1_500_000;

    // 2 000 rows of 25 columns: 50 000 bound values, under the 65 535 PostgreSQL and MariaDB take in one statement.
    private const int CHUNK = 2000;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Local and testing environments only; nothing was added.');

            return;
        }

        $sources = TrafficSource::query()->get(['id', 'type'])->all();
        $prices = TrafficPriceSet::current()->id;

        if ($sources === []) {
            $this->command?->warn('Add a source first (TrafficDemoSeeder).');

            return;
        }

        mt_srand(11);
        $now = CarbonImmutable::now();

        for ($done = 0; $done < self::VISITS; $done += self::CHUNK) {
            $rows = [];

            for ($i = 0; $i < self::CHUNK; $i++) {
                $arrived = $now->subSeconds(mt_rand(0, 30 * 86400));
                $opened = mt_rand(1, 100) <= 70;
                $source = $sources[mt_rand(0, count($sources) - 1)];
                // A banner's or link's click is a placement's, without the sites and zones of a network.
                $network = $source->type === TrafficSourceType::Network;
                $channel = $network ? TrafficChannel::Network : TrafficChannel::Placement;
                $rows[] = [
                    'channel' => $channel->value,
                    'source_id' => $source->id,
                    // Keyed as capture keys a click, which reports read; the entry id, which only a visitor's journey reads, stays unset.
                    'source_key' => AnalyticsSource::keyFor($channel, (int) $source->id, null),
                    'landing_key' => LandingRoute::key(null, null, null),
                    'site_ref' => $network ? (string) mt_rand(1, 3000) : null,
                    'zone_ref' => $network ? (string) mt_rand(1, 20000) : null,
                    'visitor_hash' => hash('sha256', 'volume-'.$done.'-'.$i),
                    'known' => false,
                    'device' => mt_rand(0, 1) === 1 ? 'mobile' : 'desktop',
                    'country' => ['US', 'GB', 'DE', 'BR', 'IN'][mt_rand(0, 4)],
                    'price_set_id' => $prices,
                    'country_group' => 'rest',
                    'worth_percent' => 100,
                    'bot_flags' => 0,
                    'arrived_at' => $arrived,
                    'network_day' => $arrived->toDateString(),
                    'holds_until' => $arrived->addDays(7),
                    'last_seen_at' => $arrived,
                    'opened_at' => $opened ? $arrived : null,
                    'started_at' => $opened && mt_rand(1, 100) <= 50 ? $arrived : null,
                    'account_at' => $opened && mt_rand(1, 1000) <= 15 ? $arrived : null,
                    'rounds_answered' => 0,
                    'spend' => number_format(mt_rand(20, 120) / 100000, 8, '.', ''),
                    'spend_basis' => TrafficSpendBasis::ClickCost->value,
                    'spend_estimated' => false,
                    'value_real' => '0',
                    'value_expected' => '0',
                ];
            }

            TrafficVisit::query()->insert($rows);
            $this->command?->getOutput()->write('.');
        }

        $this->command?->newLine();
    }
}
