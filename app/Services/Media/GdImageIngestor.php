<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\ImageDecodeException;
use App\Services\Media\Exceptions\ImageDimensionsExceededException;
use App\Services\Media\Exceptions\ImageEncodeException;
use App\Services\Media\Exceptions\ImageOrientationException;
use App\Services\Media\Exceptions\ImagePixelLimitExceededException;
use App\Services\Media\Exceptions\ImageTooLargeException;
use App\Services\Media\Exceptions\UnsupportedImageFormatException;
use Closure;
use ErrorException;
use finfo;
use GdImage;
use Throwable;

/**
 * Sole ImageIngestor implementation, pure ext-gd (no Intervention, no
 * Imagick, no libvips) — see docs/architecture/media.md for why.
 */
final class GdImageIngestor implements ImageIngestor
{
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function ingest(ImageInput $input, ImageIngestPolicy $policy): NormalizedImage
    {
        $this->assertByteLength($input->bytes, $policy->maxBytes);

        $mimeType = $this->detectFormat($input->bytes, $policy);

        $orientation = $this->resolveOrientation($input->bytes, $mimeType);

        $image = $this->decode($input->bytes);

        $image = $this->applyOrientation($image, $orientation);

        // Orientations 5-8 swap width/height; under asymmetric maxWidth/
        // maxHeight config, dimensions that passed the pre-decode check above
        // (against the as-stored, pre-orientation values) could now violate
        // it the other way around. Pixel count doesn't need re-checking — a
        // swap doesn't change width * height.
        $this->assertDimensionsWithinLimit(imagesx($image), imagesy($image), $policy);

        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $encodedBytes = $this->encode($image, $mimeType, $policy);

        return $this->buildNormalizedImage($encodedBytes, $mimeType, $policy);
    }

    private function assertByteLength(string $bytes, int $maxBytes): void
    {
        if (strlen($bytes) > $maxBytes) {
            throw ImageTooLargeException::exceedsMaxBytes(strlen($bytes), $maxBytes);
        }
    }

