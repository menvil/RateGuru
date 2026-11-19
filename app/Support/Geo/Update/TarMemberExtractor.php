<?php

namespace App\Support\Geo\Update;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;

/**
 * Copies one regular file out of an unpacked tar archive — the database file
 * of an npm package, which npm packs as package/<file>. Reads the 512-byte
 * headers in turn, skipping every other entry (pax headers included), and
 * refuses a member larger than the limit or an archive without it.
 */
final class TarMemberExtractor
{
    private const int BLOCK = 512;

    /** @return int The member's size. */
    public function extract(string $archive, string $member, string $to, int $maxBytes): int
    {
        $in = @fopen($archive, 'rb');

        if ($in === false) {
            throw GeoUpdateException::because(GeoUpdateFailure::ArchiveInvalid);
        }

        try {
            while (true) {
                $header = fread($in, self::BLOCK);

                if ($header === false || strlen($header) < self::BLOCK || trim($header, "\0") === '') {
                    break;
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $path = $prefix === '' ? $name : $prefix.'/'.$name;
                $sizeField = trim(substr($header, 124, 12), "\0 ");
                $size = $sizeField === '' ? 0 : (int) octdec($sizeField);
                $type = $header[156];
                $padded = (int) (ceil($size / self::BLOCK) * self::BLOCK);

                if ($path === $member && ($type === '0' || $type === "\0")) {
                    if ($size > $maxBytes) {
                        throw GeoUpdateException::because(GeoUpdateFailure::TooLarge);
                    }

                    $this->copy($in, $to, $size);

                    return $size;
                }

                if ($padded > 0 && fseek($in, $padded, SEEK_CUR) !== 0) {
                    break;
                }
            }
        } finally {
            fclose($in);
        }

        throw GeoUpdateException::because(GeoUpdateFailure::ArchiveInvalid);
    }

    /** @param  resource  $in */
    private function copy($in, string $to, int $size): void
    {
        $out = @fopen($to, 'wb');

        if ($out === false) {
            throw GeoUpdateException::because(GeoUpdateFailure::ArchiveInvalid);
        }

        try {
            $left = $size;

            while ($left > 0) {
                $chunk = fread($in, min($left, 1024 * 1024));

                if ($chunk === false || $chunk === '') {
                    throw GeoUpdateException::because(GeoUpdateFailure::ArchiveInvalid);
                }

                fwrite($out, $chunk);
                $left -= strlen($chunk);
            }
        } finally {
            fclose($out);
        }
    }
}
