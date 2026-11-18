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

    /** The count of the browser's visit it adds one to (`traffic_visits`), if any: a game started counts on the visit already. */
    public function visitCounter(): ?string
    {
        return match ($this) {
            self::Voted => 'votes',
            self::Commented => 'comments',
            self::Posted => 'posts_created',
            self::Played => null,
        };
    }

    /** The flag it sets on the visit's row of the post it was done on (`traffic_visit_posts`), if any. */
    public function postFlag(): ?string
    {
        return match ($this) {
            self::Voted => 'voted',
            self::Commented => 'commented',
            self::Posted, self::Played => null,
        };
    }
}
