<?php

namespace App\Support\Geo;

use App\Enums\NetworkKind;

/**
 * The network an IP address belongs to, as far as the local networks'
 * database and lists know: its autonomous system number and the
 * organisation that holds it, and what kind of network that is.
 */
final readonly class NetworkInfo
{
    public function __construct(
        public ?int $asn = null,
        public ?string $organization = null,
        public NetworkKind $kind = NetworkKind::Unknown,
    ) {}

    public static function unknown(): self
    {
        return new self;
    }

    /** A hosting, cloud or VPN provider's network: no person's own connection. */
    public function isHosting(): bool
    {
        return $this->kind === NetworkKind::Hosting;
    }
}
