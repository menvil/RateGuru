<?php

namespace App\Models;

use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A publication plan's photo analysis: the one current row of a plan, about
 * the photo `source_image_asset_id` names.
 *
 * The row says two things. Its status, attempt id, requested_at, started_at
 * and error code are the latest attempt's. Its result — a validated
 * StudioImageDescription — and the model, request id, tokens, latency and
 * finished_at beside it are the last result for that photo, kept through a
 * failed attempt after it and cleared when an attempt is made for another
 * photo. A row about a photo the plan no longer has describes nothing
 * current: the photo is simply not analysed yet.
 *
 * Written only by the photo analysis actions (app/Actions/Studio); nothing
 * is fillable.
 *
 * @property int $id
 * @property int $studio_plan_id
 * @property int $source_image_asset_id
 * @property StudioImageAnalysisStatus $status
 * @property string $attempt_id
 * @property StudioImageAnalysisError|null $error_code
 * @property array<string, mixed>|null $result
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
class StudioPlanImageAnalysis extends Model
{
    /** @use HasFactory<StudioPlanImageAnalysisFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'studio_plan_id' => 'integer',
            'source_image_asset_id' => 'integer',
            'status' => StudioImageAnalysisStatus::class,
            'error_code' => StudioImageAnalysisError::class,
            'result' => 'array',
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

    /** Whether this row is about that photo. */
    public function isFor(?int $imageAssetId): bool
    {
        return $imageAssetId !== null && $this->source_image_asset_id === $imageAssetId;
    }

    /**
     * Whether the latest attempt is still waiting or running, yet has done so
     * for longer than any live one could: its worker died, or no worker took
     * it. Such an attempt may be started again.
     */
    public function isStale(CarbonInterface $now): bool
    {
        $since = match ($this->status) {
            StudioImageAnalysisStatus::Queued => $this->requested_at,
            StudioImageAnalysisStatus::Running => $this->started_at ?? $this->requested_at,
            default => null,
        };

        return $since !== null && $since->lte($now->copy()->subSeconds(self::staleAfterSeconds()));
    }

    /** Whether the latest attempt is on its way: in progress, and not stale. */
    public function isUnderway(CarbonInterface $now): bool
    {
        return $this->status->isInProgress() && ! $this->isStale($now);
    }

    private static function staleAfterSeconds(): int
    {
        $seconds = config('content_studio.image_analysis.stale_after_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 180;
    }
}
