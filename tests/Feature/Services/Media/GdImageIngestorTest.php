<?php

use App\Enums\ImageInputSource;
use App\Services\Media\Exceptions\ImageDecodeException;
use App\Services\Media\Exceptions\ImageDimensionsExceededException;
use App\Services\Media\Exceptions\ImageEncodeException;
use App\Services\Media\Exceptions\ImageOrientationException;
use App\Services\Media\Exceptions\ImagePixelLimitExceededException;
use App\Services\Media\Exceptions\ImageTooLargeException;
use App\Services\Media\Exceptions\UnsupportedImageFormatException;
use App\Services\Media\GdImageIngestor;
use App\Services\Media\ImageIngestPolicy;
use App\Services\Media\ImageInput;

// --- fixture helpers -------------------------------------------------------

function ingestPolicy(array $overrides = []): ImageIngestPolicy
{
    return new ImageIngestPolicy(
        maxBytes: $overrides['maxBytes'] ?? 5 * 1024 * 1024,
        maxWidth: $overrides['maxWidth'] ?? 6000,
        maxHeight: $overrides['maxHeight'] ?? 6000,
        maxPixels: $overrides['maxPixels'] ?? 16_000_000,
        jpegQuality: $overrides['jpegQuality'] ?? 90,
        pngCompression: $overrides['pngCompression'] ?? 6,
        webpQuality: $overrides['webpQuality'] ?? 90,
    );
}

function ingestInput(string $bytes, ?string $originalFilename = 'photo.jpg', ImageInputSource $source = ImageInputSource::Upload): ImageInput
{
    return new ImageInput($bytes, $originalFilename, $source);
}

// makeMarkerImage()/markerCornerColor()/markerCorners()/jpegMarkerBytes()/
// markerBytesWithAlpha() live in tests/Pest.php — shared across Media test
// files, which requires them to be in Pest's always-loaded bootstrap rather
// than a bare function in any one test file (see the comment there).

function gifBytes(int $width = 20, int $height = 10): string
{
    $im = imagecreate($width, $height);
    imagecolorallocate($im, 255, 0, 0);
    ob_start();
    imagegif($im);

    return ob_get_clean();
}

function bmpBytes(int $width = 20, int $height = 10): string
{
    $im = imagecreatetruecolor($width, $height);
    ob_start();
    imagebmp($im);

    return ob_get_clean();
}

function svgBytes(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10"><rect width="20" height="10" fill="red"/></svg>';
}

/** Minimal, hand-built single-tag EXIF (TIFF/IFD0) APP1 segment. */
function exifOrientationSegment(int $orientation): string
{
    $tiffHeader = "II\x2A\x00".pack('V', 8);
    $entry = pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\x00\x00";
    $ifd0 = pack('v', 1).$entry.pack('V', 0);
    $app1Payload = "Exif\x00\x00".$tiffHeader.$ifd0;

    return "\xFF\xE1".pack('n', strlen($app1Payload) + 2).$app1Payload;
}

function jpegWithExifOrientation(int $orientation, int $width = 20, int $height = 10): string
{
    $plain = jpegMarkerBytes($width, $height);

    return substr($plain, 0, 2).exifOrientationSegment($orientation).substr($plain, 2);
}

/** JPEG comment (COM, 0xFFFE) marker segment. */
function jpegCommentSegment(string $comment): string
{
    $payload = $comment;

    return "\xFF\xFE".pack('n', strlen($payload) + 2).$payload;
}

/** Minimal single-tag (Orientation) TIFF/IFD0 body, shared by the PNG/WebP chunk builders below. */
function exifOrientationData(int $orientation = 6): string
{
    return "II\x2A\x00".pack('V', 8).pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\x00\x00".pack('V', 0);
}

/** Splices a minimal `eXIf` chunk (carrying an Orientation tag) into a PNG. */
function pngWithExifChunk(int $width = 20, int $height = 10): string
{
    $png = markerBytesWithAlpha('png', $width, $height);

    $exifData = exifOrientationData();
    $chunkType = 'eXIf';
    $chunk = pack('N', strlen($exifData)).$chunkType.$exifData.pack('N', crc32($chunkType.$exifData));

    // Splice right after the signature (8 bytes) + IHDR chunk (length+type+13-byte data+crc = 25 bytes).
    $ihdrEnd = 8 + 25;

    return substr($png, 0, $ihdrEnd).$chunk.substr($png, $ihdrEnd);
}

