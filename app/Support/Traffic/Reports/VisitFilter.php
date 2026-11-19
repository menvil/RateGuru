<?php

namespace App\Support\Traffic\Reports;

use App\Enums\TrafficDevice;

/**
 * Which recorded visits a figure counts by where they came from: of one
 * device or of either, and of any country, of the countries named — one
 * country, or a group of the price set — or, for the price set's last group,
 * of every country the other groups do not name, a visit whose country is
 * not known among them. TrafficVisit::scopeMatching() reads it.
 */
final readonly class VisitFilter
{
    /**
     * @param  list<string>|null  $countries  the countries counted, or — with $otherCountries — those left out; null for any
     */
    public function __construct(
        public ?TrafficDevice $device = null,
        public ?array $countries = null,
        public bool $otherCountries = false,
    ) {}

    /** Whether a device or countries are chosen: then only what came on a recorded visit counts. */
    public function narrows(): bool
    {
        return $this->device !== null || $this->countries !== null;
    }
}
