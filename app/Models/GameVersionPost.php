<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post a published version shows, by id: written once when the version is
 * published, never a hold on the post. A post that leaves the site — hidden,
 * deleted, purged — makes every version listing it unplayable.
 *
 * @property int $version_id
 * @property int $post_id
 */
class GameVersionPost extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'post_id';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version_id' => 'integer',
            'post_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