/** Splices a minimal `EXIF` RIFF chunk (carrying an Orientation tag) into a WebP. */
function webpWithExifChunk(int $width = 20, int $height = 10): string
{
    $webp = markerBytesWithAlpha('webp', $width, $height);

    $exifData = exifOrientationData();
    $chunk = 'EXIF'.pack('V', strlen($exifData)).$exifData.(strlen($exifData) % 2 === 1 ? "\x00" : '');

    $riffSize = unpack('V', substr($webp, 4, 4))[1];
    $webp = substr($webp, 0, 4).pack('V', $riffSize + strlen($chunk)).substr($webp, 8);

    return $webp.$chunk;
}

// --- format detection --------------------------------------------------

it('accepts a valid JPEG', function () {
    $result = (new GdImageIngestor)->ingest(ingestInput(jpegMarkerBytes()), ingestPolicy());

    expect($result->mimeType)->toBe('image/jpeg')
        ->and($result->extension)->toBe('jpg');
});

it('accepts a valid PNG', function () {
    $result = (new GdImageIngestor)->ingest(ingestInput(markerBytesWithAlpha('png')), ingestPolicy());

    expect($result->mimeType)->toBe('image/png')
        ->and($result->extension)->toBe('png');
});

it('accepts a valid WebP', function () {
    $result = (new GdImageIngestor)->ingest(ingestInput(markerBytesWithAlpha('webp')), ingestPolicy());

    expect($result->mimeType)->toBe('image/webp')
        ->and($result->extension)->toBe('webp');
});

it('ignores a misleading client filename/extension and detects format from content', function () {
    $result = (new GdImageIngestor)->ingest(
        ingestInput(jpegMarkerBytes(), originalFilename: 'totally-a-photo.png'),
        ingestPolicy(),
    );

    expect($result->mimeType)->toBe('image/jpeg')
        ->and($result->extension)->toBe('jpg');
});

it('rejects random bytes as an unrecognized format', function () {
    expect(fn () => (new GdImageIngestor)->ingest(ingestInput(random_bytes(64)), ingestPolicy()))
        ->toThrow(UnsupportedImageFormatException::class);
});

it('rejects empty bytes', function () {
    expect(fn () => (new GdImageIngestor)->ingest(ingestInput(''), ingestPolicy()))
        ->toThrow(UnsupportedImageFormatException::class);
});

it('rejects a truncated image that has a valid header but corrupt body', function () {
    // Cut deep enough to leave the header (and SOF dimensions) parseable but
    // truncate the scan data itself, so getimagesizefromstring() still
    // succeeds and only the full decode fails.
    $truncated = substr(jpegMarkerBytes(200, 200), 0, 400);

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($truncated), ingestPolicy()))
        ->toThrow(ImageDecodeException::class);
});

it('rejects GIF as an unsupported format', function () {
    expect(fn () => (new GdImageIngestor)->ingest(ingestInput(gifBytes()), ingestPolicy()))
        ->toThrow(UnsupportedImageFormatException::class);
});

it('rejects SVG as an unsupported format', function () {
    expect(fn () => (new GdImageIngestor)->ingest(ingestInput(svgBytes()), ingestPolicy()))
        ->toThrow(UnsupportedImageFormatException::class);
});

it('rejects BMP as an unsupported format', function () {
    expect(fn () => (new GdImageIngestor)->ingest(ingestInput(bmpBytes()), ingestPolicy()))
        ->toThrow(UnsupportedImageFormatException::class);
});

// --- limits --------------------------------------------------------------

it('rejects an image exceeding the byte limit', function () {
    $bytes = jpegMarkerBytes(200, 200);

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxBytes' => strlen($bytes) - 1])))
        ->toThrow(ImageTooLargeException::class);
});

