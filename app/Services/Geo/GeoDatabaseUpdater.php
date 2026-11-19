<?php

namespace App\Services\Geo;

use App\Enums\GeoEdition;
use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use App\Services\Geo\Sources\DbipMonthlySource;
use App\Services\Geo\Sources\GeoDatabaseSource;
use App\Services\Geo\Sources\JsdelivrFileSource;
use App\Services\Geo\Sources\NpmTarballSource;
use App\Support\Geo\GeoDatabaseLocator;
use App\Support\Geo\Update\GeoDatabaseValidator;
use App\Support\Geo\Update\GeoEditionSpec;
use App\Support\Geo\Update\GeoRelease;
use App\Support\Geo\Update\GeoStateStore;
use App\Support\Geo\Update\GeoUpdatePlan;
use App\Support\Geo\Update\GzipInflater;
use App\Support\Geo\Update\TarMemberExtractor;
use App\Support\Observability\DomainLogger;
use Carbon\CarbonImmutable;

/**
 * Keeps one edition's database current (docs/architecture/geo.md): checks
 * the edition's sources in order, downloads the first newer build by its
 * exact version, verifies its checksum, unpacks it, checks it with the
 * reader, and puts it in place of the serving file without a moment without
 * one — the old file stays behind as previous.mmdb for a week. A failure at
 * any step leaves the serving database alone and is written to the state.
 */
final class GeoDatabaseUpdater
{
    public function __construct(
        private readonly GeoDatabaseLocator $locator,
        private readonly GeoStateStore $states,
        private readonly GeoHttp $http,
        private readonly GzipInflater $gzip,
        private readonly TarMemberExtractor $tar,
        private readonly GeoDatabaseValidator $validator,
        private readonly DomainLogger $logger,
    ) {}

    public function spec(GeoEdition $edition): GeoEditionSpec
    {
        return GeoEditionSpec::fromConfig($edition, (array) config('geo.editions.'.$edition->value));
    }