    /**
     * Header-only parse (not a full decode): confirms the bytes look like a
     * supported image, cross-checks the MIME two different ways, and bounds
     * dimensions/pixel count *before* the much more expensive full decode
     * below — this is the memory-safety pre-decode guard, not a separate
     * dynamic budgeting mechanism.
     */
    private function detectFormat(string $bytes, ImageIngestPolicy $policy): string
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw UnsupportedImageFormatException::unrecognizedFormat();
        }

        $mimeType = $info['mime'];

        if (! isset(self::MIME_TO_EXTENSION[$mimeType])) {
            throw UnsupportedImageFormatException::disallowedMimeType($mimeType);
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width <= 0 || $height <= 0) {
            throw UnsupportedImageFormatException::unrecognizedFormat();
        }

        $this->assertDimensionsWithinLimit($width, $height, $policy);
        $this->assertPixelCountWithinLimit($width, $height, $policy->maxPixels);

        // Client Content-Type / getClientMimeType() / client extension are
        // never consulted anywhere in this class — only the two detectors
        // below, both derived from the actual bytes.
        $finfoMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($finfoMime !== $mimeType) {
            throw UnsupportedImageFormatException::mimeTypeMismatch();
        }

        return $mimeType;
    }

    private function assertDimensionsWithinLimit(int $width, int $height, ImageIngestPolicy $policy): void
    {
        if ($width > $policy->maxWidth || $height > $policy->maxHeight) {
            throw ImageDimensionsExceededException::exceedsMaxDimensions($width, $height, $policy->maxWidth, $policy->maxHeight);
        }
    }

    private function assertPixelCountWithinLimit(int $width, int $height, int $maxPixels): void
    {
        if ($width > 0 && $height > intdiv(PHP_INT_MAX, $width)) {
            throw ImagePixelLimitExceededException::exceedsMaxPixels(PHP_INT_MAX, $maxPixels);
        }

        $totalPixels = $width * $height;

        if ($totalPixels > $maxPixels) {
            throw ImagePixelLimitExceededException::exceedsMaxPixels($totalPixels, $maxPixels);
        }
    }

    /**
     * Full decode — the header check above only parses metadata, it doesn't
     * verify the pixel data is actually intact. This is what rejects a
     * truncated or otherwise corrupt file that still has a valid header.
     */
    private function decode(string $bytes): GdImage
    {
        try {
            $image = $this->withGdErrors(static fn (): GdImage|false => imagecreatefromstring($bytes));
        } catch (Throwable $exception) {
            throw ImageDecodeException::couldNotDecode($exception);
        }

        if (! $image instanceof GdImage) {
            throw ImageDecodeException::couldNotDecode();
        }

        return $image;
    }

    /**
     * exif_read_data() only understands JPEG/TIFF — PNG's `eXIf` chunk and
     * WebP's `EXIF` RIFF chunk are both real, spec-legal places for an
     * Orientation tag to live, but this class has no parser for either. Since
     * silently ignoring one would store a physically wrong orientation with
     * no way to ever recover the original intent (re-encoding strips the
     * chunk regardless), an image carrying one is rejected outright rather
     * than treated as if it were already normal.
     */
    private function resolveOrientation(string $bytes, string $mimeType): int
    {
        return match ($mimeType) {
            'image/jpeg' => $this->readExifOrientation($bytes),
            'image/png' => $this->pngHasExifChunk($bytes)
                ? throw ImageOrientationException::unsupportedContainerMetadata($mimeType)
                : 1,
            'image/webp' => $this->webpHasExifChunk($bytes)
                ? throw ImageOrientationException::unsupportedContainerMetadata($mimeType)
                : 1,
            default => 1,
        };
    }

    /**
     * A lightweight chunk-type scan, not a full PNG parse: walks the
     * length-prefixed chunk stream looking for an `eXIf` chunk type, stopping
     * at `IEND` or once the byte stream runs out.
     */
    private function pngHasExifChunk(string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 8; // past the fixed 8-byte PNG signature

        while ($offset + 8 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            $chunkType = substr($bytes, $offset + 4, 4);

            if ($chunkType === 'eXIf') {
                return true;
            }

            if ($chunkType === 'IEND') {
                break;
            }

            $offset += 8 + $chunkLength + 4; // length + type + data + crc
        }

        return false;
    }

    /**
     * Same idea as pngHasExifChunk(), for WebP's RIFF sub-chunk stream:
     * scans for an `EXIF` FourCC.
     */
    private function webpHasExifChunk(string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 12; // past "RIFF" + 4-byte size + "WEBP"

        while ($offset + 8 <= $length) {
            $fourCc = substr($bytes, $offset, 4);
            $chunkSize = unpack('V', substr($bytes, $offset + 4, 4))[1];

            if ($fourCc === 'EXIF') {
                return true;
            }

            $offset += 8 + $chunkSize + ($chunkSize % 2); // padded to an even boundary
        }

        return false;
    }

    /**
     * Absence of EXIF data (or of an Orientation tag) is the common, valid
     * case for images with no EXIF at all — treated as orientation 1, not an
     * error. A *present* but unrecognized value is an explicit failure
     * rather than a silently-ignored one.
     */
    private function readExifOrientation(string $bytes): int
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        $exif = @exif_read_data($stream);

        fclose($stream);

        if ($exif === false || ! isset($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];

        if ($orientation < 1 || $orientation > 8) {
            throw ImageOrientationException::unrecognizedOrientation($orientation);
        }

        return $orientation;
    }

    /**
     * imagerotate() returns a *new* GdImage rather than mutating in place —
     * orientation 5/7 (flip then rotate) briefly hold two full bitmaps at
     * once, which is exactly the peak-memory scenario
     * config('uploads.images.max_pixels') is sized around. GdImage is a
     * normal, refcounted PHP object (imagedestroy() has had no effect since
     * PHP 8.0 and is deprecated as of 8.5), so the superseded intermediate
     * is simply left to be garbage-collected once nothing references it.
     */
    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        try {
            return $this->withGdErrors(function () use ($image, $orientation): GdImage {
                return match ($orientation) {
                    1 => $image,
                    2 => $this->mustFlip($image, IMG_FLIP_HORIZONTAL),
                    3 => $this->mustRotate($image, 180),
                    4 => $this->mustFlip($image, IMG_FLIP_VERTICAL),
                    5 => $this->mustRotate($this->mustFlip($image, IMG_FLIP_VERTICAL), -90),
                    6 => $this->mustRotate($image, -90),
                    7 => $this->mustRotate($this->mustFlip($image, IMG_FLIP_HORIZONTAL), -90),
                    8 => $this->mustRotate($image, 90),
                    default => throw ImageOrientationException::unrecognizedOrientation($orientation),
                };
            });
        } catch (ImageOrientationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ImageOrientationException::couldNotApplyOrientation($exception);
        }
    }

    private function mustFlip(GdImage $image, int $mode): GdImage
    {
        // imageflip() only fails for a mode outside the three IMG_FLIP_*
        // constants, none of which this class ever passes — PHPStan's stub
        // for it returns a literal true, confirming there's no false case
        // reachable here to check.
        imageflip($image, $mode);

        return $image;
    }

    private function mustRotate(GdImage $image, float $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, 0);

        if (! $rotated instanceof GdImage) {
            throw ImageOrientationException::couldNotApplyOrientation();
        }

        return $rotated;
    }

    private function encode(GdImage $image, string $mimeType, ImageIngestPolicy $policy): string
    {
        ob_start();

        try {
            $success = $this->withGdErrors(fn (): bool => match ($mimeType) {
                'image/jpeg' => $this->encodeJpeg($image, $policy->jpegQuality),
                'image/png' => imagepng($image, null, $policy->pngCompression),
                'image/webp' => imagewebp($image, null, $policy->webpQuality),
                default => false,
            });
        } catch (Throwable $exception) {
            ob_end_clean();

            throw ImageEncodeException::couldNotEncode($mimeType, $exception);
        }

        $contents = ob_get_clean();

        if (! $success || ! is_string($contents) || $contents === '') {
            throw ImageEncodeException::couldNotEncode($mimeType);
        }

        return $contents;
    }

    private function encodeJpeg(GdImage $image, int $quality): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, null, $quality);
    }

    /**
     * Re-derives metadata from the bytes actually produced, rather than
     * carrying forward what was known before encoding — the saved
     * media_assets row must describe exactly what's on disk.
     */
    private function buildNormalizedImage(string $encodedBytes, string $expectedMime, ImageIngestPolicy $policy): NormalizedImage
    {
        $outputInfo = @getimagesizefromstring($encodedBytes);

        if ($outputInfo === false || $outputInfo['mime'] !== $expectedMime) {
            throw ImageEncodeException::unexpectedOutputFormat(
                $expectedMime,
                $outputInfo === false ? 'unknown' : $outputInfo['mime'],
            );
        }

        if (strlen($encodedBytes) > $policy->maxBytes) {
            throw ImageEncodeException::exceedsMaxBytesAfterEncoding(strlen($encodedBytes), $policy->maxBytes);
        }

        return new NormalizedImage(
            bytes: $encodedBytes,
            mimeType: $expectedMime,
            extension: self::MIME_TO_EXTENSION[$expectedMime],
            byteSize: strlen($encodedBytes),
            width: (int) $outputInfo[0],
            height: (int) $outputInfo[1],
        );
    }

    /**
     * Scoped, not global: converts a GD warning into a catchable exception
     * only for the duration of $callback, so a bad/corrupt image can never
     * be trusted just because its call site forgot to check a return value.
     */
    private function withGdErrors(Closure $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
