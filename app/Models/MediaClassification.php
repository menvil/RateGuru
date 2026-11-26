<?php

namespace App\Models;

use App\Enums\ClassificationMode;
use App\Enums\ClassificationStatus;
use Database\Factories\MediaClassificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One classifier's answer about one asset: what it was shown, its score per
 * content category (and per frame, for a video), how long it took and what
 * it cost — or why it failed. An asset's rating is decided from these.
 *
 * @property int $id
 * @property int $media_asset_id
 * @property string $classifier
 * @property string|null $model_version
 * @property ClassificationMode $mode
 * @property ClassificationStatus $status
 * @property array<string, mixed>|null $inputs
 * @property array<string, float>|null $scores
 * @property list<array<string, mixed>>|null $frame_scores
 * @property array<string, mixed>|null $raw
 * @property int|null $latency_ms
 * @property int|null $cost_microusd
 * @property string|null $error
 */
class MediaClassification extends Model
{
    /** @use HasFactory<MediaClassificationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mode' => ClassificationMode::class,
            'status' => ClassificationStatus::class,
            'inputs' => 'array',
            'scores' => 'array',
            'frame_scores' => 'array',
            'raw' => 'array',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
