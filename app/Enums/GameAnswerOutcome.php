<?php

namespace App\Enums;

/** What a pick earned: a point, none, or nothing to earn (an unscored round). */
enum GameAnswerOutcome: string
{
    case Correct = 'correct';
    case Incorrect = 'incorrect';
    case Neutral = 'neutral';
}
