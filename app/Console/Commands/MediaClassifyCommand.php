<?php

namespace App\Console\Commands;

use App\Enums\ClassificationStatus;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Jobs\ClassifyMediaAssetJob;
use App\Models\MediaAsset;
use App\Services\Media\Classification\MediaClassifierRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queues post media stored before a classifier was configured — the newest
 * first, up to --limit — for every configured classifier that has not
 * answered for it yet. New media is queued as it becomes ready; this is for
 * what came before, e.g. to try a classifier in shadow mode on real uploads.
 */
final class MediaClassifyCommand extends Command
{
    protected $signature = 'media:classify {--limit=100 : How many assets to queue at most}';

    protected $description = 'Queue existing post media for the configured content classifiers';

    public function handle(MediaClassifierRegistry $registry): int
    {
        $classifiers = $registry->configured();

        if ($classifiers === []) {
            $this->warn('No classifier is configured (media.classification.classifiers); nothing to queue.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $queued = 0;

        MediaAsset::query()
            ->select('id')
            ->where('kind', MediaKind::PostImage)
            ->where('status', MediaStatus::Ready)
            // Not yet answered for by at least one of them, at its version.
            ->where(function (Builder $query) use ($classifiers): void {
                foreach ($classifiers as $entry) {
                    $query->orWhereDoesntHave('classifications', fn (Builder $answered): Builder => $answered
                        ->where('classifier', $entry['classifier']->name())
                        ->where('model_version', $entry['classifier']->modelVersion())
                        ->where('status', ClassificationStatus::Succeeded));
                }
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$queued): void {
                ClassifyMediaAssetJob::dispatch($id);
                $queued++;
            });

        $this->info("Queued {$queued} asset(s) for classification.");

        return self::SUCCESS;
    }
}
