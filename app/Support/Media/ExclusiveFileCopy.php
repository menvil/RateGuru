<?php

namespace App\Support\Media;

/**
 * Copies one local file to a path that must not exist yet: the destination is
 * created afresh (O_CREAT|O_EXCL), so an entry someone left at that name — a
 * link to a file the worker may write — fails the copy instead of being
 * written through. Local paths only; nothing here opens an address.
 */
final class ExclusiveFileCopy
{
    public static function copy(string $from, string $to): bool
    {
        if (str_contains($from, '://') || str_contains($to, '://')) {
            return false;
        }

        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'xb');
        $copied = $in !== false && $out !== false && stream_copy_to_stream($in, $out) !== false;

        if ($in !== false) {
            fclose($in);
        }

        if ($out !== false) {
            fclose($out);
        }

        return $copied;
    }
}
