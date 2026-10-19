<?php

namespace App\Support\StudioAi;

use App\Enums\StudioImageAnalysisStatus;
use App\Models\StudioPlanImageAnalysis;

/**
 * What post text suggestions are written from: a plan's photo, and the
 * stored analysis that describes it — read again through
 * StudioImageDescription::from(), never trusted as stored — with its hash,
 * which a batch keeps to tell whether it still fits.
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
    ) {}

    /** The context of a plan with this photo and this analysis row; none when they make none. */
    public static function of(?int $imageAssetId, ?StudioPlanImageAnalysis $analysis): ?self
    {
        if ($imageAssetId === null || $analysis === null || ! $analysis->isFor($imageAssetId) || ! is_array($analysis->result)) {
            return null;
        }

        $description = StudioImageDescription::from($analysis->result);

        if ($description === null) {
            return null;
        }

        return new self($imageAssetId, $description, $description->hash(), $analysis->status === StudioImageAnalysisStatus::Completed);
    }
}
