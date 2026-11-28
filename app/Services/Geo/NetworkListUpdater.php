<?php

namespace App\Services\Geo;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use App\Support\Geo\GeoDatabaseLocator;
use App\Support\Geo\Networks\AddressRanges;
use App\Support\Geo\Networks\HostingAsnList;
use App\Support\Geo\Update\GeoUpdatePlan;
use App\Support\Observability\DomainLogger;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Keeps the network lists under storage/geoip/networks current, the way the
 * IP databases are kept (docs/architecture/geo.md): each list on its own
 * schedule (config geo.network_lists), fetched from fixed hosts only, read
 * and checked to hold at least as many entries as a real list does, written
 * beside the one in service and renamed over it — so a fetch that fails, or a
 * list that is short or unreadable, leaves the one in service as it was. A
 * failure waits as a database's does before the next try, and is logged as an
 * error once it has failed so many times in a row.
 *
 * - hosting: hosting, cloud and VPN networks by ASN, every list's joined;
 * - relay: iCloud Private Relay's addresses, as merged ranges (AddressRanges).
 */
final readonly class NetworkListUpdater
{
    /** @var array<string, string> each list as the update names it */
    public const array LABELS = [
        'hosting' => 'Hosting and VPN networks',
        'relay' => 'iCloud Private Relay addresses',
    ];

    public function __construct(
        private GeoDatabaseLocator $locator,
        private GeoHttp $http,
        private DomainLogger $logger,
    ) {}

    /** @return array<string, GeoUpdateOutcome> by the list's label */
    public function update(CarbonImmutable $now, ?CarbonImmutable $refreshRequestedAt, bool $force): array
    {
        $outcomes = [];

        foreach (array_keys(self::LABELS) as $name) {
            $outcomes[self::LABELS[$name]] = $this->updateList($name, $now, $refreshRequestedAt, $force);
        }

        return $outcomes;
    }

    /** @return array<string, mixed> */
    public function state(string $name): array
    {
        $path = $this->locator->networkState($name);
        $decoded = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function updateList(string $name, CarbonImmutable $now, ?CarbonImmutable $refreshRequestedAt, bool $force): GeoUpdateOutcome
    {
        $config = (array) config('geo.network_lists.'.$name);
        $directory = $this->locator->networksDirectory();

        if (! is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $lock = @fopen($this->locator->networkLock($name), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return GeoUpdateOutcome::Busy;
        }

        try {
            $state = $this->state($name);

            if (! $this->due($state, $now, $refreshRequestedAt, $force, is_file($this->locator->networkList($name)))) {
                return GeoUpdateOutcome::Idle;
            }

            $state['last_attempt_at'] = $now->toIso8601String();
            // Written before the fetch: a run that dies on the way — out of memory, killed — waits as a failure does, never fetches again a minute later.
            $state['next_check_at'] = GeoUpdatePlan::retryAt($now, (int) ($state['failures'] ?? 0) + 1, (array) config('geo.retry_after_minutes'))->toIso8601String();
            $this->writeState($name, $state);

            try {
                $list = $this->fetch($name, $config);
            } catch (GeoUpdateException $exception) {
                return $this->failed($name, $state, $now, $exception);
            }

            $path = $this->locator->networkList($name);
            $hash = hash('sha256', $list['contents']);
            $changed = ! is_file($path) || ($state['installed']['sha256'] ?? null) !== $hash || hash_file('sha256', $path) !== $hash;

            if ($changed) {
                $part = $this->locator->networkPart($name, 'list');

                if (@file_put_contents($part, $list['contents']) === false || ! @chmod($part, 0640) || ! @rename($part, $path)) {
                    @unlink($part);

                    return $this->failed($name, $state, $now, GeoUpdateException::because(GeoUpdateFailure::SwapFailed));
                }

                $state['installed'] = ['entries' => $list['entries'], 'sha256' => $hash, 'size' => strlen($list['contents']), 'installed_at' => $now->toIso8601String()];
                $this->logger->info('geo.network_list.updated', ['list' => $name, 'entries' => $list['entries']]);
            }

            $state['failures'] = 0;
            $state['last_error'] = null;
            $state['last_checked_at'] = $now->toIso8601String();
            $state['next_check_at'] = $now->addHours((int) ($config['check_every_hours'] ?? 24))->toIso8601String();
            $this->writeState($name, $state);

            return $changed ? GeoUpdateOutcome::Updated : GeoUpdateOutcome::Current;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Each of the list's addresses fetched and read: an ASN list joins all
     * of them, the relay ranges take the one. Short of the entries a real
     * list holds, nothing is taken.
     *
     * @param  array<string, mixed>  $config
     * @return array{contents: string, entries: int}
     */
    private function fetch(string $name, array $config): array
    {
        $read = [];

        foreach (array_values((array) ($config['urls'] ?? [])) as $index => $url) {
            $part = $this->locator->networkPart($name, 'download'.$index);

            try {
                $this->http->download((string) $url, $part, (int) ($config['max_bytes'] ?? 0), $name);
                // The relay ranges are read from the file a line at a time; an ASN list is a few tens of KB.
                $read[] = $name === 'relay' ? AddressRanges::parseFile($part) : HostingAsnList::parse((string) @file_get_contents($part));
            } catch (GeoUpdateException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw GeoUpdateException::because(GeoUpdateFailure::ListInvalid, $name);
            } finally {
                @unlink($part);
            }
        }

        if ($name === 'relay') {
            $ranges = $read[0] ?? [];
            [$contents, $entries] = [AddressRanges::encode($ranges), count($ranges)];
        } else {
            /** @var list<list<int>> $read */
            $asns = HostingAsnList::union($read);
            [$contents, $entries] = [HostingAsnList::encode($asns), count($asns)];
        }

        if ($read === [] || $entries < (int) ($config['min_entries'] ?? 1)) {
            throw GeoUpdateException::because(GeoUpdateFailure::ListInvalid, $name);
        }

        return ['contents' => $contents, 'entries' => $entries];
    }

    /**
     * Due when forced, when there is no list yet and none has just failed,
     * when the administrator asked for a refresh after the last attempt, or
     * once the next check — or the retry after a failure — has come.
     *
     * @param  array<string, mixed>  $state
     */
    private function due(array $state, CarbonImmutable $now, ?CarbonImmutable $refreshRequestedAt, bool $force, bool $exists): bool
    {
        $lastAttempt = self::time($state['last_attempt_at'] ?? null);
        $nextCheck = self::time($state['next_check_at'] ?? null);

        return $force
            || (! $exists && (int) ($state['failures'] ?? 0) === 0)
            || ($refreshRequestedAt !== null && ($lastAttempt === null || $refreshRequestedAt->greaterThan($lastAttempt)))
            || $nextCheck === null
            || $now->greaterThanOrEqualTo($nextCheck);
    }

    /** @param  array<string, mixed>  $state */
    private function failed(string $name, array $state, CarbonImmutable $now, GeoUpdateException $exception): GeoUpdateOutcome
    {
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $state['failures'] = $failures;
        $state['last_error'] = ['code' => $exception->failure->value, 'at' => $now->toIso8601String()];
        $state['next_check_at'] = GeoUpdatePlan::retryAt($now, $failures, (array) config('geo.retry_after_minutes'))->toIso8601String();
        $this->writeState($name, $state);

        $context = ['list' => $name, 'failure' => $exception->failure->value, 'failures' => $failures];
        $failures >= (int) config('geo.alert_after_failures')
            ? $this->logger->error('geo.network_list.update_failed', $context)
            : $this->logger->warning('geo.network_list.update_failed', $context);

        return GeoUpdateOutcome::Failed;
    }

    /** @param  array<string, mixed>  $state */
    private function writeState(string $name, array $state): void
    {
        $path = $this->locator->networkState($name);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        @chmod($temporary, 0640);
        rename($temporary, $path);
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
