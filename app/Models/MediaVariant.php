<?php

namespace App\Models;

use App\Enums\MediaVariantName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A derived file produced from a MediaAsset — a responsive feed/detail size
 * (post images) or a square thumbnail (avatars). See docs/architecture/media.md.
 *
 * @property MediaVariantName $name
 */
class MediaVariant extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => MediaVariantName::class,
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
