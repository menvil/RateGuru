<?php

namespace App\Actions\Personas;

use App\Data\Personas\PersonaData;
use App\Enums\MediaKind;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\PersonaProfile;
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
 * An administrator gives a Persona its avatar, or a new one, through the
 * same media path a member's own avatar takes (UpdateUserProfileAction):
 *
 * 1. the image is checked and normalized once by the ingestor and stored —
 *    slow file I/O, so before any transaction;
 * 2. in the transaction the administrator's row and the Persona's are locked
 *    in ascending id order (LocksUsersInOrder), as UpdatePersonaAction and the
 *    sanctions lock them, and everything is asked again on them — the
 *    administrator may still manage Personas, the account is still a Persona
 *    and not deleted meanwhile, so an avatar never lands on a tombstone — and
 *    the Persona is still what the editor was drawn from: the same profile
 *    (`$expectedFingerprint`) and the same avatar (`$expectedAvatarAssetId`),
 *    so a photo chosen before somebody else's save is refused rather than
 *    put over it;
 * 3. the MediaAsset is made — owned by the Persona, whose avatar it is, never
 *    by the administrator who uploaded it — and `users.avatar_asset_id`
 *    points at it;
 * 4. only then is the previous avatar released through the media lifecycle
 *    (soft-deleted when nothing references it, purged after the grace
 *    period), so the account is never left pointing at a released asset.
 *    The lock makes two uploads take turns: the second releases the avatar
 *    the locked row points at rather than leaving it active and unreferenced;
 * 5. after the commit, its variants are generated from the stored master.
 *
 * A failure past the store removes the file it wrote, unless an asset row
 * claims it.
 */
final class SetPersonaAvatarAction
{
    use LocksUsersInOrder;

    public function __construct(
        private readonly ImageUploadStorer $imageUploadStorer,
        private readonly MediaAssetCreator $mediaAssetCreator,
        private readonly StoredMediaCleaner $storedMediaCleaner,
        private readonly MediaLifecycleService $lifecycleService,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  string  $expectedFingerprint  PersonaData::fingerprint() of the Persona as the editor was drawn from it, or as this request has just saved it
     * @param  int|null  $expectedAvatarAssetId  the avatar the editor was drawn with
     *
     * @throws CannotManagePersonaException when the administrator may not, the account is no Persona to edit, or it changed meanwhile
     * @throws ImageIngestException when the file is not an image the site takes
     */
    public function handle(User $admin, User $persona, UploadedFile $file, string $expectedFingerprint, ?int $expectedAvatarAssetId): void
    {
        if (! $admin->can('manage-personas')) {
            throw CannotManagePersonaException::becauseUserIsNotAllowed();
        }

        // Asked of the caller's copy first, so no file is stored for an
        // account that is plainly no Persona; asked again on the locked row.
        if (! $persona->isPersona() || $persona->isTombstoned()) {
            throw CannotManagePersonaException::becausePersonaIsGone();
        }

        $stored = $this->imageUploadStorer->store($file, MediaStoreRequest::forAvatar($persona->id));

        try {
            $assetId = DB::transaction(function () use ($admin, $persona, $stored, $expectedFingerprint, $expectedAvatarAssetId): int {
                $rows = $this->lockUsersInOrder((int) $admin->getKey(), (int) $persona->getKey());
                $lockedAdmin = $rows->get($admin->getKey());

                if ($lockedAdmin === null || ! $lockedAdmin->can('manage-personas')) {
                    throw CannotManagePersonaException::becauseUserIsNotAllowed();
                }

                $locked = $rows->get($persona->getKey());

                if ($locked === null || ! $locked->isPersona() || $locked->isTombstoned()) {
                    throw CannotManagePersonaException::becausePersonaIsGone();
                }

                $profile = PersonaProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
                $previousAssetId = $locked->avatar_asset_id !== null ? (int) $locked->avatar_asset_id : null;

                // Asked before anything is made: a refusal leaves no asset, and
                // the file stored above is removed with any other failure.
                if ($previousAssetId !== $expectedAvatarAssetId
                    || PersonaData::of($locked, $profile)->fingerprint() !== $expectedFingerprint) {
                    throw CannotManagePersonaException::becausePersonaChanged();
                }

                $asset = $this->mediaAssetCreator->create($stored, MediaKind::Avatar, $locked);

                $locked->forceFill(['avatar_asset_id' => $asset->id])->save();

                if ($previousAssetId !== null) {
                    $this->release($previousAssetId);
                }

                $persona->setRawAttributes($locked->getAttributes(), true);
                $persona->unsetRelation('avatarAsset');

                return $asset->id;
            });
        } catch (Throwable $exception) {
            // The file was written before the transaction; whatever went
            // wrong since must not leave it orphaned on disk.
            $this->storedMediaCleaner->deleteIfUnclaimed($stored);

            throw $exception;
        }

        $this->logger->info('persona.avatar.updated', [
            'user_id' => $persona->id,
            'admin_id' => $admin->id,
        ]);

        try {
            GenerateMediaVariantsJob::dispatch($assetId)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);

            // On the sync queue dispatch() runs the job inline, so reaching
            // this means the job ran and failed: the avatar is set, and its
            // variants can be generated again from Media diagnostics.
            Log::error('Failed to dispatch or run media variant generation.', [
                'user_id' => $persona->id,
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
