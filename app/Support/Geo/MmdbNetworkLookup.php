<?php

namespace App\Support\Geo;

use App\Enums\GeoEdition;
use App\Enums\NetworkKind;
use App\Support\Geo\Networks\AddressRanges;
use App\Support\Geo\Networks\HostingAsnList;
use App\Support\Import\Net\PublicIpClassifier;
use App\Support\Observability\DomainLogger;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Reads the networks' database (DB-IP ASN Lite) and the network lists kept
 * beside it. One per request or job (bound scoped), each file opened at the
 * first lookup that needs it. An address in iCloud Private Relay's ranges is
 * a relay — a person behind Apple's relay, whatever network carries it; else
 * one whose ASN the hosting list names is hosting; else any other network.
 * With no database the kind is unknown, unless the relay ranges say relay.
 * Anything going wrong switches that file off for the rest of the request.
 */
final class MmdbNetworkLookup implements NetworkLookup
{
    private ?Reader $reader = null;

    private bool $readerUnavailable = false;

    /** @var array<int, true>|null */
    private ?array $hosting = null;

    public function __construct(
        private readonly GeoDatabaseLocator $locator,
        private readonly PublicIpClassifier $publicIps,
        private readonly DomainLogger $logger,
    ) {}

    public function lookup(?string $ip): NetworkInfo
    {
        $ip = $ip === null ? '' : trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || ! $this->publicIps->isPublic($ip)) {
            return NetworkInfo::unknown();
        }

        $record = $this->record($ip);
        $asn = NetworkRecordMapper::asn($record);
        $organization = NetworkRecordMapper::organization($record);

        $kind = match (true) {
            AddressRanges::contains($this->locator->networkList('relay'), $ip) => NetworkKind::Relay,
            $asn === null => NetworkKind::Unknown,
            isset($this->hosting()[$asn]) => NetworkKind::Hosting,
            default => NetworkKind::Other,
        };

        return new NetworkInfo($asn, $organization, $kind);
    }

    /** @return array<mixed>|null */
    private function record(string $ip): ?array
    {
        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return null;
        }

        return is_array($record) ? $record : null;
    }

    private function reader(): ?Reader
    {
        if ($this->reader !== null || $this->readerUnavailable) {
            return $this->reader;
        }

        $path = $this->locator->current(GeoEdition::DbipAsnLite);

        if (! is_file($path)) {
            $this->readerUnavailable = true;

            return null;
        }

        try {
            return $this->reader = new Reader($path);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return null;
        }
    }

    /** @return array<int, true> */
    private function hosting(): array
    {
        if ($this->hosting === null) {
            $path = $this->locator->networkList('hosting');
            $this->hosting = is_file($path) ? HostingAsnList::decode((string) @file_get_contents($path)) : [];
        }

        return $this->hosting;
    }

    private function fail(Throwable $exception): void
    {
        $this->readerUnavailable = true;
        $this->reader = null;
        $this->logger->warning('geo.network_lookup.failed', ['exception' => $exception::class]);
    }
}