it('accepts an image exactly at the byte limit', function () {
    $bytes = jpegMarkerBytes(200, 200);

    $result = (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxBytes' => strlen($bytes)]));

    expect($result->byteSize)->toBeGreaterThan(0);
});

it('rejects an image exceeding the width limit', function () {
    $bytes = jpegMarkerBytes(100, 10);

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxWidth' => 99])))
        ->toThrow(ImageDimensionsExceededException::class);
});

it('accepts an image exactly at the width limit', function () {
    $bytes = jpegMarkerBytes(100, 10);

    $result = (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxWidth' => 100]));

    expect($result->width)->toBe(100);
});

it('rejects an image exceeding the height limit', function () {
    $bytes = jpegMarkerBytes(10, 100);

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxHeight' => 99])))
        ->toThrow(ImageDimensionsExceededException::class);
});

it('accepts an image exactly at the height limit', function () {
    $bytes = jpegMarkerBytes(10, 100);

    $result = (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxHeight' => 100]));

    expect($result->height)->toBe(100);
});

it('rejects an image whose EXIF-corrected dimensions exceed asymmetric limits even though the as-stored dimensions do not', function () {
    // Stored as 100x50 (passes maxWidth=200/maxHeight=80 as-is), but
    // orientation 6 (rotate 90 CW) swaps it to 50x100 — the corrected height
    // of 100 now exceeds maxHeight=80, which the pre-decode check alone
    // could never catch.
    $bytes = jpegWithExifOrientation(6, 100, 50);

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxWidth' => 200, 'maxHeight' => 80])))
        ->toThrow(ImageDimensionsExceededException::class);
});

it('rejects an image exceeding the total pixel limit even when each dimension is individually within bounds', function () {
    $bytes = jpegMarkerBytes(100, 100); // 10,000 px — under maxWidth/maxHeight, over a tight maxPixels

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxWidth' => 200, 'maxHeight' => 200, 'maxPixels' => 9_999])))
        ->toThrow(ImagePixelLimitExceededException::class);
});

it('accepts an image exactly at the total pixel limit', function () {
    $bytes = jpegMarkerBytes(100, 100); // exactly 10,000 px

    $result = (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy(['maxWidth' => 200, 'maxHeight' => 200, 'maxPixels' => 10_000]));

    expect($result->width * $result->height)->toBe(10_000);
});

it('rejects when the normalized output exceeds the byte limit', function () {
    // A noisy 150x150 image encoded at low quality is small; re-encoding the
    // same pixels at quality 100 genuinely produces more bytes than that —
    // this is a real, not artificial, "output bigger than input" case.
    $im = imagecreatetruecolor(150, 150);

    for ($y = 0; $y < 150; $y++) {
        for ($x = 0; $x < 150; $x++) {
            imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7) % 256, ($y * 13) % 256, (($x + $y) * 3) % 256));
        }
    }

    ob_start();
    imagejpeg($im, null, 10);
    $lowQualityBytes = ob_get_clean();

    expect(fn () => (new GdImageIngestor)->ingest(
        ingestInput($lowQualityBytes),
        ingestPolicy(['maxBytes' => strlen($lowQualityBytes) + 500, 'jpegQuality' => 100]),
    ))->toThrow(ImageEncodeException::class);
});

// --- EXIF orientation ------------------------------------------------------

it('has no orientation correction to apply when there is no EXIF data at all', function () {
    $result = (new GdImageIngestor)->ingest(ingestInput(jpegMarkerBytes()), ingestPolicy());
    $corners = markerCorners($result->bytes);

    expect($corners)->toMatchArray(['tl' => 'RED', 'tr' => 'GREEN', 'bl' => 'BLUE', 'br' => 'WHITE', 'width' => 20, 'height' => 10]);
});

it('rejects an unrecognized EXIF orientation value instead of silently defaulting', function () {
    $bytes = jpegWithExifOrientation(9);

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy()))
        ->toThrow(ImageOrientationException::class);
});

dataset('exif orientations', [
    'orientation 1 (normal)' => [1, ['tl' => 'RED', 'tr' => 'GREEN', 'bl' => 'BLUE', 'br' => 'WHITE', 'width' => 20, 'height' => 10]],
    'orientation 2 (mirror horizontal)' => [2, ['tl' => 'GREEN', 'tr' => 'RED', 'bl' => 'WHITE', 'br' => 'BLUE', 'width' => 20, 'height' => 10]],
    'orientation 3 (rotate 180)' => [3, ['tl' => 'WHITE', 'tr' => 'BLUE', 'bl' => 'GREEN', 'br' => 'RED', 'width' => 20, 'height' => 10]],
    'orientation 4 (mirror vertical)' => [4, ['tl' => 'BLUE', 'tr' => 'WHITE', 'bl' => 'RED', 'br' => 'GREEN', 'width' => 20, 'height' => 10]],
    'orientation 5 (transpose)' => [5, ['tl' => 'RED', 'tr' => 'BLUE', 'bl' => 'GREEN', 'br' => 'WHITE', 'width' => 10, 'height' => 20]],
    'orientation 6 (rotate 90 CW)' => [6, ['tl' => 'BLUE', 'tr' => 'RED', 'bl' => 'WHITE', 'br' => 'GREEN', 'width' => 10, 'height' => 20]],
    'orientation 7 (transverse)' => [7, ['tl' => 'WHITE', 'tr' => 'GREEN', 'bl' => 'BLUE', 'br' => 'RED', 'width' => 10, 'height' => 20]],
    'orientation 8 (rotate 90 CCW)' => [8, ['tl' => 'GREEN', 'tr' => 'WHITE', 'bl' => 'RED', 'br' => 'BLUE', 'width' => 10, 'height' => 20]],
]);

