<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotFinalizeRemovalException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Declares that a moderation-hidden post will never return publicly
 * (docs/architecture/moderation-content-lifecycle.md). Sets only
 * moderation_removed_at (plus needs_review normalization — finalization is
 * a completed moderation decision) and writes exactly one ModerationLog.
 * Nothing is physically deleted: the graph, reports, votes, saves and
 * media all remain until the separate, disabled-by-default moderation
 * retention purge. Irreversible in the normal lifecycle: restore rejects
 * a finalized row and no action clears the timestamp.
 */
final class FinalizePostRemovalAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, Post $post, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CannotFinalizeRemovalException::becauseReasonIsRequired();
        }

        // Cheap pre-check; the locked re-check below is authoritative.
        if (! $admin->can('finalizeRemoval', $post)) {
            throw CannotFinalizeRemovalException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $post, $reason): void {
            // Lock order: Actor User -> Post (PR-F).
            $lockedActor = $this->lockActor($admin);

            $locked = Post::withTrashed()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw CannotFinalizeRemovalException::becauseTargetStateIsInvalid();
            }

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('finalizeRemoval', $locked)) {
                throw CannotFinalizeRemovalException::becauseUserIsNotAllowed();
            }

            // Only a reversible moderation hide is finalizable: never a live
            // or author-deleted post, never a malformed soft-deleted row,
            // never twice.
            if (
                $locked->trashed()
                || $locked->status !== PostStatus::Hidden
                || $locked->moderation_removed_at !== null
            ) {
                throw CannotFinalizeRemovalException::becauseTargetStateIsInvalid();
            }

            $locked->forceFill([
                'moderation_removed_at' => now(),
                'needs_review' => false,
            ])->save();

            $this->createModerationLog->handle(
                moderator: $lockedActor,
                action: ModerationActionType::FinalizePostRemoval,
                target: $locked,
                reason: $reason,
                metadata: [
                    // Honest derived transition: the status enum stays
                    // Hidden; finalization is a lifecycle-metadata change.
                    'from_state' => 'hidden',
                    'to_state' => 'removal_finalized',
                    'moderation_removed_at' => $locked->moderation_removed_at->toIso8601String(),
                ],
            );

            $post->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
