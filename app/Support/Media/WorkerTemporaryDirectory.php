<?php

namespace App\Support\Media;

use RuntimeException;

/**
 * A directory of this worker's own under the system temp directory, for the
 * media it downloads or takes apart. One per name and per user: the targets
 * sharing a host run their workers as different users, and a directory one
 * of them made is closed to the other. Made 0700; refused when it is a link
 * or another user's — what someone else left at that name is never cleared
 * or written into. What a run killed before its clean-up left there is
 * cleared once older than the given age.
 */
final class WorkerTemporaryDirectory
{
    public static function path(string $name): string
    {
        return sys_get_temp_dir().'/rateguru-'.$name.'-'.posix_geteuid();
    }

    /**
     * @throws RuntimeException when it cannot be made, or is not this worker's own
     */
    public static function prepare(string $name, int $clearOlderThanSeconds): string
    {
        $directory = self::path($name);

        if (! file_exists($directory) && ! @mkdir($directory, 0o700) && ! is_dir($directory)) {
            throw new RuntimeException("The {$name} work directory could not be made.");
        }

        if (is_link($directory) || ! is_dir($directory) || fileowner($directory) !== posix_geteuid()) {
            throw new RuntimeException("The {$name} work directory is not this worker's own.");
        }

        foreach (glob($directory.'/*') ?: [] as $stale) {
            if (@filemtime($stale) < time() - $clearOlderThanSeconds) {
                exec('rm -rf '.escapeshellarg($stale));
            }
        }

        return $directory;
    }
}
