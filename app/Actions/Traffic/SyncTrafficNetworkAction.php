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
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Services\Traffic\Connectors\ConnectorException;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\Connectors\ConnectorSession;
use App\Services\Traffic\Connectors\EditsBlockLists;
use App\Services\Traffic\Connectors\NetworkCampaign;
use App\Services\Traffic\Connectors\ReadsBlockLists;
use App\Services\Traffic\Connectors\ReadsCampaigns;
use App\Services\Traffic\Connectors\ReadsImpressionsByCountry;
use App\Services\Traffic\Connectors\ReadsSpend;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One connection's turn with its network (docs/architecture/traffic.md,
 * "Sync"): spend for today and yesterday in the network's time zone,
 * yesterday closed once its figures came complete and six hours of the next
 * day have passed, then a few older days back to 30 before it was connected;
 * the campaigns' own details each turn, so a campaign that has just had its
 * first click is named within minutes; and every six hours each campaign's
 * block list, read and never written (ReadTrafficBlockListsAction), so what
 * was blocked or unblocked in the network's own panel shows — a list that
 * can't be read failing nothing but itself, short of a refused key, and
 * kept on the connection, with the campaign whose list it was, until a read
 * goes through; then RateGuru's own blocks queued for the running campaigns
 * they are missing from (QueueMissingTrafficBlocksAction), a campaign that
 * came since among them, for the next apply to send. A turn comes every
 * five minutes. Only campaigns RateGuru saw a click of are read and kept: a
 * network account can hold campaigns sent elsewhere. A failure waits longer
 * each time; a refused key, an account not in dollars or five failures
 * running need the administrator. A connection reconnected or disconnected
 * during a turn keeps its new state: how the turn went is the old
 * connection's.
 */
final class SyncTrafficNetworkAction
{
    private const int EVERY_MINUTES = 5;

    private const int CLOSE_AFTER_HOURS = 6;

    private const int BACKFILL_PER_RUN = 3;

    private const int FAILURES = 5;

