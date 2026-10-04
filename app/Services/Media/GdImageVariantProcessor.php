<?php

namespace App\Services\Media;

use App\Enums\MediaResizeMode;
use App\Services\Media\Exceptions\MediaVariantGenerationException;
use Closure;
use ErrorException;
use finfo;
use GdImage;
use Throwable;

/**
 * Sole ImageVariantProcessor implementation, pure ext-gd — mirrors
 * GdImageIngestor's scoped-error-handling and alpha-preservation idioms
 * (both private there, so re-implemented here rather than shared). Never
 * touches storage or the database.
 */
final class GdImageVariantProcessor implements ImageVariantProcessor
{
    private const int PNG_COMPRESSION = 6;

    private const array MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function generate(string $masterBytes, string $mimeType, MediaVariantSpecification $specification): GeneratedMediaVariant
    {
        $source = $this->decode($masterBytes);

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        $outputMimeType = $specification->outputMimeType ?? $mimeType;

        [$targetWidth, $targetHeight, $srcX, $srcY, $copyWidth, $copyHeight] = match ($specification->mode) {
            MediaResizeMode::Contain => $this->planContain($srcWidth, $srcHeight, $specification->maxWidth, $specification->maxHeight),
            MediaResizeMode::CoverSquare => $this->planCoverSquare($srcWidth, $srcHeight, $specification->maxWidth),
            MediaResizeMode::Cover => $this->planCover($srcWidth, $srcHeight, $specification->maxWidth, $specification->maxHeight),
        };

        $destination = $this->resample($source, $outputMimeType, $targetWidth, $targetHeight, $srcX, $srcY, $copyWidth, $copyHeight);

        $encodedBytes = $this->encode($destination, $outputMimeType, $specification->quality);

        return $this->buildGeneratedVariant($encodedBytes, $outputMimeType, $targetWidth, $targetHeight);
    }

    /**
     * Scales down to fit within maxWidth x maxHeight preserving aspect ratio,
     * never upscaling (the min(..., 1.0) clamp) — the whole source is always
     * copied, never cropped.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private function planContain(int $srcWidth, int $srcHeight, int $maxWidth, int $maxHeight): array
    {
        $scale = min($maxWidth / $srcWidth, $maxHeight / $srcHeight, 1.0);

        $targetWidth = max(1, (int) round($srcWidth * $scale));
        $targetHeight = max(1, (int) round($srcHeight * $scale));

        return [$targetWidth, $targetHeight, 0, 0, $srcWidth, $srcHeight];
    }

    /**
     * Crops the largest centered square out of the source, then resizes it
     * to targetSize x targetSize — crop and resize happen in the same
     * imagecopyresampled() call.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private function planCoverSquare(int $srcWidth, int $srcHeight, int $targetSize): array
    {
        return $this->planCover($srcWidth, $srcHeight, $targetSize, $targetSize);
    }

    /**
     * Crops the largest centered targetWidth:targetHeight-ratio rectangle out
     * of the source, then resizes it to targetWidth x targetHeight — crop and
     * resize happen in the same imagecopyresampled() call. Generalizes
     * planCoverSquare() to an arbitrary (non-square) target aspect ratio;
     * planCoverSquare(w, h, size) and planCover(w, h, size, size) are
     * algebraically identical.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private function planCover(int $srcWidth, int $srcHeight, int $targetWidth, int $targetHeight): array
    {
        $targetRatio = $targetWidth / $targetHeight;
        $srcRatio = $srcWidth / $srcHeight;

        if ($srcRatio > $targetRatio) {
            $cropHeight = $srcHeight;
            $cropWidth = (int) round($srcHeight * $targetRatio);
        } else {
            $cropWidth = $srcWidth;
            $cropHeight = (int) round($srcWidth / $targetRatio);
        }

        $cropX = intdiv($srcWidth - $cropWidth, 2);
        $cropY = intdiv($srcHeight - $cropHeight, 2);

        return [$targetWidth, $targetHeight, $cropX, $cropY, $cropWidth, $cropHeight];
    }

    private function decode(string $bytes): GdImage
    {
        try {
            $image = $this->withNativeErrorsAsExceptions(static fn (): GdImage|false => imagecreatefromstring($bytes));
        } catch (Throwable $exception) {
            throw MediaVariantGenerationException::couldNotDecodeMaster($exception);
        }

        if (! $image instanceof GdImage) {
            throw MediaVariantGenerationException::couldNotDecodeMaster();
        }

        return $image;
    }

    private function resample(GdImage $source, string $outputMimeType, int $targetWidth, int $targetHeight, int $srcX, int $srcY, int $copyWidth, int $copyHeight): GdImage
    {
        $destination = $this->createCanvas($targetWidth, $targetHeight);

        if ($outputMimeType === 'image/png' || $outputMimeType === 'image/webp') {
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
            imagefill($destination, 0, 0, $transparent);
        } elseif ($outputMimeType === 'image/jpeg') {
            // JPEG has no alpha channel; GD's default canvas fill is black
            // with alpha blending on, which would let a transparent PNG/WebP
            // source bleed through to black. Flatten onto white first — a
            // no-op for already-opaque JPEG sources, which fully overwrite
            // every destination pixel regardless of fill color.
            $white = imagecolorallocate($destination, 255, 255, 255);
            imagefill($destination, 0, 0, $white);
        }

        try {
            $success = $this->withNativeErrorsAsExceptions(static fn (): bool => imagecopyresampled(
                $destination, $source, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $copyWidth, $copyHeight,
            ));
        } catch (Throwable $exception) {
            throw MediaVariantGenerationException::couldNotResample($exception);
        }

        if (! $success) {
            throw MediaVariantGenerationException::couldNotResample();
        }

        return $destination;
    }

    /**
     * imagecreatetruecolor() can return false (invalid dimensions, or
     * allocation failure under memory pressure) rather than throwing — left
     * unchecked, that false would reach imagealphablending() et al. as a
     * GdImage-typed argument and surface as an uncaught TypeError instead of
     * the exception contract generate() promises.
     */
    private function createCanvas(int $width, int $height): GdImage
    {
        try {
            $canvas = $this->withNativeErrorsAsExceptions(static fn (): GdImage|false => imagecreatetruecolor($width, $height));
        } catch (Throwable $exception) {
            throw MediaVariantGenerationException::couldNotResample($exception);
        }

        if (! $canvas instanceof GdImage) {
            throw MediaVariantGenerationException::couldNotResample();
        }

        return $canvas;
    }

