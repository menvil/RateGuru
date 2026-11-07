<?php

namespace App\Exceptions\Follows;

use RuntimeException;

/**
 * Some followers were not told about a new post. Thrown after everyone else
 * was, so the queue tries the job again: the followers already notified are
 * skipped on the retry, and only the ones missed are tried once more.
 */
final class FollowerNotificationsNotDeliveredException extends RuntimeException
{
    public static function forPost(int $postId, int $failed): self
    {
        return new self("{$failed} follower notification(s) for post {$postId} failed and will be retried.");
    }
}
