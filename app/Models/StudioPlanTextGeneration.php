<?php

namespace App\Models;

use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Enums\StudioPostTextTone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\StudioPlanTextGenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch of post text suggestions a publication plan asked for: its
 * tone, what it was written from — the photo (`source_image_asset_id`), and
 * its analysis with the voice of the post's author in one hash
 * (`source_analysis_hash`, StudioPostTextContext::sourceHash(): the
 * analysis's own hash when the post had no author's voice) — its state, and,
 * once completed, its titles and descriptions with the call that wrote them.
 *
 * A batch written from a photo, an analysis or an author's voice the plan no
 * longer has is kept, as history, and suggests nothing for the plan as it is
 * now (isFor()). Written only by the post text actions (app/Actions/Studio);
 * nothing is fillable.
 *
 * @property int $id
 * @property int $studio_plan_id
 * @property int $source_image_asset_id
 * @property string $source_analysis_hash
 * @property StudioPostTextTone $tone
 * @property StudioPostTextGenerationStatus $status
 * @property string $attempt_id
 * @property StudioPostTextGenerationError|null $error_code
 * @property list<string>|null $titles
 * @property list<string>|null $descriptions
 * @property string|null $model
 * @property string|null $request_id
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $total_tokens
 * @property int|null $latency_ms
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
class StudioPlanTextGeneration extends Model
{
    /** @use HasFactory<StudioPlanTextGenerationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'studio_plan_id' => 'integer',
            'source_image_asset_id' => 'integer',
            'tone' => StudioPostTextTone::class,
            'status' => StudioPostTextGenerationStatus::class,
            'error_code' => StudioPostTextGenerationError::class,
            'titles' => 'array',
            'descriptions' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StudioPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudioPlan::class, 'studio_plan_id');
    }

    /** Whether this batch was written from that photo, and from what that hash says (StudioPostTextContext::sourceHash()). */
    public function isFor(?int $imageAssetId, ?string $sourceHash): bool
    {
        return $imageAssetId !== null && $sourceHash !== null
            && $this->source_image_asset_id === $imageAssetId
            && hash_equals($this->source_analysis_hash, $sourceHash);
    }

    /**
     * Whether the batch is still waiting or running, yet has done so for
     * longer than any live one could: its worker died, or no worker took it.
     * Another may be asked for in its place.
     */
    public function isStale(CarbonInterface $now): bool
    {
        $since = match ($this->status) {
            StudioPostTextGenerationStatus::Queued => $this->requested_at,
            StudioPostTextGenerationStatus::Running => $this->started_at ?? $this->requested_at,
            default => null,
        };

        return $since !== null && $since->lte($now->copy()->subSeconds(self::staleAfterSeconds()));
    }

    /** Whether the batch is on its way: in progress, and not stale. */
    public function isUnderway(CarbonInterface $now): bool
    {
        return $this->status->isInProgress() && ! $this->isStale($now);
    }

    private static function staleAfterSeconds(): int
    {
        $seconds = config('content_studio.post_text_generation.stale_after_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 180;
    }
}
