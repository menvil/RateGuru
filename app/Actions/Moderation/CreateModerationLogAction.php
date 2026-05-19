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
        throw new \LogicException('Not implemented yet.');
    }
}
