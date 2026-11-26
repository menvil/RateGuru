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
use Illuminate\Support\Collection;

/**
 * Queues post media stored before a classifier was configured — the newest
 * first, up to --limit — for every configured classifier that has not
 * answered for it yet. New media is queued as it becomes ready; this is for
 * what came before, e.g. to try a classifier in shadow mode on real uploads.
 *
 * An asset a classifier failed on counts as answered unless --retry-failed
 * says otherwise, so a file nothing can be taken from never keeps the head
 * of the list. --retry-failed clears those failures for the assets it
 * queues, so they are asked again by whatever job runs next for each. The jobs are spread out, ten seconds apart, so the videos
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
            ->chunk(500)
            ->each(function (Collection $ids) use (&$queued, $classifiers, $retryFailed): void {
                // Asked again by clearing what they failed: whichever job
                // runs next for the asset — this one, or one already
                // waiting for it — finds them unanswered and asks them.
                if ($retryFailed) {
                    MediaClassification::query()
                        ->whereIn('media_asset_id', $ids->all())
                        ->where('status', ClassificationStatus::Failed)
                        ->where(function (Builder $configured) use ($classifiers): void {
                            foreach ($classifiers as $entry) {
                                $configured->orWhere(fn (Builder $one): Builder => $one
                                    ->where('classifier', $entry['classifier']->name())
                                    ->where('model_version', $entry['classifier']->modelVersion()));
                            }
                        })
                        ->delete();
                }

                foreach ($ids as $id) {
                    $delay = $queued * self::SPACING_SECONDS;
                    ClassifyMediaAssetJob::dispatch((int) $id, $delay)->delay(now()->addSeconds($delay));
                    $queued++;
                }
            });

        $this->info("Queued {$queued} asset(s) for classification.");

        return self::SUCCESS;
    }
}
