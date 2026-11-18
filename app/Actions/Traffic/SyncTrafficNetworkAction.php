<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnectorFailure;
use App\Enums\TrafficDevice;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficApiConnection;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignPrice;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Services\Traffic\Connectors\ConnectorException;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\Connectors\ConnectorSession;
use App\Services\Traffic\Connectors\NetworkCampaign;
use App\Services\Traffic\Connectors\ReadsCampaigns;
use App\Services\Traffic\Connectors\ReadsSpend;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One connection's turn with its network (docs/architecture/traffic.md,
 * "Sync"): spend for today and yesterday in the network's time zone, a day
 * closed once its figures and the two before came complete and six hours
 * have passed, then a few older days back to 30 before it was connected;
 * the campaigns' own details each turn, so a campaign that has just had its
 * first click is named within minutes. A turn comes every five minutes.
 * Only campaigns RateGuru saw a
 * click of are read and kept: a network account can hold campaigns sent
 * elsewhere. A failure waits longer each time; a refused key, an account not
 * in dollars or five failures running need the administrator.
 */
final class SyncTrafficNetworkAction
{
    private const int EVERY_MINUTES = 5;

    private const int CLOSE_AFTER_HOURS = 6;

    private const int BACKFILL_PER_RUN = 3;

    private const int FAILURES = 5;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ReplaceSpendSliceAction $slices,
        private readonly RecordTrafficCampaignEventAction $history,
    ) {}

    public function handle(TrafficApiConnection $connection, CarbonImmutable $now): void
    {
        $source = TrafficSource::query()->find($connection->source_id);

        if ($source === null || $source->status === TrafficSourceStatus::Ended) {
            return;
        }

        try {
            $session = new ConnectorSession($connection->credentials ?? []);
        } catch (DecryptException) {
            $this->failed($connection, new ConnectorException(TrafficConnectorFailure::CredentialsUnreadable), $now);

            return;
        }

        try {
            $connector = $this->registry->connector($connection->connector);
            $campaigns = TrafficCampaign::query()->where('source_id', $source->id)->pluck('id', 'external_id')->all();

            if ($connector instanceof ReadsSpend) {
                $this->spend($connector, $session, $connection, $source, array_map('strval', array_keys($campaigns)), $campaigns, $now);
            }

            if ($connector instanceof ReadsCampaigns) {
                // Details only for the campaigns still running: an archived one has nothing to sync.
                $running = TrafficCampaign::query()->where('source_id', $source->id)->where('status', '!=', TrafficCampaignStatus::Archived)->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
                $this->campaigns($connector->campaigns($session, $running), $source, $now);
                $connection->campaigns_synced_at = $now;
            }

            $connection->forceFill([
                'credentials' => $session->changed() ? $session->credentials() : $connection->credentials,
                'status' => TrafficConnectionStatus::Connected,
                'last_synced_at' => $now,
                'next_sync_at' => $now->addMinutes(self::EVERY_MINUTES),
                'failures' => 0,
                'last_error' => null,
            ])->save();
        } catch (ConnectorException $exception) {
            if ($session->changed()) {
                $connection->credentials = $session->credentials();
            }

            $this->failed($connection, $exception, $now);
        } catch (Throwable $exception) {
            // An answer that can't be read — a figure in words, a field of the wrong shape — is the network's: it backs off like any failure.
            report($exception);
            $this->failed($connection, new ConnectorException(TrafficConnectorFailure::UnexpectedResponse), $now);
        }
    }

    /**
     * @param  list<string>  $networkIds
     * @param  array<string, int>  $campaigns  by the network's ID
     */
    private function spend(ReadsSpend $connector, ConnectorSession $session, TrafficApiConnection $connection, TrafficSource $source, array $networkIds, array $campaigns, CarbonImmutable $now): void
    {
        $local = $now->setTimezone($source->reporting_timezone);
        $today = $local->toDateString();
        $days = [$today, $local->subDay()->toDateString()];
        $firstVisit = TrafficVisit::query()->where('source_id', $source->id)->min('network_day');
        $cursor = $connection->backfill_cursor;

        for ($n = 0; $cursor !== null && $n < self::BACKFILL_PER_RUN && $cursor < $local->subDays(2)->toDateString(); $n++) {
            if ($firstVisit === null || $cursor >= (string) $firstVisit) {
                $days[] = $cursor;
            }

            $cursor = CarbonImmutable::parse($cursor)->addDay()->toDateString();
        }

        $complete = [];

        foreach (array_unique($days) as $day) {
            $report = $connector->spendForDay($session, $day, $networkIds);
            $complete[$day] = $report->complete;
            $rows = [];

            foreach ($report->rows as $row) {
                if ($row['campaign'] !== '' && ! isset($campaigns[$row['campaign']])) {
                    continue;
                }

                $rows[] = ['campaign_id' => $row['campaign'] === '' ? null : $campaigns[$row['campaign']], 'site_ref' => $row['site'], 'zone_ref' => $row['zone'], 'amount' => $row['amount'], 'clicks' => $row['clicks'], 'impressions' => $row['impressions']];
            }

            DB::transaction(fn () => $this->slices->handle((int) $source->id, $day, TrafficSpendOrigin::Api, $rows));
        }

        $yesterday = $local->subDay();
        $closable = $now->greaterThanOrEqualTo($yesterday->addDay()->startOfDay()->addHours(self::CLOSE_AFTER_HOURS));

        if ($closable && ($complete[$yesterday->toDateString()] ?? false)) {
            $connection->spend_closed_through = max((string) $connection->spend_closed_through, $yesterday->toDateString());
        }

        $connection->backfill_cursor = $cursor !== null && $cursor >= $local->subDays(2)->toDateString() ? null : $cursor;
    }

    /** @param  list<NetworkCampaign>  $networkCampaigns */
    private function campaigns(array $networkCampaigns, TrafficSource $source, CarbonImmutable $now): void
    {
        foreach ($networkCampaigns as $details) {
            DB::transaction(function () use ($details, $source, $now): void {
                $campaign = TrafficCampaign::query()->where('source_id', $source->id)->where('external_id', $details->id)->lockForUpdate()->first();

                if ($campaign === null) {
                    return;
                }

                $before = [$campaign->name, $campaign->bid_mobile, $campaign->bid_desktop, $campaign->daily_budget];
                $campaign->forceFill([
                    'name' => $details->name ?? $campaign->name,
                    'countries' => $details->countries ?? $campaign->countries,
                    'devices' => $details->devices ?? $campaign->devices,
                    'format' => $details->format ?? $campaign->format,
                    'bid_model' => $details->bidModel ?? $campaign->bid_model,
                    'bid_mobile' => $details->bidMobile ?? $campaign->bid_mobile,
                    'bid_desktop' => $details->bidDesktop ?? $campaign->bid_desktop,
                    'daily_budget' => $details->dailyBudget ?? $campaign->daily_budget,
                    'synced_at' => $now,
                ])->save();

                if ($before[0] !== $campaign->name) {
                    $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Targeting, 'Named “'.$campaign->name.'” in the network', TrafficActor::NetworkApi);
                }

                foreach ([[TrafficDevice::Mobile, $before[1], $campaign->bid_mobile], [TrafficDevice::Desktop, $before[2], $campaign->bid_desktop]] as [$device, $old, $new]) {
                    if ($new !== null && (string) $old !== (string) $new) {
                        TrafficCampaignPrice::query()->create(['campaign_id' => $campaign->id, 'device' => $device, 'bid_model' => (string) $campaign->bid_model, 'price' => $new, 'effective_from' => $now, 'actor' => TrafficActor::NetworkApi]);
                        $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Bid, ucfirst($device->value).' bid changed in the network to '.$new, TrafficActor::NetworkApi);
                    }
                }

                if ((string) $before[3] !== (string) $campaign->daily_budget) {
                    $this->history->handle((int) $campaign->id, TrafficCampaignEventKind::Budget, 'Daily budget changed in the network to '.$campaign->daily_budget, TrafficActor::NetworkApi);
                }
            });
        }
    }

    private function failed(TrafficApiConnection $connection, ConnectorException $exception, CarbonImmutable $now): void
    {
        $failures = $connection->failures + 1;

        $connection->forceFill([
            'failures' => $failures,
            'last_error' => $exception->failure->value,
            'status' => $exception->failure->needsAttention() || $failures >= self::FAILURES ? TrafficConnectionStatus::NeedsAttention : TrafficConnectionStatus::Connected,
            'next_sync_at' => $now->addMinutes((int) min(360, 15 * 2 ** ($failures - 1))),
        ])->save();
    }
}
