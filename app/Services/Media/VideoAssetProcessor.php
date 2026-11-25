<?php

namespace App\Services\Media;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Services\Media\Exceptions\VideoIngestException;
use App\Support\Media\ImageOrientationClassifier;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a video or animated-GIF upload into what pages play, the way the
 * image ingest and variant generator do for a photo:
 *
 *   the original (private, as it arrived)
 *     -> probe, then the upload policy
 *     -> the master: H.264/AAC MP4, at most 1080 on the short side, public
 *     -> display renditions below it (video_720, video_480)
 *     -> a poster frame, put through the image variants (feed, detail, Open
 *        Graph) so everything that shows a still works for a video unchanged
 *     -> the asset Ready, with the master's picture, length and sound
 *
 * The asset arrives Processing, its disk and path naming the original. The
 * original's location is kept in metadata.original before anything else, so
 * a retry after a partial run reads the same original and rewrites the same
 * master path. A file the policy or ffmpeg refuses leaves the asset Failed
 * with a safe reason, and is not retried; anything else (a storage error, a
 * tool that timed out) is thrown for the queue to retry.
 */
final class VideoAssetProcessor
{
    private const string MASTER_MIME = 'video/mp4';

    public function __construct(
        private readonly VideoProbe $probe,
        private readonly VideoTranscoder $transcoder,
        private readonly MediaStorage $storage,
        private readonly MediaVariantWriter $writer,
        private readonly MediaVariantSpecificationRegistry $registry,
        private readonly ImageOrientationClassifier $orientation,
    ) {}

    public function process(MediaAsset $asset): void
    {
        if ($asset->trashed() || $asset->status !== MediaStatus::Processing || $asset->media_type === MediaType::Image) {
            return;
        }

        // One run per asset at a time: a retry racing the first attempt would
        // write the same files from two workers.
        $lock = $this->lock("media-video-process:{$asset->id}");

        if (! $lock->get()) {
            return;
        }

        $work = sys_get_temp_dir().'/rateguru-video-'.Str::uuid()->toString();

        try {
            if (! @mkdir($work, 0o700)) {
                throw new RuntimeException('Could not create a work directory for video processing.');
            }

            $this->run($asset, $work);
        } catch (VideoIngestException $exception) {
            Log::warning('VideoAssetProcessor: video refused.', [
                'media_asset_id' => $asset->id,
                'exception_class' => $exception::class,
            ]);

            $asset->forceFill(['status' => MediaStatus::Failed, 'processing_error' => $exception->getMessage()])->save();
        } finally {
            exec('rm -rf '.escapeshellarg($work));
            $lock->release();
        }
    }

    private function run(MediaAsset $asset, string $work): void
    {
        $original = $this->rememberOriginal($asset);
        $policy = VideoIngestPolicy::fromConfig();

        $source = $work.'/original';
        $this->storage->copyToFile($original, $source);
        $policy->assertWithinByteLimit((int) filesize($source));

        $probe = $this->probe->probe($source);
        $policy->assertAcceptable($probe, (int) filesize($source));

        $animation = $asset->media_type === MediaType::Animation || $probe->formatName === 'gif';
        $withAudio = ! $animation && $probe->hasAudio();

        $master = $work.'/master.mp4';
        $this->transcoder->transcode($source, $probe, VideoRendition::master(), $master, $withAudio);
        $masterProbe = $this->probe->probe($master);

        $this->storeMaster($asset, $original, $master, $masterProbe, $animation, $withAudio);

        foreach (VideoRendition::displays() as $rendition) {
            if ($rendition->name === null || $rendition->shortSide >= min($masterProbe->width, $masterProbe->height)) {
                continue;
            }

            $output = "{$work}/{$rendition->name->value}.mp4";
            $this->transcoder->transcode($master, $masterProbe, $rendition, $output, $withAudio);
            $this->writer->writeFile($asset, $rendition->name, $this->produced($output, $this->probe->probe($output)));
        }

        $this->storePosterVariants($asset, $master, $masterProbe, $work);

        $asset->forceFill(['status' => MediaStatus::Ready, 'processing_error' => null])->save();
    }

    /**
     * Where the original is: metadata.original once a run has started, the
     * asset's own disk and path before that — recorded at once, because the
     * asset's path moves to the master partway through.
     */
    private function rememberOriginal(MediaAsset $asset): MediaLocation
    {
        $metadata = $asset->metadata ?? [];
        $recorded = $metadata['original'] ?? null;

        if (is_array($recorded) && is_string($recorded['disk'] ?? null) && is_string($recorded['path'] ?? null)) {
            return new MediaLocation($recorded['disk'], $recorded['path']);
        }

        $asset->forceFill(['metadata' => [...$metadata, 'original' => ['disk' => $asset->disk, 'path' => $asset->path]]])->save();

        return new MediaLocation($asset->disk, $asset->path);
    }

    /**
     * The master replaces the original as the asset's file: public, under
     * post-videos, named after the original (so a retry lands on the same
     * key), with the picture, length and sound it actually has.
     */
    private function storeMaster(MediaAsset $asset, MediaLocation $original, string $master, VideoProbeResult $probe, bool $animation, bool $withAudio): void
    {
        $location = new MediaLocation(
            (string) config('media.disks.public'),
            rtrim((string) config('media.directories.post_videos'), '/').'/'
                .($asset->created_at ?? now())->format('Y/m').'/'
                .pathinfo($original->path, PATHINFO_FILENAME).'.mp4',
        );

        $this->storage->putFile($location, $master, MediaVisibility::Public);

        $asset->forceFill([
            'disk' => $location->disk,
            'path' => $location->path,
            'visibility' => MediaVisibility::Public,
            'mime_type' => self::MASTER_MIME,
            'extension' => 'mp4',
            'byte_size' => (int) filesize($master),
            'width' => $probe->width,
            'height' => $probe->height,
            'aspect_ratio' => round($probe->width / max(1, $probe->height), 6),
            'orientation' => $this->orientation->classify($probe->width, $probe->height),
            'duration_ms' => $probe->durationMs,
            'has_audio' => $withAudio && $probe->hasAudio(),
            'media_type' => $animation ? MediaType::Animation : MediaType::Video,
        ])->save();
    }

    /**
     * The poster goes through the same image variants a photo gets, so a
     * thumbnail, a share card or a game picker shows a video like a photo.
     */
    private function storePosterVariants(MediaAsset $asset, string $master, VideoProbeResult $masterProbe, string $work): void
    {
        $poster = $work.'/poster.png';
        $this->transcoder->poster($master, $masterProbe->durationMs, $poster);
        $bytes = (string) file_get_contents($poster);

        foreach ($this->registry->for(MediaKind::PostImage) as $specification) {
            if ($specification->wouldUpscale($masterProbe->width, $masterProbe->height)) {
                continue;
            }

            $this->writer->write($asset, $bytes, $specification, 'image/png');
        }
    }

    private function produced(string $path, VideoProbeResult $probe): ProducedMediaFile
    {
        return new ProducedMediaFile(
            localPath: $path,
            mimeType: self::MASTER_MIME,
            extension: 'mp4',
            byteSize: (int) filesize($path),
            width: $probe->width,
            height: $probe->height,
            durationMs: $probe->durationMs,
        );
    }

    /**
     * The database store, as MediaVariantWriter's own lock: one mutex for
     * every process on every server. Held no longer than ProcessVideoAssetJob
     * may run, plus a margin, so a crashed worker cannot hold it for good.
     */
    private function lock(string $key): Lock
    {
        $store = Cache::store('database')->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The "database" cache store does not support locks.');
        }

        return $store->lock($key, 960);
    }
}
