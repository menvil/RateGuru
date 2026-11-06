<?php

namespace App\Models;

use App\Enums\ModerationActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ModerationActionType $action
 * @property string $target_type
 * @property int $target_id
 * @property string|null $reason
 */
class ModerationLog extends Model
{
    protected $fillable = ['moderator_id', 'action', 'target_type', 'target_id', 'reason', 'metadata'];

    protected function casts(): array
    {
        return [
            'action' => ModerationActionType::class,
            'metadata' => 'array',
        ];
    }

    /**
     * Who took the action.
     *
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }
}
