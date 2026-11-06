<?php

namespace App\Models;

use App\Enums\VoteType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property VoteType $type */
class PostVote extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => VoteType::class,
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
