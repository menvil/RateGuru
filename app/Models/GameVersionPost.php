<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post a published version shows, by id: written once when the version is
 * published, never changed, never a hold on the post. A post that leaves the
 * site — hidden, deleted, purged — makes every version listing it unplayable.
 *
 * Keyed by its version and its post together: a row loaded, refreshed or
 * deleted is that one version's listing, never every version's listing of
 * the post.
 *
 * @property int $version_id
 * @property int $post_id
 */
class GameVersionPost extends Model
{
    public $timestamps = false;

    public $incrementing = false;

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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query): Builder
    {
        return $this->setKeysForSaveQuery($query);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query
            ->where('version_id', '=', $this->original['version_id'] ?? $this->getAttribute('version_id'))
            ->where('post_id', '=', $this->original['post_id'] ?? $this->getAttribute('post_id'));
    }
}
