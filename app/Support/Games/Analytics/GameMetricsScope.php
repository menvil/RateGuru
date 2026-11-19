<?php

namespace App\Support\Games\Analytics;

use App\Enums\GameAudience;
use App\Support\Traffic\Reports\VisitFilter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What a game's figures are read over: one game, one audience, a window from
 * its first moment up to but not including its last, either every version
 * or one, and the device and country of the recorded visits — every play
 * when the filter narrows nothing, else only what came on a visit it admits.
 */
final readonly class GameMetricsScope
{
    public VisitFilter $visits;

    public function __construct(
        public int $gameId,
        public GameAudience $audience,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $versionId = null,
        ?VisitFilter $visits = null,
    ) {
        if (! $from->lessThan($to)) {
            throw new InvalidArgumentException('A metrics window has to end after it starts.');
        }

        $this->visits = $visits ?? new VisitFilter;
    }

    /** The same scope for the other audience, or another window. */
    public function with(?GameAudience $audience = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): self
    {
        return new self($this->gameId, $audience ?? $this->audience, $from ?? $this->from, $to ?? $this->to, $this->versionId, $this->visits);
    }
}
