<?php

namespace App\Services\Media\Classification;

use App\Enums\MediaResizeMode;
use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\Exceptions\MediaVariantGenerationException;
use App\Services\Media\Exceptions\VideoUnreadableException;
use App\Services\Media\ImageVariantProcessor;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\VideoTranscoder;

/**
 * What a classifier is shown of an asset, as local JPEGs no larger than
 * MAX_SIDE — the format and size every provider takes:
 *
 *   a photo   its master, scaled down
 *   a video   its poster frame (what a feed shows before it plays), and a
 *   or GIF    frame from every stretch of it — one per two seconds, at most
 *             MAX_FRAMES, each from the middle of its stretch
 *
 * The files go into $directory, which the caller owns and clears. A frame
 * ffmpeg cannot take, or takes too long over, is left out; a file that
 * cannot be read at all, or gives no frame, is said as such — the same file
 * would fail the same way again. Only ffmpeg missing from the host goes up
 * as it is, for the job to try again.
 */
final class ClassificationFrameSampler
{
    public const int MAX_SIDE = 1280;

    public const int MAX_FRAMES = 30;

    /** One frame per this many seconds, until a long clip reaches MAX_FRAMES. */
    public const int SECONDS_PER_FRAME = 2;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly ImageVariantProcessor $images,
        private readonly VideoTranscoder $transcoder,
    ) {}

    /**
     * @throws MediaClassificationFailedException when no frame could be taken
     * @throws MediaToolUnavailableException
     */
    public function sample(MediaAsset $asset, string $directory): ClassificationInput
    {
        try {
            $frames = $asset->media_type === MediaType::Image
                ? [$this->still($asset, $directory)]
                : $this->frames($asset, $directory);
        } catch (MediaStorageException) {
            throw new MediaClassificationFailedException('The media file could not be read.');
        } catch (MediaVariantGenerationException) {
            throw new MediaClassificationFailedException('The picture could not be decoded.');
        }

        $frames = array_values(array_filter($frames, fn (ClassificationFrame $frame): bool => is_file($frame->path) && filesize($frame->path) > 0));

        if ($frames === []) {
            throw new MediaClassificationFailedException('No frame could be taken from the media.');
        }

        return new ClassificationInput($frames);
    }

    private function still(MediaAsset $asset, string $directory): ClassificationFrame
    {
        $master = $directory.'/master';
        $this->storage->copyToFile(new MediaLocation($asset->disk, $asset->path), $master);

        $still = $this->images->generate((string) file_get_contents($master), (string) $asset->mime_type, new MediaVariantSpecification(
            // The size of the feed's own picture, as a JPEG.
            MediaVariantName::PostFeed1280,
            self::MAX_SIDE,
            self::MAX_SIDE,
            MediaResizeMode::Contain,
            85,
            outputMimeType: 'image/jpeg',
        ));
        @unlink($master);

        $path = $directory.'/still.jpg';
        file_put_contents($path, $still->bytes);

        return new ClassificationFrame($path, null, 'still');
    }

    /** @return list<ClassificationFrame> */
    private function frames(MediaAsset $asset, string $directory): array
    {
        $video = $directory.'/master.mp4';
        $this->storage->copyToFile(new MediaLocation($asset->disk, $asset->path), $video);

        $frames = [];

        foreach (self::times($asset->duration_ms) as $index => [$atMs, $label]) {
            $path = sprintf('%s/frame-%02d.jpg', $directory, $index);

            try {
                $this->transcoder->frame($video, $atMs / 1000, $path, self::MAX_SIDE);
            } catch (VideoUnreadableException|MediaToolTimedOutException) {
                // One frame short — past the end of a container that claimed
                // more, or a stalled seek — and the rest still tell.
                continue;
            }

            $frames[] = new ClassificationFrame($path, $atMs, $label);
        }

        @unlink($video);

        return $frames;
    }

    /**
     * When the frames are taken: the poster's moment first — a tenth of the
     * way in, at most three seconds, as VideoTranscoder::poster takes it —
     * then the middle of every stretch.
     *
     * @return list<array{int, string}> milliseconds, and what the frame is
     */
    public static function times(?int $durationMs): array
    {
        if ($durationMs === null || $durationMs <= 0) {
            return [[0, 'poster']];
        }

        $times = [[(int) round(min(3000, $durationMs / 10)), 'poster']];
        $count = max(1, min(self::MAX_FRAMES, intdiv($durationMs, self::SECONDS_PER_FRAME * 1000)));
        $stretch = $durationMs / $count;

        for ($n = 0; $n < $count; $n++) {
            $times[] = [(int) round($stretch * ($n + 0.5)), 'frame'];
        }

        return $times;
    }
}
