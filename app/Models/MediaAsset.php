<?php

namespace App\Models;

use App\Enums\ImageOrientation;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master identity for an uploaded media file (a post image or a user
 * avatar). Canonical identity is disk + path; no absolute/canonical URL is
 * stored here — see docs/architecture/media.md.
 *
 * @property MediaKind $kind
 * @property MediaType $media_type
 * @property int|null $duration_ms
 * @property bool $has_audio
 * @property array<string, mixed>|null $metadata
 * @property MediaStatus $status
 * @property MediaVisibility $visibility
 * @property string $disk
 * @property string $path
 */
class MediaAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'media_type' => MediaType::class,
            'duration_ms' => 'integer',
            'has_audio' => 'boolean',
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

    /**
     * Videos and GIFs still Processing that arrived at $cutoff or before:
     * past every retry a job that started at once could make — delayed, or
     * lost. Media Diagnostics counts them; media:sweep-videos fails the
     * ones a day old.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeVideosProcessingSince(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereIn('media_type', [MediaType::Video, MediaType::Animation])
            ->where('status', MediaStatus::Processing)
            ->where('created_at', '<=', $cutoff);
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

    /**
     * The published game versions that show the asset as an upload and so
     * keep it (MediaReferenceChecker).
     *
     * @return BelongsToMany<GameVersion, $this>
     */
    public function gameVersions(): BelongsToMany
    {
        return $this->belongsToMany(GameVersion::class, 'game_version_assets', 'media_asset_id', 'version_id');
    }
}
