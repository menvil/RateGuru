<?php

namespace App\Services\Traffic\Connectors;

/**
 * A network's spend for one of its days, by campaign, site and zone where it
 * says — and whether the figures were complete: a day is closed only once
 * its figures came whole.
 */
final readonly class SpendDayReport
{
    /** @param  list<array{campaign: string, site: string, zone: string, amount: string, clicks: int|null, impressions: int|null}>  $rows */
    public function __construct(public array $rows, public bool $complete) {}
}