    private function encode(GdImage $image, string $mimeType, int $quality): string
    {
        ob_start();

        try {
            $success = $this->withNativeErrorsAsExceptions(fn (): bool => match ($mimeType) {
                'image/jpeg' => $this->encodeJpeg($image, $quality),
                'image/png' => imagepng($image, null, self::PNG_COMPRESSION),
                'image/webp' => imagewebp($image, null, $quality),
                default => false,
            });
        } catch (Throwable $exception) {
            ob_end_clean();

            throw MediaVariantGenerationException::couldNotEncode($mimeType, $exception);
        }

        $contents = ob_get_clean();

        if (! $success || ! is_string($contents) || $contents === '') {
            throw MediaVariantGenerationException::couldNotEncode($mimeType);
        }

        return $contents;
    }

    private function encodeJpeg(GdImage $image, int $quality): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, null, $quality);
    }

    /**
     * Re-derives everything from the bytes actually produced, cross-checking
     * mime (two ways) and dimensions against what was intended — never
     * trusts the in-memory GdImage's own reported size.
     */
    private function buildGeneratedVariant(string $encodedBytes, string $expectedMime, int $expectedWidth, int $expectedHeight): GeneratedMediaVariant
    {
        $outputInfo = $this->readImageSize($encodedBytes);

        if ($outputInfo === false || $outputInfo['mime'] !== $expectedMime) {
            throw MediaVariantGenerationException::unexpectedOutputFormat(
                $expectedMime,
                $outputInfo === false ? 'unknown' : $outputInfo['mime'],
            );
        }

        $actualWidth = (int) $outputInfo[0];
        $actualHeight = (int) $outputInfo[1];

        if ($actualWidth !== $expectedWidth || $actualHeight !== $expectedHeight) {
            throw MediaVariantGenerationException::unexpectedOutputDimensions($expectedWidth, $expectedHeight, $actualWidth, $actualHeight);
        }

        $finfoMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($encodedBytes);

        if ($finfoMime !== $expectedMime) {
            throw MediaVariantGenerationException::unexpectedOutputFormat($expectedMime, $finfoMime);
        }

        return new GeneratedMediaVariant(
            bytes: $encodedBytes,
            mimeType: $expectedMime,
            extension: self::MIME_TO_EXTENSION[$expectedMime],
            byteSize: strlen($encodedBytes),
            width: $actualWidth,
            height: $actualHeight,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string, mime: string}|false
     */
    private function readImageSize(string $bytes): array|false
    {
        try {
            return $this->withNativeErrorsAsExceptions(static fn (): array|false => getimagesizefromstring($bytes));
        } catch (Throwable) {
            return false;
        }
    }

    private function withNativeErrorsAsExceptions(Closure $callback): mixed
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
