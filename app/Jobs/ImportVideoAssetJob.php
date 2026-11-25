<?php

namespace App\Jobs;

use App\Actions\Posts\FinalizePostMediaAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Models\MediaAsset;
use App\Services\Media\Exceptions\VideoIngestException;
use App\Services\Media\MediaStorage;
use App\Services\Media\StoredVideo;
use App\Services\Media\VideoIngestPolicy;
use App\Support\Import\ImportProviderDetector;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Import\YtDlp;
use App\Support\Import\YtDlpSites;
use App\Support\Media\ImageOrientationClassifier;
use App\Support\Media\MediaQueue;
use App\Support\Media\VideoUploadStorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches the video or animated GIF a post was given by link — a file's, the
 * one a post on X carries, or a page's on one of yt-dlp's sites — then hands
 * it to ProcessVideoAssetJob as any upload.
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

    public function handle(SafeImportHttpClient $client, VideoUploadStorer $storer, FinalizePostMediaAction $finalize, ?YtDlp $ytDlp = null, ?YtDlpSites $sites = null): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);
        $url = $asset?->metadata['import']['url'] ?? null;

        // Failed by an earlier attempt whose notice could not be written:
        // its author hears now — once, whichever attempt it is.
        if ($asset !== null && $asset->status === MediaStatus::Failed) {
            $finalize->failed($asset);

            return;
        }

        if ($asset === null || $asset->status !== MediaStatus::Processing || ! is_string($url)) {
            return;
        }

        // Downloaded already — a run that stopped before handing it on, or a
        // redelivery: hand it on now. Processing it twice is safe; the
        // processor holds its own lock and settles an asset once.
        if (isset($asset->metadata['import']['downloaded_at'])) {
            ProcessVideoAssetJob::dispatch((int) $asset->id);

            return;
        }

        $ytDlp ??= app(YtDlp::class);
        $sites ??= app(YtDlpSites::class);
        // A page on one of yt-dlp's sites; a video file's link goes to the
        // plain download, whatever site serves it.
        $viaYtDlp = $sites->extractorFor($url) !== null && app(ImportProviderDetector::class)->detect($url) !== 'direct_video';
        $file = self::temporaryFile();
        $directory = $file.'.d';

        try {
            if ($viaYtDlp) {
                // A page on one of yt-dlp's sites: its own extractor finds
                // the video and downloads it, no larger or longer than an
                // upload may be.
                @mkdir($directory, 0o700);
                $downloaded = $ytDlp->download($url, $directory);

                if ($downloaded === null) {
                    $this->failImport($asset, $finalize, 'The video could not be taken from that page.');

                    return;
                }
            } else {
                $client->download($url, VideoIngestPolicy::fromConfig()->maxBytes, $file);
                $downloaded = $file;
            }

            $stored = $storer->storeFile($downloaded, (string) $asset->original_filename, (int) $asset->owner_user_id);
        } catch (ImportFetchException $exception) {
            // The other site may answer next time (a timeout, a 5xx): one more
            // try, then the link is given up on.
            if ($this->attempts() < $this->tries) {
                throw $exception;
            }

            Log::warning('ImportVideoAssetJob: video not downloaded.', ['media_asset_id' => $asset->id, 'exception_class' => $exception::class]);
            $this->failImport($asset, $finalize, 'The video could not be downloaded.');

            return;
        } catch (UnsafeImportUrlException|VideoIngestException $exception) {
            // The link's or the file's fault: final.
            Log::warning('ImportVideoAssetJob: video not imported.', [
                'media_asset_id' => $asset->id,
                'exception_class' => $exception::class,
            ]);

            $this->failImport($asset, $finalize, $exception instanceof VideoIngestException
                ? $exception->getMessage()
                : 'The video could not be downloaded.');

            return;
        } finally {
            @unlink($file);
            exec('rm -rf '.escapeshellarg($directory));
        }

        $width = $stored->probe->width;
        $height = $stored->probe->height;
        $valid = $width > 0 && $height > 0;

        // Pointed at its file only while it still waits for one, read again
        // under a lock on its row: a run that met this one on the same asset
        // and settled it first keeps what it wrote, and this run's file —
        // which nothing names then — goes.
        try {
            $claimed = DB::transaction(function () use ($asset, $stored, $url, $width, $height, $valid): bool {
                $locked = MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== MediaStatus::Processing || isset($locked->metadata['import']['downloaded_at'])) {
                    return false;
                }

                $this->pointAtFile($locked, $stored, $url, $width, $height, $valid);

                return true;
            });
        } catch (Throwable $exception) {
            // The write rolled back: the file it was to name is named by
            // nothing, and the job's next attempt stores its own.
            self::forget($stored);

            throw $exception;
        }

        if (! $claimed) {
            self::forget($stored);

            return;
        }

        ProcessVideoAssetJob::dispatch((int) $asset->id);
    }

    /** Removes an original no asset names; a removal that fails is reported, never thrown over what went before. */
    private static function forget(StoredVideo $stored): void
    {
        try {
            app(MediaStorage::class)->deleteIfExists($stored->media->location());
        } catch (Throwable $cleanup) {
            report($cleanup);
        }
    }

    private function pointAtFile(MediaAsset $asset, StoredVideo $stored, string $url, int $width, int $height, bool $valid): void
    {
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

        if (! file_exists($directory) && ! @mkdir($directory, 0o700) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the directory videos are downloaded into.');
        }

        // Ours, and a directory — not a link someone else left at that name,
        // whose target the clean-up below would otherwise empty.
        if (is_link($directory) || ! is_dir($directory) || fileowner($directory) !== posix_geteuid()) {
            throw new RuntimeException('The directory videos are downloaded into is not this worker\'s own.');
        }

        foreach (glob($directory.'/*') ?: [] as $stale) {
            if (filemtime($stale) < time() - 3600) {
                exec('rm -rf '.escapeshellarg($stale));
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

        if ($asset === null) {
            return;
        }

        // No attempt comes after this one to retry a notice that could not be
        // written: it is reported instead, for media:sweep-videos to send.
        // An asset an earlier attempt failed is asked about too — its notice
        // is claimed once, so one already sent is never sent again.
        try {
            if ($asset->status === MediaStatus::Processing) {
                $this->failImport($asset, app(FinalizePostMediaAction::class), 'The video could not be downloaded.');
            } elseif ($asset->status === MediaStatus::Failed) {
                app(FinalizePostMediaAction::class)->failed($asset);
            }
        } catch (Throwable $notice) {
            report($notice);
        }
    }

    private function failImport(MediaAsset $asset, FinalizePostMediaAction $finalize, string $reason): void
    {
        // Never an asset another run of the job has downloaded meanwhile:
        // that run's file is on its way to processing.
        $changed = MediaAsset::query()
            ->whereKey($asset->id)
            ->where('status', MediaStatus::Processing)
            ->whereNull('metadata->import->downloaded_at')
            ->update(['status' => MediaStatus::Failed, 'processing_error' => $reason]);

        if ($changed === 1) {
            $finalize->failed($asset->refresh());
        }
    }
}