it('physically applies each EXIF orientation correction', function (int $orientation, array $expectedCorners) {
    $bytes = jpegWithExifOrientation($orientation, 20, 10);

    $result = (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy());

    expect($result->width)->toBe($expectedCorners['width'])
        ->and($result->height)->toBe($expectedCorners['height'])
        ->and(markerCorners($result->bytes))->toMatchArray($expectedCorners);
})->with('exif orientations');

it('rejects a PNG carrying EXIF orientation metadata rather than silently treating it as normal', function () {
    $bytes = pngWithExifChunk();

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy()))
        ->toThrow(ImageOrientationException::class);
});

it('rejects a WebP carrying EXIF orientation metadata rather than silently treating it as normal', function () {
    $bytes = webpWithExifChunk();

    expect(fn () => (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy()))
        ->toThrow(ImageOrientationException::class);
});

// --- alpha preservation -----------------------------------------------

it('preserves PNG alpha transparency', function () {
    $result = (new GdImageIngestor)->ingest(ingestInput(markerBytesWithAlpha('png')), ingestPolicy());

    $im = imagecreatefromstring($result->bytes);
    $center = imagecolorsforindex($im, imagecolorat($im, 10, 5));

    expect($center['alpha'])->toBe(127); // fully transparent fill, GD alpha scale 0-127
});

it('preserves WebP alpha transparency', function () {
    $result = (new GdImageIngestor)->ingest(ingestInput(markerBytesWithAlpha('webp')), ingestPolicy());

    $im = imagecreatefromstring($result->bytes);
    $center = imagecolorsforindex($im, imagecolorat($im, 10, 5));

    expect($center['alpha'])->toBe(127);
});

// --- metadata stripping -----------------------------------------------

it('strips EXIF and comment metadata from the normalized JPEG while keeping it physically oriented', function () {
    $plain = jpegMarkerBytes(20, 10);
    $withComment = substr($plain, 0, 2).jpegCommentSegment('SECRET-GPS-LIKE-COMMENT-MARKER').substr($plain, 2);
    $withExifAndComment = substr($withComment, 0, 2).exifOrientationSegment(6).substr($withComment, 2);

    $result = (new GdImageIngestor)->ingest(ingestInput($withExifAndComment), ingestPolicy());

    expect($result->bytes)->not->toContain('SECRET-GPS-LIKE-COMMENT-MARKER')
        ->and($result->bytes)->not->toContain('Exif');

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $result->bytes);
    rewind($stream);
    $exif = @exif_read_data($stream);
    fclose($stream);

    expect($exif === false || ! isset($exif['Orientation']))->toBeTrue();

    // Orientation 6 (rotate 90 CW) was still physically applied before stripping.
    expect(markerCorners($result->bytes))->toMatchArray(['tl' => 'BLUE', 'tr' => 'RED', 'bl' => 'WHITE', 'br' => 'GREEN', 'width' => 10, 'height' => 20]);
});

// --- normalized output sanity -------------------------------------------

it('never returns the original bytes verbatim', function () {
    $bytes = jpegMarkerBytes();
    $result = (new GdImageIngestor)->ingest(ingestInput($bytes), ingestPolicy());

    expect($result->bytes)->not->toBe($bytes);
});

it('reports a byteSize that always equals strlen(bytes)', function () {
    $result = (new GdImageIngestor)->ingest(ingestInput(markerBytesWithAlpha('png')), ingestPolicy());

    expect($result->byteSize)->toBe(strlen($result->bytes));
});

it('produces output that can itself be re-ingested successfully', function () {
    $first = (new GdImageIngestor)->ingest(ingestInput(jpegMarkerBytes()), ingestPolicy());
    $second = (new GdImageIngestor)->ingest(ingestInput($first->bytes), ingestPolicy());

    expect($second->width)->toBe($first->width)
        ->and($second->height)->toBe($first->height)
        ->and($second->mimeType)->toBe($first->mimeType);
});
