<?php

namespace App\Console\Commands;

use App\Enums\MediaKind;
use App\Enums\MediaResizeMode;
use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\MediaVariantGenerator;
use App\Services\Media\MediaVariantSpecificationRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateMediaVariantsCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 1000;

    protected $signature = 'media:generate-variants
        {--asset= : Only process the MediaAsset with this id}
        {--kind= : Only process assets of this kind (post_image or avatar)}
        {--missing-only : Only process assets missing at least one applicable variant (default)}
        {--force : Regenerate every matching asset\'s variants, even ones that already exist}
        {--chunk=200 : Number of assets to load per chunk}';

    protected $description = 'Generate responsive media variants for post image / avatar assets.';

    public function handle(MediaVariantGenerator $generator, MediaVariantSpecificationRegistry $registry): int
    {
        $force = (bool) $this->option('force');

        $query = MediaAsset::query()
            ->with('variants')
            ->where('status', MediaStatus::Ready->value)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp']);

        if (! $this->applyAssetFilter($query) || ! $this->applyKindFilter($query)) {
            return self::FAILURE;
        }

        $chunkSize = $this->chunkSize();
        $totalCandidates = (clone $query)->count();
        $progress = $totalCandidates > 0 ? $this->output->createProgressBar($totalCandidates) : null;
        $progress?->start();

        $processed = 0;
        $generated = 0;
        $failures = 0;

        $query->orderBy('id')->chunkById($chunkSize, function ($assets) use (&$processed, &$generated, &$failures, $progress, $generator, $registry, $force): void {
            foreach ($assets as $asset) {
                $progress?->advance();

                if (! $force && ! $this->isMissingAnyVariant($asset, $registry)) {
                    continue;
                }

                $processed++;

                try {
                    $generator->generateAll($asset);
                    $generated++;
                } catch (Throwable $exception) {
                    $failures++;

                    Log::error('Failed to generate media variants.', [
                        'media_asset_id' => $asset->id,
                        'exception' => $exception,
                    ]);

                    $this->error("Failed to generate variants for media asset {$asset->id}: {$exception->getMessage()}");
                }
            }
        });

        if ($progress !== null) {
            $progress->finish();
            $this->newLine();
        }

        $this->info("Generated variants for {$generated} of {$processed} candidate media assets.");

        if ($failures > 0) {
            $this->error("Failed to generate variants for {$failures} media assets.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    private function applyAssetFilter(Builder $query): bool
    {
        $rawAssetId = $this->option('asset');

        if ($rawAssetId === null) {
            return true;
        }

        $assetId = filter_var($rawAssetId, FILTER_VALIDATE_INT);

        if ($assetId === false) {
            $this->error("Invalid --asset value: [{$rawAssetId}].");

            return false;
        }

        $query->whereKey($assetId);

        return true;
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    private function applyKindFilter(Builder $query): bool
    {
        $rawKind = $this->option('kind');

        if ($rawKind === null) {
            return true;
        }

        $kind = MediaKind::tryFrom($rawKind);

        if ($kind === null) {
            $this->error("Invalid --kind value: [{$rawKind}].");

            return false;
        }

        $query->where('kind', $kind->value);

        return true;
    }

    /**
     * A CoverSquare spec too small to ever generate (see
     * MediaVariantGenerator) doesn't count as "missing" — it will never be
     * generated regardless of how many times this command runs.
     */
    private function isMissingAnyVariant(MediaAsset $asset, MediaVariantSpecificationRegistry $registry): bool
    {
        $existingNames = $asset->variants
            ->map(fn (MediaVariant $variant): string => $variant->name->value)
            ->all();

        foreach ($registry->for($asset->kind) as $specification) {
            if (in_array($specification->name->value, $existingNames, true)) {
                continue;
            }

            if ($specification->mode === MediaResizeMode::CoverSquare
                && min($asset->width, $asset->height) < $specification->maxWidth) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function chunkSize(): int
    {
        $rawChunkSize = $this->option('chunk');
        $chunkSize = filter_var($rawChunkSize, FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1) {
            $this->warn('Invalid chunk size provided; using 1.');

            return 1;
        }

        if ($chunkSize > self::MAX_CHUNK_SIZE) {
            $this->warn('Chunk size exceeds maximum; using '.self::MAX_CHUNK_SIZE.'.');

            return self::MAX_CHUNK_SIZE;
        }

        return $chunkSize;
    }
}
