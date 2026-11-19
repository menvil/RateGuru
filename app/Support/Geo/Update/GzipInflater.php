<?php

namespace App\Support\Geo\Update;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;

/**
 * Unpacks a gzip file into another file in 16 KiB steps, never more than a
 * given number of bytes, and only accepts a stream that reaches its proper
 * end — a download cut short or a file that is not gzip fails instead of
 * leaving half a database. Uses PHP's own zlib; there is no fallback to a
 * gzip binary.
 *
 * The step is small because inflate_add() unpacks a whole step before the
 * limit can be compared: deflate expands at most about 1032 to 1, so a step
 * of 16 KiB never holds more than about 16.5 MiB in memory, however far a
 * crafted file would unpack.
 */
final class GzipInflater
{
    private const int CHUNK = 16 * 1024;

    /** @var callable(): bool */
    private $zlibAvailable;

    /** @param  (callable(): bool)|null  $zlibAvailable  Overridable only for tests. */
    public function __construct(?callable $zlibAvailable = null)
    {
        $this->zlibAvailable = $zlibAvailable ?? static fn (): bool => function_exists('inflate_init');
    }

    public function available(): bool
    {
        return ($this->zlibAvailable)();
    }

    /** @return int The bytes written. */
    public function inflate(string $from, string $to, int $maxBytes): int
    {
        if (! $this->available()) {
            throw GeoUpdateException::because(GeoUpdateFailure::ZlibMissing);
        }

        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'wb');

        if ($in === false || $out === false) {
            throw GeoUpdateException::because(GeoUpdateFailure::UnpackFailed);
        }

        try {
            $context = inflate_init(ZLIB_ENCODING_GZIP);
            $written = 0;

            if ($context === false) {
                throw GeoUpdateException::because(GeoUpdateFailure::UnpackFailed);
            }

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    throw GeoUpdateException::because(GeoUpdateFailure::UnpackFailed);
                }

                if ($chunk === '') {
                    continue;
                }

                $data = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);

                if ($data === false) {
                    throw GeoUpdateException::because(GeoUpdateFailure::UnpackFailed);
                }

                $written += strlen($data);

                if ($written > $maxBytes) {
                    throw GeoUpdateException::because(GeoUpdateFailure::TooLarge);
                }

                fwrite($out, $data);

                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    break;
                }
            }

            if (inflate_get_status($context) !== ZLIB_STREAM_END) {
                throw GeoUpdateException::because(GeoUpdateFailure::UnpackFailed);
            }

            return $written;
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
