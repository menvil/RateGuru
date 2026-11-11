<?php

namespace App\Support\Games\Analytics;

use App\Enums\GameAudience;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What a game's figures are read over: one game, one audience, a window from
 * its first moment up to but not including its last, and either every version
 * or one.
 */
final readonly class GameMetricsScope
{
    public function __construct(
        public int $gameId,
        public GameAudience $audience,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $versionId = null,
    ) {
        if (! $from->lessThan($to)) {
            throw new InvalidArgumentException('A metrics window has to end after it starts.');
        }
    }
}
