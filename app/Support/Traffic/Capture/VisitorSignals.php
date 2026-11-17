<?php

namespace App\Support\Traffic\Capture;

use App\Enums\TrafficDevice;
use App\Support\Geo\GeoLocation;

/**
 * What a request says about the browser behind a visit: its device, the bot
 * signs it shows, where its address is, a keyed hash of the address (never
 * the address), its system, browser and language, and the host it came from.
 */
final readonly class VisitorSignals
{
    public function __construct(
        public TrafficDevice $device,
        public int $botFlags,
        public GeoLocation $location,
        public ?string $ipHash,
        public ?string $os,
        public ?string $browser,
        public ?string $language,
        public ?string $referrer,
        public ?string $referrerHost,
        public string $path,
    ) {}
}
