<?php

namespace App\Support\StudioAi;

use App\Enums\StudioImageAnalysisStatus;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;

/**
 * What post text suggestions are written from: a plan's photo, and the
 * stored analysis that describes it — read again through
 * StudioImageDescription::from(), never trusted as stored — with its hash;
 * and the voice of the post's author (StudioPostTextAuthor), when it has
 * one. A batch keeps sourceHash(), all of it in one value, to tell whether it
 * still fits.
 *
 * There is a context only while the plan has a photo and its analysis row
 * is about that photo and holds a valid description. `complete` says that
 * description is the analysis's finished last word: a re-run on its way
 * keeps the description it is replacing, which still tells current
 * suggestions apart, but nothing new is written from it.
 */
final readonly class StudioPostTextContext
{
    private function __construct(
        public int $imageAssetId,
        public StudioImageDescription $description,
        public string $analysisHash,
        public bool $complete,
        public ?StudioPostTextAuthor $author,
    ) {}

    /** The context of a plan with this photo, this analysis row and this author's voice; none when they make none. */
    public static function of(?int $imageAssetId, ?StudioPlanImageAnalysis $analysis, ?StudioPostTextAuthor $author = null): ?self
    {
        if ($imageAssetId === null || $analysis === null || ! $analysis->isFor($imageAssetId) || ! is_array($analysis->result)) {
            return null;
        }

        $description = StudioImageDescription::from($analysis->result);

        if ($description === null) {
            return null;
        }

        return new self($imageAssetId, $description, $description->hash(), $analysis->status === StudioImageAnalysisStatus::Completed, $author);
    }

    /** The context of the plan as it is now: its photo, this analysis row of it, and its author's voice as the author's profile has it now. */
    public static function ofPlan(StudioPlan $plan, ?StudioPlanImageAnalysis $analysis): ?self
    {
        $author = $plan->author_user_id !== null
            ? User::query()->with('personaProfile')->find($plan->author_user_id)
            : null;

        return self::of($plan->image_asset_id, $analysis, StudioPostTextAuthor::of($author));
    }

    /**
     * What a batch is written from, in one value: the analysis's hash alone
     * when the post has no author's voice — as a batch written from the
     * photo alone always was — and otherwise one hash of the analysis's and
     * everything the model is told of the author. A change of either changes
     * it, and nothing else does.
     */
    public function sourceHash(): string
    {
        return self::sourceHashOf($this->analysisHash, $this->author);
    }

    /** What a batch written from an analysis with this hash, in this author's voice or none, is written from (sourceHash()). */
    public static function sourceHashOf(string $analysisHash, ?StudioPostTextAuthor $author): string
    {
        if ($author === null) {
            return $analysisHash;
        }

        return hash('sha256', json_encode([$analysisHash, $author->fingerprint()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
