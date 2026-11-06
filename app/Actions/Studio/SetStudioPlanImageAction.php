<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Enums\ImageInputSource;
use App\Enums\MediaKind;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\StudioPlan;
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
 * An administrator gives a Draft its photo, or a new one, through the media
 * path a post's own image takes (CreatePostAction) — never a path of its own:
 *
 * 1. the plan is asked first, as it is now, so no file is stored for a plan
 *    that plainly cannot take one;
 * 2. the image is checked and normalized once by the ingestor and stored —
 *    slow file I/O, so before any transaction;
 * 3. in the transaction the administrator's row and then the plan are locked
 *    (LocksStudioPlanDraft) and everything is asked again on them: the
 *    administrator may still manage plans, and the plan is still a Draft at
 *    the version the editor was drawn from (`$expectedLockVersion`), so a
 *    photo chosen before somebody else's save — another photo included — is
 *    refused rather than put over it;
 * 4. the post-image MediaAsset is made, owned by the administrator who
 *    uploaded it — never by the plan's author, so a change of author leaves
 *    the photo as it is — `studio_plans.image_asset_id` points at it, and the
 *    version rises;
 * 5. only then is the previous photo released through the media lifecycle
 *    (soft-deleted when nothing — no post, avatar or other plan — references
 *    it, purged after the grace period), so the plan never points at a
 *    released asset. The lock makes two uploads take turns, and the second
 *    is refused by the version, so no photo is left active and unreferenced;
 * 6. after the commit, its variants are generated from the stored master.
 *
 * A failure or a refusal past the store removes the file it wrote, unless an
 * asset row claims it.
 *
 * A photo imported from a web address arrives the same way: the caller
 * downloads it with StoreImportedImageAction::download(), passes the file here
 * with ImageInputSource::UrlImport, and cleans it up afterwards.
 */
final class SetStudioPlanImageAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly ImageUploadStorer $imageUploadStorer,
        private readonly MediaAssetCreator $mediaAssetCreator,
        private readonly StoredMediaCleaner $storedMediaCleaner,
        private readonly MediaLifecycleService $lifecycleService,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the editor was drawn from it
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile
     * @throws ImageIngestException when the file is not an image the site takes
     */
    public function handle(User $admin, StudioPlan $plan, UploadedFile $file, int $expectedLockVersion, ImageInputSource $source = ImageInputSource::Upload): void
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        $this->currentDraft((int) $plan->getKey(), $expectedLockVersion, lock: false);

        $stored = $this->imageUploadStorer->store($file, MediaStoreRequest::forPostImage((int) $admin->getKey()), $source);

        try {
            $assetId = DB::transaction(function () use ($admin, $plan, $stored, $expectedLockVersion): int {
                [$lockedAdmin] = $this->lockAdministratorAndAuthor($admin);
                $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);
                $previousAssetId = $locked->image_asset_id;

                $asset = $this->mediaAssetCreator->create($stored, MediaKind::PostImage, $lockedAdmin);

                $locked->forceFill([
                    'image_asset_id' => $asset->id,
                    'lock_version' => $locked->lock_version + 1,
                ])->save();

                if ($previousAssetId !== null) {
                    $this->release($previousAssetId);
                }

                $plan->setRawAttributes($locked->getAttributes(), true);
                $plan->unsetRelation('imageAsset');

                return $asset->id;
            });
        } catch (Throwable $exception) {
            // The file was written before the transaction; whatever went
            // wrong since must not leave it orphaned on disk.
            $this->storedMediaCleaner->deleteIfUnclaimed($stored);

            throw $exception;
        }

        $this->logger->info('studio_plan.image.updated', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'media_asset_id' => $assetId,
        ]);

        try {
            GenerateMediaVariantsJob::dispatch($assetId)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);

            // On the sync queue dispatch() runs the job inline, so reaching
            // this means the job ran and failed: the photo is set, and its
            // variants can be generated again from Media diagnostics.
            Log::error('Failed to dispatch or run media variant generation.', [
                'studio_plan_id' => $plan->id,
                'media_asset_id' => $assetId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * A plain `int` parameter, for the reason AnonymizeUserAccountAction's
     * releaseAvatar() has one: releaseUnreferenced() takes exactly a
     * Collection<int, int>.
     */
    private function release(int $assetId): void
    {
        $this->lifecycleService->releaseUnreferenced(collect([$assetId]));
    }
}
