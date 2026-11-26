<?php

namespace App\Support\Media;

/**
 * Where the media pipeline's long jobs go. A queue that redelivers a job
 * still reserved after its retry window — Redis on the hosts, or the
 * database — sends them to its `-media` counterpart: a queue of their own
 * (`<queue>-media`) and a window longer than a transcode may run. Anything
 * else (sync in development and tests) runs them as it runs everything else.
 */
final class MediaQueue
{
    /** Each redelivering connection, and the media connection beside it. */
    public const array CONNECTIONS = [
        'redis' => 'redis-media',
        'database' => 'database-media',
    ];

    public static function connection(): string
    {
        $default = (string) config('queue.default');

        return self::CONNECTIONS[$default] ?? $default;
    }
}
