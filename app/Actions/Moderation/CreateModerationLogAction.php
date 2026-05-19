<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Models\ModerationLog;
use App\Models\User;
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
        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        return ModerationLog::create([
            'moderator_id' => $moderator->id,
            'action' => $action,
            'target_type' => $target::class,
            'target_id' => $target->id,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
