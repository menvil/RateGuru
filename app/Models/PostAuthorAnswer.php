<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostAuthorAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'rating_group_id',
        'rating_option_id',
    ];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<RatingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(RatingGroup::class, 'rating_group_id');
    }

    /** @return BelongsTo<RatingOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(RatingOption::class, 'rating_option_id');
    }
}
