<?php

namespace App\Models;

use App\Enums\GameOptionSource;
use Database\Factories\GameRoundOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice of a round, in its slot (A is 0): a published post's picture or
 * an uploaded one. A post option loses its post if the post is purged; the
 * round then asks for another.
 *
 * @property int $id
 * @property int $round_id
 * @property int $position
 * @property GameOptionSource $source
 * @property int|null $post_id
 * @property int|null $asset_id
 * @property string|null $label
 * @property array<string, string>|null $label_translations
 * @property bool $is_correct
 */
class GameRoundOption extends Model
{
    /** @use HasFactory<GameRoundOptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id', 'round_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'source' => 'post',
        'is_correct' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'round_id' => 'integer',
            'position' => 'integer',
            'source' => GameOptionSource::class,
            'post_id' => 'integer',
            'asset_id' => 'integer',
            'label_translations' => 'array',
            'is_correct' => 'boolean',
        ];
    }

    /** @return BelongsTo<GameRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(GameRound::class, 'round_id');
    }

    /**
     * The post, deleted or not: a draft must be able to tell an editor that
     * its post has left the site.
     *
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withTrashed();
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'asset_id')->withTrashed();
    }
}
