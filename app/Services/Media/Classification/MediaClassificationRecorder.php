<?php

namespace App\Services\Media\Classification;

use App\Enums\ClassificationMode;
use App\Enums\ClassificationStatus;
use App\Models\MediaAsset;
use App\Models\MediaClassification;
use Throwable;

/**
 * Runs one classifier on one asset and records what came of it — its answer,
 * or that it failed and why — as a MediaClassification. It decides nothing
 * about the asset's rating, whichever mode it ran in.
 */
final class MediaClassificationRecorder
{
    /** The provider's own answer is kept to this many bytes of JSON at most. */
    private const int RAW_LIMIT_BYTES = 16_384;

    public function record(MediaAsset $asset, MediaContentClassifier $classifier, ClassificationInput $input, ClassificationMode $mode): MediaClassification
    {
        $inputs = ['frames' => array_map(fn (ClassificationFrame $frame): array => ['label' => $frame->label, 'at_ms' => $frame->atMs], $input->frames)];
        $started = hrtime(true);

        try {
            $result = $classifier->classify($input);
        } catch (Throwable $exception) {
            return MediaClassification::query()->create([
                'media_asset_id' => $asset->id,
                'classifier' => $classifier->name(),
                'model_version' => $classifier->modelVersion(),
                'mode' => $mode,
                'status' => ClassificationStatus::Failed,
                'inputs' => $inputs,
                'latency_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                // A classifier's exception names no file and holds no key;
                // anything else is kept to its class.
                'error' => mb_substr($exception instanceof MediaClassificationFailedException ? $exception->getMessage() : $exception::class, 0, 500),
            ]);
        }

        return MediaClassification::query()->create([
            'media_asset_id' => $asset->id,
            'classifier' => $classifier->name(),
            'model_version' => $classifier->modelVersion(),
            'mode' => $mode,
            'status' => ClassificationStatus::Succeeded,
            'inputs' => $inputs,
            'scores' => $result->scores,
            'frame_scores' => $result->frameScores === [] ? null : $result->frameScores,
            'raw' => self::trimmed($result->raw),
            'latency_ms' => $result->latencyMs ?? intdiv(hrtime(true) - $started, 1_000_000),
            'cost_microusd' => $result->costMicroUsd,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function trimmed(array $raw): ?array
    {
        if ($raw === []) {
            return null;
        }

        $json = (string) json_encode($raw);

        return strlen($json) <= self::RAW_LIMIT_BYTES ? $raw : ['truncated' => true, 'bytes' => strlen($json)];
    }
}
