<?php

namespace App\Jobs;

use App\Actions\Posts\FinalizePostMediaAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Models\MediaAsset;
use App\Services\Media\Exceptions\VideoIngestException;
use App\Services\Media\VideoIngestPolicy;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Media\ImageOrientationClassifier;
use App\Support\Media\MediaQueue;
use App\Support\Media\VideoUploadStorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches the video or animated GIF a post was given by link — a file's, or
 * the one a post on another site carries — into the place its original
 * waits, then hands it to ProcessVideoAssetJob as any upload.
 *
 * The download goes through SafeImportHttpClient (every hop validated and
 * pinned, never past the upload limit), then ffprobe and the upload policy
 * judge what arrived (VideoUploadStorer). A link that cannot be fetched or a
 * file the policy refuses fails the asset at once and tells the author; a
 * host problem is retried once.
 */
final class ImportVideoAssetJob implements RunsOnMediaQueue, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    /** The download's own limit (import.video_timeout_seconds) and ffprobe's, with room. */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $mediaAssetId)
    {
        $this->onConnection(MediaQueue::connection());
    }

    public function handle(SafeImportHttpClient $client, VideoUploadStorer $storer, FinalizePostMediaAction $finalize): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);
        $url = $asset?->metadata['import']['url'] ?? null;

        if ($asset === null || $asset->status !== MediaStatus::Processing || ! is_string($url) || isset($asset->metadata['import']['downloaded_at'])) {
            return;
        }

        $file = self::temporaryFile();

        try {
            $client->download($url, VideoIngestPolicy::fromConfig()->maxBytes, $file);

            $stored = $storer->storeFile($file, (string) $asset->original_filename, (int) $asset->owner_user_id);
        } catch (ImportFetchException|UnsafeImportUrlException|VideoIngestException $exception) {
            // The link's or the file's fault, not the host's: final.
            Log::warning('ImportVideoAssetJob: video not imported.', [
                'media_asset_id' => $asset->id,
                'exception_class' => $exception::class,
            ]);

            $this->fail($asset, $finalize, $exception instanceof VideoIngestException
                ? $exception->getMessage()
                : 'The video could not be downloaded.');

            return;
        } finally {
            @unlink($file);
        }

        $width = $stored->probe->width;
        $height = $stored->probe->height;
        $valid = $width > 0 && $height > 0;

        $asset->forceFill([
            'media_type' => $stored->mediaType,
            'duration_ms' => $stored->probe->durationMs,
            'has_audio' => $stored->mediaType === MediaType::Video && $stored->probe->hasAudio(),
            'disk' => $stored->media->disk,
            'path' => $stored->media->path,
            'mime_type' => $stored->media->mimeType,
            'extension' => $stored->media->extension,
            'byte_size' => $stored->media->byteSize,
            'width' => $valid ? $width : null,
            'height' => $valid ? $height : null,
            'aspect_ratio' => $valid ? round($width / $height, 6) : null,
            'orientation' => $valid ? app(ImageOrientationClassifier::class)->classify($width, $height) : null,
            // Where it came from, without a query that may have carried a
            // signed link's token, now that the link has done its job.
            'metadata' => [...($asset->metadata ?? []), 'import' => [
                'url' => strtok($url, '?#') ?: $url,
                'downloaded_at' => now()->toIso8601ZuluString(),
            ]],
        ])->save();

        ProcessVideoAssetJob::dispatch((int) $asset->id);
    }

    /**
     * A new, empty file only this user may read (tempnam: created
     * exclusively, mode 0600), in a directory of its own. A download a timeout
     * killed mid-way never reached its clean-up, so any file there older than
     * an hour goes first.
     */
    private static function temporaryFile(): string
    {
        $directory = sys_get_temp_dir().'/rateguru-video-import';

        if (! is_dir($directory)) {
            @mkdir($directory, 0o700, true);
        }

        foreach (glob($directory.'/*') ?: [] as $stale) {
            if (is_file($stale) && filemtime($stale) < time() - 3600) {
                @unlink($stale);
            }
        }

        $file = tempnam($directory, 'download-');

        if ($file === false) {
            throw new RuntimeException('Could not create a file to download a video into.');
        }

        return $file;
    }

    /** After the last attempt: the asset stops waiting and its author is told. */
    public function failed(?Throwable $exception): void
    {
        Log::error('ImportVideoAssetJob: import failed.', [
            'media_asset_id' => $this->mediaAssetId,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);

        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if ($asset !== null && $asset->status === MediaStatus::Processing) {
            $this->fail($asset, app(FinalizePostMediaAction::class), 'The video could not be downloaded.');
        }
    }

    private function fail(MediaAsset $asset, FinalizePostMediaAction $finalize, string $reason): void
    {
        $changed = MediaAsset::query()
            ->whereKey($asset->id)
            ->where('status', MediaStatus::Processing)
            ->update(['status' => MediaStatus::Failed, 'processing_error' => $reason]);

        if ($changed === 1) {
            $finalize->failed($asset->refresh());
        }
    }
}
