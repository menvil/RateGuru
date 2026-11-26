<?php

namespace App\Console\Commands;

use App\Enums\ClassificationStatus;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Jobs\ClassifyMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaClassification;
use App\Services\Media\Classification\MediaClassifierRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queues post media stored before a classifier was configured — the newest
 * first, up to --limit — for every configured classifier that has not
 * answered for it yet. New media is queued as it becomes ready; this is for
 * what came before, e.g. to try a classifier in shadow mode on real uploads.
 *
 * An asset a classifier failed on counts as answered unless --retry-failed
 * says otherwise, so a file nothing can be taken from never keeps the head
 * of the list. The jobs are spread out, ten seconds apart, so the videos
 * waiting to be processed on the same media queue are never stuck behind a
 * whole batch.
 */
final class MediaClassifyCommand extends Command
{
    protected $signature = 'media:classify
        {--limit=100 : How many assets to queue at most}
        {--retry-failed : Queue again the assets a classifier failed on}';

    protected $description = 'Queue existing post media for the configured content classifiers';

    /** How far apart the queued jobs become available. */
    public const int SPACING_SECONDS = 10;

    public function handle(MediaClassifierRegistry $registry): int
    {
        $classifiers = $registry->configured();

        if ($classifiers === []) {
            $this->warn('No classifier is configured (media.classification.classifiers); nothing to queue.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $retryFailed = (bool) $this->option('retry-failed');
        // What failed up to the last answer there is now is asked again; a
        // failure recorded since — by the job itself, on its own retry
        // too — is not.
        $retryFailuresThrough = $retryFailed ? (int) MediaClassification::query()->max('id') : null;
        $queued = 0;

        MediaAsset::query()
            ->select('id')
            ->where('kind', MediaKind::PostImage)
            ->where('status', MediaStatus::Ready)
            // Not yet answered for by at least one of them, at its version.
            ->where(function (Builder $query) use ($classifiers, $retryFailed): void {
                foreach ($classifiers as $entry) {
                    $query->orWhereDoesntHave('classifications', fn (Builder $answered): Builder => $answered
                        ->where('classifier', $entry['classifier']->name())
                        ->where('model_version', $entry['classifier']->modelVersion())
                        ->when($retryFailed, fn (Builder $succeeded): Builder => $succeeded->where('status', ClassificationStatus::Succeeded)));
                }
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$queued, $retryFailuresThrough): void {
                $delay = $queued * self::SPACING_SECONDS;
                ClassifyMediaAssetJob::dispatch($id, $retryFailuresThrough, $delay)->delay(now()->addSeconds($delay));
                $queued++;
            });

        $this->info("Queued {$queued} asset(s) for classification.");

        return self::SUCCESS;
    }
}
