<?php

namespace App\Models;

use App\Enums\ImageOrientation;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master identity for an uploaded media file (a post image or a user
 * avatar). Canonical identity is disk + path; no absolute/canonical URL is
 * stored here — see docs/architecture/media.md.
 */
class MediaAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'status' => MediaStatus::class,
            'visibility' => MediaVisibility::class,
            'orientation' => ImageOrientation::class,
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'aspect_ratio' => 'float',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<MediaVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }
}
