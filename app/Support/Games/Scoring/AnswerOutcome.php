<?php

namespace App\Support\Games\Scoring;

use App\Enums\GameAnswerOutcome;

/** What one pick earned, and which option earned the point. */
final readonly class AnswerOutcome
{
    public function __construct(
        public GameAnswerOutcome $outcome,
        public int $points,
        public ?int $answer,
    ) {}
}