    private const int READ_LISTS_EVERY_HOURS = 6;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ReplaceSpendSliceAction $slices,
        private readonly ReplaceSpendCountriesAction $countryFigures,
        private readonly RecordTrafficCampaignEventAction $history,
        private readonly ReadTrafficBlockListsAction $lists,
        private readonly QueueMissingTrafficBlocksAction $missing,
    ) {}

    public function handle(TrafficApiConnection $connection, CarbonImmutable $now): void
    {
        $source = TrafficSource::query()->find($connection->source_id);

        if ($source === null || $source->status === TrafficSourceStatus::Ended) {
            return;
        }

        // The credentials as stored when the turn began: a reconnect stores new ones and a disconnect none, so a turn that outlived either is no longer the connection's.
        $fence = $connection->getRawOriginal('credentials');

        try {
            $session = new ConnectorSession($connection->credentials ?? []);
        } catch (DecryptException) {
            $this->failed($connection, $fence, new ConnectorException(TrafficConnectorFailure::CredentialsUnreadable), $now);

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

            // The campaigns' block lists, read and never written, every few hours: a call a campaign, and what they hold changes by hand.
            if ($connector instanceof ReadsBlockLists && ($connection->block_lists_read_at === null || $connection->block_lists_read_at->addHours(self::READ_LISTS_EVERY_HOURS)->lessThanOrEqualTo($now))) {
                $failure = $this->readLists((int) $source->id, $connector, $session);
                // Whose list couldn't be read, and why, for the source's Blocking pane — until a read goes through.
                $connection->forceFill([
                    'block_lists_read_at' => $now,
                    'block_lists_error' => $failure?->failure->value,
                    'block_lists_error_campaign' => $failure?->campaignId,
                ]);
            }

            // RateGuru's own blocks are for every running campaign: one that came since — its first click — or whose list was just read
            // without one, nothing refused there, is sent it.
            if ($connector instanceof EditsBlockLists) {
                // A campaign that missed one only because the network was slow or down is sent it again once its list was read since.
                $this->missing->handle((int) $source->id, $connector->blockLevels(), $connection->block_lists_read_at);
            }

            $this->settle($connection, $fence, fn (): array => [
                'credentials' => $session->changed() ? $session->credentials() : $connection->credentials,
                'status' => TrafficConnectionStatus::Connected,
                'last_synced_at' => $now,
                'next_sync_at' => $now->addMinutes(self::EVERY_MINUTES),
                'failures' => 0,
                'last_error' => null,
            ]);
        } catch (ConnectorException $exception) {
            if ($session->changed()) {
                $connection->credentials = $session->credentials();
            }

            $this->failed($connection, $fence, $exception, $now);
        } catch (Throwable $exception) {
            // An answer that can't be read — a figure in words, a field of the wrong shape — is the network's: it backs off like any failure.
            report($exception);
            $this->failed($connection, $fence, new ConnectorException(TrafficConnectorFailure::UnexpectedResponse), $now);
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
        $yesterday = $local->subDay();
        $days = [$today, $yesterday->toDateString()];
        $firstVisit = TrafficVisit::query()->where('source_id', $source->id)->min('network_day');
        $cursor = $connection->backfill_cursor;

        // Reading back stops at yesterday, which every turn reads anyway: every older day is read, the one before yesterday too.
        for ($n = 0; $cursor !== null && $n < self::BACKFILL_PER_RUN && $cursor < $yesterday->toDateString(); $n++) {
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

        $closable = $now->greaterThanOrEqualTo($yesterday->addDay()->startOfDay()->addHours(self::CLOSE_AFTER_HOURS));

        if ($closable && ($complete[$yesterday->toDateString()] ?? false)) {
            $connection->spend_closed_through = max((string) $connection->spend_closed_through, $yesterday->toDateString());
        }

        $connection->backfill_cursor = $cursor !== null && $cursor >= $yesterday->toDateString() ? null : $cursor;

        if ($connector instanceof ReadsImpressionsByCountry) {
            $this->countries($connector, $session, $connection, $source, array_values(array_unique($days)), $networkIds, $campaigns, $yesterday->toDateString());
        }
    }

    /**
     * The same days' impressions by country, and a few older days each turn
     * from the first the API reported, so a connection made before this was
     * read catches up. Spend for the turn is already stored: a failure here
     * costs only these figures, and the older days are asked for again.
     *
     * @param  list<string>  $days
     * @param  list<string>  $networkIds
     * @param  array<string, int>  $campaigns  by the network's ID
     */
    private function countries(ReadsImpressionsByCountry $connector, ConnectorSession $session, TrafficApiConnection $connection, TrafficSource $source, array $days, array $networkIds, array $campaigns, string $yesterday): void
    {
        $cursor = $connection->country_cursor ?? TrafficSpendDay::query()
            ->where('source_id', $source->id)
            ->where('origin', TrafficSpendOrigin::Api)
            ->whereNotNull('reported_impressions')
            ->min('network_day');

        for ($n = 0; $cursor !== null && $n < self::BACKFILL_PER_RUN && $cursor < $yesterday; $n++) {
            $days[] = (string) $cursor;
            $cursor = CarbonImmutable::parse((string) $cursor)->addDay()->toDateString();
        }

        try {
            foreach (array_unique($days) as $day) {
                $rows = [];

                foreach ($connector->impressionsByCountry($session, $day, $networkIds) as $row) {
                    if (isset($campaigns[$row['campaign']])) {
                        $rows[] = ['campaign_id' => $campaigns[$row['campaign']], 'site_ref' => $row['site'], 'country' => $row['country'], 'impressions' => $row['impressions'], 'amount' => $row['amount']];
                    }
                }

                $this->countryFigures->handle((int) $source->id, $day, $rows);
            }
        } catch (ConnectorException) {
            return;
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $connection->country_cursor = $cursor === null ? null : (string) $cursor;
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

    /**
     * The campaigns' block lists, read beside the spend the turn is for: a
     * list that can't be read — an answer of a shape RateGuru doesn't know,
     * the API down for it — changes nothing and fails nothing, and is read
     * again at the next read; only a key the network refused is the
     * connection's to answer for. Reported, never with the key, and given
     * back, naming the campaign whose list it was, for the source's
     * Blocking pane to say.
     *
     * @return ConnectorException|null why the read failed, or nothing once it went through
     *
     * @throws ConnectorException
     */
    private function readLists(int $sourceId, ReadsBlockLists $connector, ConnectorSession $session): ?ConnectorException
    {
        try {
            $this->lists->handle($sourceId, $connector, $session);
        } catch (ConnectorException $exception) {
            if ($exception->failure->needsAttention()) {
                throw $exception;
            }

            report($exception);

            return $exception;
        }

        return null;
    }

    private function failed(TrafficApiConnection $connection, mixed $fence, ConnectorException $exception, CarbonImmutable $now): void
    {
        $this->settle($connection, $fence, function (TrafficApiConnection $current) use ($exception, $now): array {
            $failures = $current->failures + 1;

            return [
                'failures' => $failures,
                'last_error' => $exception->failure->value,
                'status' => $exception->failure->needsAttention() || $failures >= self::FAILURES ? TrafficConnectionStatus::NeedsAttention : TrafficConnectionStatus::Connected,
                'next_sync_at' => $now->addMinutes((int) min(360, 15 * 2 ** ($failures - 1))),
            ];
        });
    }

    /**
     * Writes how the turn went — with what it read along the way — on the
     * connection still locked as it was when the turn began. One reconnected
     * or disconnected meanwhile keeps its new state: the turn was the old
     * connection's. Never around a call to the network.
     *
     * @param  callable(TrafficApiConnection): array<string, mixed>  $outcome  given the locked row
     */
    private function settle(TrafficApiConnection $connection, mixed $fence, callable $outcome): void
    {
        DB::transaction(function () use ($connection, $fence, $outcome): void {
            $current = TrafficApiConnection::query()->whereKey($connection->id)->lockForUpdate()->first();

            if ($current === null || $current->status === TrafficConnectionStatus::Disconnected || $current->getRawOriginal('credentials') !== $fence) {
                return;
            }

            $connection->forceFill($outcome($current))->save();
        });
    }
}
