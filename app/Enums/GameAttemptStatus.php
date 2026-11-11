<?php

namespace App\Enums;

/**
 * An attempt is Started when the visitor presses Start and Completed with its
 * last answer. One left Started is abandoned once it has been idle long
 * enough; that is read, never stored.
 */
enum GameAttemptStatus: string
{
    case Started = 'started';
    case Completed = 'completed';
}
