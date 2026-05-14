<?php

namespace App\Models;

use App\Enums\VoteType;
use Illuminate\Database\Eloquent\Model;

class PostVote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => VoteType::class,
        ];
    }
}
