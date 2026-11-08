<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Enums\ImageInputSource;
use App\Enums\MediaKind;
use App\Exceptions\Games\CannotManageGameException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\Game;
use App\Models\User;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMediaCleaner;
use App\Support\Media\ImageUploadStorer;
use App\Support\Media\MediaAssetCreator;
use App\Support\Observability\DomainLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An administrator gives a game its cover, or a new one — or takes it away —
 * through the media path a post's own image takes, as Content Studio's photo
 * does (SetStudioPlanImageAction): checked and stored before the transaction,
 * the asset made and the game pointed at it under the game's lock and
 * version, the previous cover released only afterwards, its variants made
 * after the commit. A published version keeps its own copy of the cover it
 * was published with.
 */
final class SetGameCoverAction
{
    use LocksGameDraft;

    public function __construct(
        private readonly ImageUploadStorer $imageUploadStorer,
        private readonly MediaAssetCreator $mediaAssetCreator,
        private readonly StoredMediaCleaner $storedMediaCleaner,
        private readonly MediaLifecycleService $lifecycleService,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws CannotManageGameException
     * @throws ImageIngestException when the file is not an image the site takes
     */
    public function handle(User $admin, Game $game, UploadedFile $file, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $stored = $this->imageUploadStorer->store($file, MediaStoreRequest::forPostImage((int) $admin->getKey()), ImageInputSource::Upload);

        try {
            $assetId = DB::transaction(function () use ($admin, $game, $stored, $expectedLockVersion): int {
                $lockedAdmin = $this->lockAdministrator($admin);
                $locked = $this->lockGame((int) $game->id, $expectedLockVersion);
                $previous = $locked->cover_asset_id;

                $asset = $this->mediaAssetCreator->create($stored, MediaKind::PostImage, $lockedAdmin);
                $locked->forceFill(['cover_asset_id' => $asset->id]);
                $this->touchGame($locked, $lockedAdmin);

                if ($previous !== null) {
                    $this->lifecycleService->releaseUnreferenced(collect([$previous]));
                }

                $game->setRawAttributes($locked->getAttributes(), true);
                $game->unsetRelation('coverAsset');

                return $asset->id;
            });
        } catch (Throwable $exception) {
            $this->storedMediaCleaner->deleteIfUnclaimed($stored);

            throw $exception;
        }

        $this->logger->info('game.cover.updated', ['game_id' => $game->id, 'admin_id' => $admin->id, 'media_asset_id' => $assetId]);

        try {
            GenerateMediaVariantsJob::dispatch($assetId)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
            Log::error('Failed to dispatch or run media variant generation.', ['game_id' => $game->id, 'media_asset_id' => $assetId, 'exception' => $exception->getMessage()]);
        }
    }

    /** @throws CannotManageGameException */
    public function remove(User $admin, Game $game, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $game, $expectedLockVersion): void {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id, $expectedLockVersion);
            $previous = $locked->cover_asset_id;

            $locked->forceFill(['cover_asset_id' => null]);
            $this->touchGame($locked, $lockedAdmin);

            if ($previous !== null) {
                $this->lifecycleService->releaseUnreferenced(collect([$previous]));
            }

            $game->setRawAttributes($locked->getAttributes(), true);
            $game->unsetRelation('coverAsset');
        });
    }
}
