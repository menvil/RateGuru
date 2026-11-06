<?php

namespace App\Models;

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\StudioPlanDiscussionGenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One discussion generation a publication plan asked for
 * (docs/architecture/studio-plans.md, "Discussion generation"): a whole new
 * discussion, or one part of the plan's own written again; who asked; the
 * Personas taking part; what it was written from — the plan's version, the
 * photo and its analysis, the post's texts with the discussion as it was,
 * and the Personas' characters, each as a hash; its state; and, once
 * completed, the validated proposal with the call that wrote it.
 *
 * A proposal is never part of the plan's discussion by itself: an
 * administrator takes it in (ApplyStudioDiscussionGenerationAction), which
 * stamps `applied_at`. One written from a context the plan no longer has is
 * kept, as history, and can no longer be taken in. Written only by the
 * discussion generation actions (app/Actions/Studio); nothing is fillable,
 * and nothing of it reaches any public page or API.
 *
 * @property int $id
 * @property int $studio_plan_id
 * @property int $requested_by_user_id
 * @property StudioDiscussionGenerationMode $mode
 * @property int|null $target_node_id
 * @property list<int> $persona_user_ids
 * @property array<string, mixed> $parameters
 * @property int $source_lock_version
 * @property int $source_image_asset_id
 * @property string $source_analysis_hash
 * @property string $source_discussion_hash
 * @property string $source_personas_hash
 * @property StudioDiscussionGenerationStatus $status
 * @property string $attempt_id
 * @property StudioDiscussionGenerationError|null $error_code
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
 * @property CarbonImmutable|null $applied_at
 */
class StudioPlanDiscussionGeneration extends Model
{
    /** @use HasFactory<StudioPlanDiscussionGenerationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'studio_plan_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'mode' => StudioDiscussionGenerationMode::class,
            'target_node_id' => 'integer',
            'persona_user_ids' => 'array',
            'parameters' => 'array',
            'source_lock_version' => 'integer',
            'source_image_asset_id' => 'integer',
            'status' => StudioDiscussionGenerationStatus::class,
            'error_code' => StudioDiscussionGenerationError::class,
            'result' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StudioPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudioPlan::class, 'studio_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<StudioDiscussionNode, $this> */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(StudioDiscussionNode::class, 'target_node_id');
    }

    /**
     * The comment it writes again, by the id it was asked for — kept in the
     * parameters, so it is known even once the comment itself is deleted.
     */
    public function targetNodeId(): ?int
    {
        $id = $this->parameters['target_node_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * The whole tree of the plan taken in last — the one its discussion was
     * written as, unless it has been changed since — locked when asked.
     */
    public static function lastTreeTakenIn(int $planId, bool $lock = false): ?self
    {
        $query = self::query()
            ->where('studio_plan_id', $planId)
            ->where('mode', StudioDiscussionGenerationMode::FullDiscussion)
            ->whereNotNull('applied_at')
            ->orderByDesc('applied_at')
            ->orderByDesc('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Whether a whole tree was written for other texts of the post than these
     * (StudioDiscussionContext::textFingerprint()), and was not kept for
     * them. One asked for before the texts were kept with it says nothing.
     */
    public function writtenForOtherTexts(string $textFingerprint): bool
    {
        $source = $this->parameters['source_text_fingerprint'] ?? null;
        $kept = $this->parameters['kept_text_fingerprint'] ?? null;

        return is_string($source)
            && ! hash_equals($source, $textFingerprint)
            && ! (is_string($kept) && hash_equals($kept, $textFingerprint));
    }

    /** Whether it was written from this context: the photo, its analysis, the post with its discussion, and the Personas. */
    public function isFor(int $imageAssetId, string $analysisHash, string $discussionHash, string $personasHash): bool
    {
        return $this->source_image_asset_id === $imageAssetId
            && hash_equals($this->source_analysis_hash, $analysisHash)
            && hash_equals($this->source_discussion_hash, $discussionHash)
            && hash_equals($this->source_personas_hash, $personasHash);
    }

    /**
     * Whether the generation is still waiting or running, yet has done so for
     * longer than any live one could: its worker died, or no worker took it.
     * Another may be asked for in its place.
     */
    public function isStale(CarbonInterface $now): bool
    {
        $since = match ($this->status) {
            StudioDiscussionGenerationStatus::Queued => $this->requested_at,
            StudioDiscussionGenerationStatus::Running => $this->started_at ?? $this->requested_at,
            default => null,
        };

        return $since !== null && $since->lte($now->copy()->subSeconds(self::staleAfterSeconds()));
    }

    /** Whether the generation is on its way: in progress, and not stale. */
    public function isUnderway(CarbonInterface $now): bool
    {
        return $this->status->isInProgress() && ! $this->isStale($now);
    }

    private static function staleAfterSeconds(): int
    {
        $seconds = config('content_studio.discussion_generation.stale_after_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 180;
    }
}
