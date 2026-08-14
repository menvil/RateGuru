<?php

namespace App\Actions\Moderation;

use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Exceptions\Moderation\CannotFinalizeRemovalException;
use App\Models\Comment;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Declares that a moderation-hidden comment will never return publicly.
 * The row may be live OR already author-soft-deleted (PR-D permits
 * Hide -> author Delete; the row is still moderation evidence either
 * way), hence the withTrashed lookup. Sets only moderation_removed_at and
 * writes exactly one ModerationLog; nothing is physically deleted here.
 */
final class FinalizeCommentRemovalAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, Comment $comment, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CannotFinalizeRemovalException::becauseReasonIsRequired();
        }

        if (! $admin->can('finalizeRemoval', $comment)) {
            throw CannotFinalizeRemovalException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $comment, $reason): void {
            // Lock order: Actor User -> Comment (PR-F).
            $lockedActor = $this->lockActor($admin);

            $locked = Comment::withTrashed()
                ->whereKey($comment->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw CannotFinalizeRemovalException::becauseTargetStateIsInvalid();
            }

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('finalizeRemoval', $locked)) {
                throw CannotFinalizeRemovalException::becauseUserIsNotAllowed();
            }

            if (
                $locked->status !== CommentStatus::Hidden
                || $locked->moderation_removed_at !== null
            ) {
                throw CannotFinalizeRemovalException::becauseTargetStateIsInvalid();
            }

            $locked->forceFill([
                'moderation_removed_at' => now(),
            ])->save();

            $this->createModerationLog->handle(
                moderator: $lockedActor,
                action: ModerationActionType::FinalizeCommentRemoval,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_state' => 'hidden',
                    'to_state' => 'removal_finalized',
                    'moderation_removed_at' => $locked->moderation_removed_at->toIso8601String(),
                ],
            );

            $comment->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
