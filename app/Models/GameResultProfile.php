<?php

namespace App\Models;

use Database\Factories\GameResultProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a player is told they are, by how many scored rounds they matched.
 * The key is stable within its set and copied into a version, where the
 * address of a shared result names it.
 *
 * @property int $id
 * @property int $content_set_id
 * @property int $position
 * @property string $key
 * @property string $name
 * @property array<string, string>|null $name_translations
 * @property string|null $description
 * @property array<string, string>|null $description_translations
 * @property int $min_matches
 * @property int $max_matches
 * @property string $icon
 * @property string|null $share_text
 * @property array<string, string>|null $share_text_translations
 * @property int|null $share_asset_id
 */
class GameResultProfile extends Model
{
    /** @use HasFactory<GameResultProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id', 'content_set_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content_set_id' => 'integer',
            'position' => 'integer',
            'name_translations' => 'array',
            'description_translations' => 'array',
            'min_matches' => 'integer',
            'max_matches' => 'integer',
            'share_text_translations' => 'array',
            'share_asset_id' => 'integer',
        ];
    }

    /** @return BelongsTo<GameContentSet, $this> */
    public function contentSet(): BelongsTo
    {
        return $this->belongsTo(GameContentSet::class, 'content_set_id');
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function shareAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'share_asset_id')->withTrashed();
    }
}
