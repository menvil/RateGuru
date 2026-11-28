<?php

namespace App\Console\Commands;

use App\Enums\GeoEdition;
use App\Exceptions\Geo\GeoUpdateException;
use App\Models\TrafficSetting;
use App\Services\Geo\GeoDatabaseUpdater;
use App\Services\Geo\GeoUpdateOutcome;
use App\Services\Geo\NetworkListUpdater;
use App\Support\Geo\GeoDatabaseLocator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Keeps the local IP location database current (docs/architecture/geo.md).
 * Scheduled every minute and done at once when there is nothing to do: an
 * edition is checked once a day, sooner after a failure or when the
 * administrator asks for a refresh. When the chosen edition has no database
 * file (current.mmdb) — not installed yet, its downloads still failing, or
 * taken out of service by its licence age — the other edition is installed and
 * serves meanwhile; once the chosen one serves again, the other one's files are
 * deleted. A chosen file that is there but cannot be read is not replaced by
 * the other edition: lookups answer unknown until the edition's next check
 * finds that it no longer matches its recorded size and SHA-256 and installs
 * it again.
 *
 * The networks' database (DB-IP ASN Lite) and the network lists — the
 * hosting and VPN networks, the addresses of iCloud Private Relay — are kept
 * the same way on their own schedules in the same run; none of them stands
 * in for another, and a failure of theirs never fails the run.
 */
final class GeoUpdateCommand extends Command
{
    protected $signature = 'geo:update
        {--force : Check for a newer build now, whenever the last check was}
        {--rollback : Put the database the last update replaced back in service}';

    protected $description = 'Download, check and install the newest IP location database when there is one.';

    public function handle(GeoDatabaseUpdater $updater, GeoDatabaseLocator $locator, NetworkListUpdater $lists): int
    {
        $now = CarbonImmutable::now('UTC');
        [$chosen, $requestedAt] = $this->preferences();

        if ((bool) $this->option('rollback')) {
            try {
                $rolledBack = $updater->rollback($chosen, $now);
            } catch (GeoUpdateException $exception) {
                $this->error($exception->failure->description());

                return self::FAILURE;
            }

            if ($rolledBack === GeoUpdateOutcome::Busy) {
                $this->error("An update of the {$chosen->label()} database is running; try again when it finishes.");

                return self::FAILURE;
            }

            $this->info("The previous {$chosen->label()} database serves again.");

            return self::SUCCESS;
        }

        $outcome = $updater->update($chosen, $now, $requestedAt, (bool) $this->option('force'));

        if (is_file($locator->current($chosen))) {
            $updater->discard($chosen->other());
        } else {
            $fallback = $updater->update($chosen->other(), $now, $requestedAt, (bool) $this->option('force'));

            if ($fallback !== GeoUpdateOutcome::Idle) {
                $this->line("{$chosen->other()->label()}, standing in: {$fallback->value}.");
            }
        }

        if ($outcome !== GeoUpdateOutcome::Idle) {
            $this->line("{$chosen->label()}: {$outcome->value}.");
        }

        // The networks' database on its own schedule beside the city one: its failure leaves the city one serving, and is told, not fatal.
        $networks = $updater->update(GeoEdition::DbipAsnLite, $now, $requestedAt, (bool) $this->option('force'));

        if ($networks !== GeoUpdateOutcome::Idle) {
            $this->line(GeoEdition::DbipAsnLite->label().": {$networks->value}.");
        }

        $lists = $lists->update($now, $requestedAt, (bool) $this->option('force'));

        foreach ($lists as $list => $listOutcome) {
            if ($listOutcome !== GeoUpdateOutcome::Idle) {
                $this->line("{$list}: {$listOutcome->value}.");
            }
        }

        return $outcome === GeoUpdateOutcome::Failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The edition to serve and when the administrator last asked for a
     * refresh, as Traffic sources → Sources (Tracking) has them.
     *
     * @return array{0: GeoEdition, 1: ?CarbonImmutable}
     */
    private function preferences(): array
    {
        $settings = TrafficSetting::current();

        return [$settings->cityEdition(), $settings->geo_refresh_requested_at];
    }
}
