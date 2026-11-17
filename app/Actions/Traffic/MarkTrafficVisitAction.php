<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficEvent;
use App\Models\TrafficVisit;
use Carbon\CarbonImmutable;

/**
 * Sets a visit's mark for an event — when it happened — the first time only,
 * with whatever else the moment records beside it. Whether this call was the
 * first is the answer: what follows a moment, a conversion or a postback,
 * follows it once.
 */
final class MarkTrafficVisitAction
{
    /** @param  array<string, mixed>  $also */
    public function handle(int $visitId, TrafficEvent $event, CarbonImmutable $at, array $also = []): bool
    {
        return TrafficVisit::query()
            ->whereKey($visitId)
            ->whereNull($event->column())
            ->update([$event->column() => $at, ...$also]) === 1;
    }
}
