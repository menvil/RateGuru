<?php

namespace App\Services\Traffic\Connectors;

/** A campaign as its network describes it: what RateGuru shows, never changes. */
final readonly class NetworkCampaign
{
    /**
     * @param  list<string>|null  $countries
     * @param  list<string>|null  $devices
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public ?array $countries = null,
        public ?array $devices = null,
        public ?string $format = null,
        public ?string $bidModel = null,
        public ?string $bidMobile = null,
        public ?string $bidDesktop = null,
        public ?string $dailyBudget = null,
    ) {}
}
