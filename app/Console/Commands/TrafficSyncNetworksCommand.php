<?php

namespace App\Console\Commands;

use App\Actions\Traffic\ApplyTrafficBlocksAction;
use App\Actions\Traffic\SyncTrafficNetworkAction;
use App\Enums\TrafficConnectionStatus;
use App\Models\TrafficApiConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The one process that talks to ad networks' APIs (docs/architecture/
 * traffic.md, "Sync"), so their limits and sessions never compete: every
 * minute, for up to 45 seconds, the blocks waiting for a network first,
 * then the connections due, the longest waiting first. It never changes a
 * bid or a budget and never touches money.
 */
final class TrafficSyncNetworksCommand extends Command
{
    protected $signature = 'traffic:sync-networks';

    protected $description = 'Read spend and campaigns from ad networks\' APIs and apply blocks there.';

    private const int SECONDS = 45;

    public function handle(ApplyTrafficBlocksAction $blocks, SyncTrafficNetworkAction $sync): int
    {
        $deadline = microtime(true) + self::SECONDS;

        foreach (TrafficApiConnection::query()->where('status', TrafficConnectionStatus::Connected)->orderBy('id')->get() as $connection) {
            if (microtime(true) >= $deadline) {
                return self::SUCCESS;
            }

            $blocks->handle($connection);
        }

        $due = TrafficApiConnection::query()
            ->where('status', TrafficConnectionStatus::Connected)
            ->where('next_sync_at', '<=', now())
            ->orderBy('next_sync_at')
            ->get();

        foreach ($due as $connection) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $sync->handle($connection, CarbonImmutable::now());
        }

        return self::SUCCESS;
    }
}
