<?php

namespace App\Support\Geo;

use App\Enums\GeoEdition;
use App\Support\Import\Net\PublicIpClassifier;
use App\Support\Observability\DomainLogger;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Reads the local MaxMind DB file. One reader per request or job (the class
 * is bound scoped), opened at the first lookup that needs it; the file is read
 * with seeks, never loaded whole. Anything going wrong — no file, a file that
 * is not a database, a corrupt record — answers unknown and switches the
 * reader off for the rest of the request, so a visitor's page never fails over
 * where they are.
 */
final class MmdbGeoLookup implements GeoLookup
{
    private ?Reader $reader = null;

    private bool $unavailable = false;

    public function __construct(
        private readonly GeoDatabaseLocator $locator,
        private readonly GeoEdition $preferred,
        private readonly PublicIpClassifier $publicIps,
        private readonly DomainLogger $logger,
    ) {}

    public function lookup(?string $ip): GeoLocation
    {
        $ip = $ip === null ? '' : trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || ! $this->publicIps->isPublic($ip)) {
            return GeoLocation::unknown();
        }

        $reader = $this->reader();

        if ($reader === null) {
            return GeoLocation::unknown();
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return GeoLocation::unknown();
        }

        return GeoRecordMapper::map(is_array($record) ? $record : null);
    }

    private function reader(): ?Reader
    {
        if ($this->reader !== null || $this->unavailable) {
            return $this->reader;
        }

        $edition = $this->locator->serving($this->preferred);

        if ($edition === null) {
            $this->unavailable = true;

            return null;
        }

        try {
            return $this->reader = new Reader($this->locator->current($edition));
        } catch (Throwable $exception) {
            $this->fail($exception);

            return null;
        }
    }

    private function fail(Throwable $exception): void
    {
        $this->unavailable = true;
        $this->reader = null;
        $this->logger->warning('geo.lookup.failed', ['exception' => $exception::class]);
    }
}
