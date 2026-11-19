<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficConnectionStatus;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficApiConnection;
use App\Models\TrafficSource;
use App\Models\User;
use App\Services\Traffic\Connectors\ConnectorException;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\Connectors\ConnectorSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Connects a source to its network's API with a key the administrator typed
 * — checked with the network first, outside any transaction, then kept
 * encrypted — or disconnects it, forgetting the key and switching blocking
 * by itself off. A connected source is synced at once, and its spend read
 * back up to 30 days.
 */
final class ConnectTrafficApiAction
{
    use LocksTrafficRecords;

    public const int BACKFILL_DAYS = 30;

    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * @param  array<string, string>  $secret  `api_key` for PopAds, `api_token` for ExoClick
     *
     * @throws CannotManageTrafficException
     */
    public function connect(User $admin, TrafficSource $source, array $secret): TrafficApiConnection
    {
        if ($source->connector === null) {
            throw CannotManageTrafficException::because('This network has no API RateGuru can connect to yet.');
        }

        DB::transaction(fn () => $this->lockAdministrator($admin));
        $session = new ConnectorSession(array_map('trim', $secret));

        try {
            $account = $this->registry->connector($source->connector)->verify($session);
        } catch (ConnectorException $exception) {
            throw CannotManageTrafficException::because($exception->getMessage());
        }

        return DB::transaction(function () use ($admin, $source, $session, $account): TrafficApiConnection {
            $locked = $this->lockAdministrator($admin);
            $this->lockSource((int) $source->id);
            $connection = TrafficApiConnection::query()->where('source_id', $source->id)->first() ?? new TrafficApiConnection;

            $connection->forceFill([
                'source_id' => $source->id,
                'connector' => $source->connector,
                'credentials' => $session->credentials(),
                'account_label' => mb_substr($account->label, 0, 120),
                'status' => TrafficConnectionStatus::Connected,
                'next_sync_at' => now(),
                'campaigns_synced_at' => null,
                'backfill_cursor' => CarbonImmutable::now($source->reporting_timezone)->subDays(self::BACKFILL_DAYS)->toDateString(),
                'last_error' => null,
                'failures' => 0,
                'connected_by_user_id' => $locked->id,
            ])->save();

            return $connection;
        });
    }

    /** @throws CannotManageTrafficException */
    public function disconnect(User $admin, TrafficSource $source): void
    {
        DB::transaction(function () use ($admin, $source): void {
            $this->lockAdministrator($admin);
            $record = $this->lockSource((int) $source->id);
            TrafficApiConnection::query()->where('source_id', $source->id)->update(['credentials' => null, 'status' => TrafficConnectionStatus::Disconnected, 'next_sync_at' => null]);
            $record->forceFill(['block_auto' => false, 'lock_version' => $record->lock_version + 1])->save();
        });
    }

    /** "Sync now": the next run of traffic:sync-networks takes it first. */
    public function syncNow(User $admin, TrafficSource $source): void
    {
        DB::transaction(function () use ($admin, $source): void {
            $this->lockAdministrator($admin);
            TrafficApiConnection::query()->where('source_id', $source->id)->where('status', '!=', TrafficConnectionStatus::Disconnected)
                ->update(['next_sync_at' => now(), 'status' => TrafficConnectionStatus::Connected, 'failures' => 0]);
        });
    }
}
