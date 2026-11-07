<?php

namespace App\Exceptions\Follows;

use RuntimeException;

class CannotFollowAuthorException extends RuntimeException
{
    private bool $aboutFollower = false;

    public static function authorNotViewable(): self
    {
        return new self('This author cannot be followed.');
    }

    public static function followerNotAllowed(): self
    {
        $exception = new self('Your account is not allowed to follow authors.');
        $exception->aboutFollower = true;

        return $exception;
    }

    /** Whether the refusal is about the follower's own account rather than the author. */
    public function isAboutFollower(): bool
    {
        return $this->aboutFollower;
    }
}
