<?php

namespace App\Enums;

/**
 * What a signed-in member does on the site that counts as coming back with
 * an action (docs/architecture/traffic.md, "Accounts"): a new vote on a post
 * or a new answer to a rating question, a comment or a reply, a post, a game
 * started. Never stored as a value of its own: each is a flag of the day it
 * happened on (`user_activity_days.{activity}`).
 */
enum UserActivity: string
{
    case Voted = 'voted';
    case Commented = 'commented';
    case Posted = 'posted';
    case Played = 'played';

    /** The flag of `user_activity_days` it sets. */
    public function column(): string
    {
        return $this->value;
    }
}
