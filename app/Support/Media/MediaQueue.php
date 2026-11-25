<?php

namespace App\Support\Media;

/**
 * Where the media pipeline's long jobs go. On Redis — the hosts — that is the
 * `redis-media` connection: its own queue (`<target queue>-media`) and a retry
 * window longer than a transcode may run. Anywhere else (sync in development
 * and tests, database) they stay on the default connection, which runs them
 * as it runs everything else.
 */
final class MediaQueue
{
    public const string CONNECTION = 'redis-media';

    public static function connection(): string
    {
        $default = (string) config('queue.default');

        return $default === 'redis' ? self::CONNECTION : $default;
    }
}
