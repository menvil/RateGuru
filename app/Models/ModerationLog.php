<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationLog extends Model
{
    protected $fillable = ['moderator_id', 'action', 'target_type', 'target_id', 'reason', 'metadata'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