    public function update(GeoEdition $edition, CarbonImmutable $now, ?CarbonImmutable $refreshRequestedAt, bool $force): GeoUpdateOutcome
    {
        $this->ensureDirectories();
        $spec = $this->spec($edition);
        $state = $this->states->read($edition);
        $current = $this->locator->current($edition);
        $plan = GeoUpdatePlan::decide($state, $spec, $now, $refreshRequestedAt, $force, is_file($current));

        if ($plan->dropPrevious) {
            @unlink($this->locator->previous($edition));
            $state['previous'] = null;
            $this->states->write($edition, $state);
        }

        if (! $plan->due) {
            return GeoUpdateOutcome::Idle;
        }

        $lock = @fopen($this->locator->lock($edition), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return GeoUpdateOutcome::Busy;
        }

        try {
            $state = $this->states->read($edition);
            $state['attempt_started_at'] = $now->toIso8601String();
            $state['last_attempt_at'] = $now->toIso8601String();

            foreach (glob($this->locator->editionDirectory($edition).'/*.part') ?: [] as $part) {
                @unlink($part);
            }

            if ($plan->withdraw) {
                @unlink($current);
                @unlink($this->locator->previous($edition));
                $state['installed'] = null;
                $state['previous'] = null;
                $state['last_result'] = 'withdrawn';
                $this->logger->warning('geo.database.withdrawn', ['edition' => $edition->value]);
            }

            if (! $this->intact($current, $state)) {
                $state['installed'] = null;
            }

            $this->states->write($edition, $state);

            return $this->attempt($spec, $state, $now);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Puts previous.mmdb back in service and keeps the build it replaces from being installed again. */
    public function rollback(GeoEdition $edition, CarbonImmutable $now): GeoUpdateOutcome
    {
        $state = $this->states->read($edition);
        $previous = $this->locator->previous($edition);

        if (! is_file($previous) || ! is_array($state['previous'] ?? null)) {
            throw GeoUpdateException::because(GeoUpdateFailure::NothingToRollBack);
        }

        if (! @rename($previous, $this->locator->current($edition))) {
            throw GeoUpdateException::because(GeoUpdateFailure::SwapFailed);
        }

        $state['skip_version'] = $state['installed']['version'] ?? null;
        $state['installed'] = array_diff_key($state['previous'], ['kept_until' => true]);
        $state['previous'] = null;
        $state['last_result'] = 'rolled_back';
        $state['next_check_at'] = $now->addHours((int) config('geo.check_every_hours'))->toIso8601String();
        $this->states->write($edition, $state);
        $this->logger->info('geo.database.rolled_back', ['edition' => $edition->value]);

        return GeoUpdateOutcome::RolledBack;
    }

    /** Deletes an edition's database files, for when the other edition serves again. */
    public function discard(GeoEdition $edition): void
    {
        $existed = is_file($this->locator->current($edition)) || is_file($this->locator->previous($edition));
        @unlink($this->locator->current($edition));
        @unlink($this->locator->previous($edition));

        if ($existed) {
            $state = $this->states->read($edition);
            $state['installed'] = null;
            $state['previous'] = null;
            $state['last_result'] = 'discarded';
            $this->states->write($edition, $state);
        }
    }

    /** @param  array<string, mixed>  $state */
    private function attempt(GeoEditionSpec $spec, array $state, CarbonImmutable $now): GeoUpdateOutcome
    {
        $edition = $spec->edition;
        $lastError = null;

        try {
            if (! $this->gzip->available()) {
                throw GeoUpdateException::because(GeoUpdateFailure::ZlibMissing);
            }

            $free = @disk_free_space($this->locator->editionDirectory($edition));

            if ($free !== false && $free < $spec->maxArchiveBytes * 2 + $spec->maxDatabaseBytes) {
                throw GeoUpdateException::because(GeoUpdateFailure::DiskSpace);
            }

            foreach ($spec->sources as $key) {
                try {
                    $release = $this->source($key)->discover($spec);
                    $installed = is_array($state['installed'] ?? null) ? $state['installed'] : null;

                    if (($installed !== null && ($installed['version'] ?? null) === $release->token) || ($state['skip_version'] ?? null) === $release->token) {
                        return $this->finish($edition, $state, $now, 'current');
                    }

                    $this->install($spec, $release, $state, $now);

                    return GeoUpdateOutcome::Updated;
                } catch (GeoUpdateException $exception) {
                    // The failure that says most is kept: a source that could
                    // not even name its build tells less than one whose build
                    // was refused.
                    if ($lastError === null || $lastError->failure === GeoUpdateFailure::DiscoveryFailed) {
                        $lastError = $exception;
                    }
                }
            }

            throw $lastError ?? GeoUpdateException::because(GeoUpdateFailure::DiscoveryFailed);
        } catch (GeoUpdateException $exception) {
            foreach (glob($this->locator->editionDirectory($edition).'/*.part') ?: [] as $part) {
                @unlink($part);
            }

            $failures = (int) ($state['failures'] ?? 0) + 1;
            $state['failures'] = $failures;
            $state['last_result'] = 'failed';
            $state['last_error'] = ['code' => $exception->failure->value, 'source' => $exception->source, 'at' => $now->toIso8601String()];
            $state['next_check_at'] = GeoUpdatePlan::retryAt($now, $failures, (array) config('geo.retry_after_minutes'))->toIso8601String();
            $this->states->write($edition, $state);

            $context = ['edition' => $edition->value, 'failure' => $exception->failure->value, 'source' => $exception->source, 'failures' => $failures];
            $failures >= (int) config('geo.alert_after_failures')
                ? $this->logger->error('geo.database.update_failed', $context)
                : $this->logger->warning('geo.database.update_failed', $context);

            return GeoUpdateOutcome::Failed;
        }
    }

    /** @param  array<string, mixed>  $state */
    private function install(GeoEditionSpec $spec, GeoRelease $release, array $state, CarbonImmutable $now): void
    {
        $edition = $spec->edition;
        $download = $this->locator->part($edition, 'download');
        $database = $this->locator->part($edition, 'database');

        $this->http->download($release->url, $download, $spec->maxArchiveBytes, $release->source);
        clearstatcache(true, $download);

        if ($release->size !== null && (int) filesize($download) !== $release->size) {
            throw GeoUpdateException::because(GeoUpdateFailure::SizeMismatch, $release->source);
        }

        if ($release->checksumAlgorithm !== null && $release->checksum !== null
            && ! hash_equals((string) base64_decode($release->checksum, true), hash_file($release->checksumAlgorithm, $download, true))) {
            throw GeoUpdateException::because(GeoUpdateFailure::ChecksumMismatch, $release->source);
        }

        try {
            if ($release->isPackage()) {
                $tar = $this->locator->part($edition, 'package');
                $gzip = $this->locator->part($edition, 'gzip');
                $this->gzip->inflate($download, $tar, $spec->maxArchiveBytes + 1024 * 1024);
                $this->tar->extract($tar, (string) $release->member, $gzip, $spec->maxArchiveBytes);
                $this->gzip->inflate($gzip, $database, $spec->maxDatabaseBytes);
            } else {
                $this->gzip->inflate($download, $database, $spec->maxDatabaseBytes);
            }
        } catch (GeoUpdateException $exception) {
            throw GeoUpdateException::because($exception->failure, $release->source);
        }

        $installed = is_array($state['installed'] ?? null) ? $state['installed'] : null;

        try {
            $checked = $this->validator->check(
                $database,
                $spec,
                is_int($installed['build_epoch'] ?? null) ? $installed['build_epoch'] : null,
                $now,
                array_map('strval', (array) config('geo.probes')),
                (int) config('geo.probes_required'),
            );
        } catch (GeoUpdateException $exception) {
            throw GeoUpdateException::because($exception->failure, $release->source);
        }

        $this->swap($edition, $database);

        $state['previous'] = $installed === null ? null : [...$installed, 'kept_until' => $now->addDays((int) config('geo.previous_kept_days'))->toIso8601String()];
        $state['installed'] = [
            'source' => $release->source,
            'version' => $release->token,
            'build_epoch' => $checked['build_epoch'],
            'database_type' => $checked['database_type'],
            'size' => (int) filesize($this->locator->current($edition)),
            'sha256' => hash_file('sha256', $this->locator->current($edition)),
            'installed_at' => $now->toIso8601String(),
        ];
        $state['skip_version'] = null;

        foreach (glob($this->locator->editionDirectory($edition).'/*.part') ?: [] as $part) {
            @unlink($part);
        }

        $this->finish($edition, $state, $now, 'updated');
        $this->logger->info('geo.database.updated', ['edition' => $edition->value, 'source' => $release->source, 'version' => $release->token, 'build_epoch' => $checked['build_epoch']]);
    }

    /** @param  array<string, mixed>  $state */
    private function finish(GeoEdition $edition, array $state, CarbonImmutable $now, string $result): GeoUpdateOutcome
    {
        $state['failures'] = 0;
        $state['last_error'] = null;
        $state['last_result'] = $result;
        $state['last_checked_at'] = $now->toIso8601String();
        $state['next_check_at'] = $now->addHours((int) config('geo.check_every_hours'))->toIso8601String();
        $this->states->write($edition, $state);

        return $result === 'updated' ? GeoUpdateOutcome::Updated : GeoUpdateOutcome::Current;
    }

    /** The new file takes the serving name in one rename; the old one is kept as previous.mmdb by a second name for it. */
    private function swap(GeoEdition $edition, string $database): void
    {
        $current = $this->locator->current($edition);
        $previous = $this->locator->previous($edition);
        @chmod($database, 0640);

        if (is_file($current)) {
            @unlink($previous);

            if (! @link($current, $previous) && ! @copy($current, $previous)) {
                throw GeoUpdateException::because(GeoUpdateFailure::SwapFailed);
            }
        }

        if (! @rename($database, $current)) {
            throw GeoUpdateException::because(GeoUpdateFailure::SwapFailed);
        }
    }

    /** @param  array<string, mixed>  $state */
    private function intact(string $current, array $state): bool
    {
        $installed = $state['installed'] ?? null;

        if (! is_file($current) || ! is_array($installed)) {
            return false;
        }

        clearstatcache(true, $current);

        return (int) filesize($current) === (int) ($installed['size'] ?? -1)
            && hash_file('sha256', $current) === ($installed['sha256'] ?? null);
    }

    private function source(string $key): GeoDatabaseSource
    {
        return match ($key) {
            'npm' => new NpmTarballSource($this->http),
            'jsdelivr' => new JsdelivrFileSource($this->http),
            'dbip' => new DbipMonthlySource($this->http),
            default => throw GeoUpdateException::because(GeoUpdateFailure::DiscoveryFailed, $key),
        };
    }

    private function ensureDirectories(): void
    {
        foreach (GeoEdition::cases() as $edition) {
            $directory = $this->locator->editionDirectory($edition);

            if (! is_dir($directory)) {
                @mkdir($directory, 0750, true);
            }
        }
    }
}
