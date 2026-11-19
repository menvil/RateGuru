<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Settles a paid visit that came to rest (docs/architecture/traffic.md,
 * "Settling"): in a transaction of its own, the moment it did, telling its
 * network once what the best step it reached was worth. A visit settles
 * once; its confirmed account settles it as soon as it comes.
 */
final class SettleTrafficVisitAction
{
    public function __construct(private readonly MarkTrafficVisitAction $mark) {}

    /** Whether this call settled it. */
    public function handle(int $visitId, CarbonImmutable $at): bool
    {
        return DB::transaction(fn (): bool => $this->mark->handle($visitId, TrafficEvent::Settled, $at));
    }
}
