<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Models\ModerationLog;
use App\Models\User;
use App\Support\Moderation\ModerationReason;
use Illuminate\Database\Eloquent\Model;

final class CreateModerationLogAction
{
    public function handle(
        User $moderator,
        ModerationActionType $action,
        Model $target,
        ?string $reason = null,
        array $metadata = [],
    ): ModerationLog {
        // ModerationReason, not trim(): trim()'s character list includes "\0"
        // and excludes every Unicode blank, so the two disagreed about what an
        // empty reason is — and a NUL-only reason passed the finalizers' guard
        // and then became NULL here, which is an irreversible removal with no
        // audit reason at all. One definition, used by whoever writes and
        // whoever guards.
        $reason = $reason !== null ? ModerationReason::normalize($reason) : null;
        $reason = $reason === '' ? null : $reason;

        return ModerationLog::create([
            'moderator_id' => $moderator->id,
            'action' => $action,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
