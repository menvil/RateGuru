<?php

namespace App\Support\Games\Flow;

use App\Enums\GameAudience;

/** What a flow needs to know about a visitor to decide which steps they see. */
final readonly class FlowVisitor
{
    public function __construct(
        public GameAudience $audience,
        /** Signed in, or left an email in this game before. */
        public bool $emailKnown = false,
        /** The Next Game step's game is live and gives this visitor's audience a version. */
        public bool $nextGameAvailable = true,
    ) {}
}
