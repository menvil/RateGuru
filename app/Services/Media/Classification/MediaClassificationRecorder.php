<?php

namespace App\Services\Media\Classification;

use App\Enums\ClassificationMode;
use App\Enums\ClassificationStatus;
use App\Enums\ContentCategory;
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
            // A classifier saying it has no answer is expected; anything
            // else is a bug in it, and goes where bugs go — by its class and
            // place, without the message a provider's answer may fill.
            if (! $exception instanceof MediaClassificationFailedException) {
                report(MediaClassifierCrashedException::from($classifier->name(), $exception));
            }

            return MediaClassification::query()->create([
                'media_asset_id' => $asset->id,
                'classifier' => self::bounded($classifier->name()),
                'model_version' => self::bounded($classifier->modelVersion()),
                'mode' => $mode,
                'status' => ClassificationStatus::Failed,
                'inputs' => $inputs,
                'latency_ms' => self::millisecondsSince($started),
                'error' => mb_substr($exception instanceof MediaClassificationFailedException ? $exception->getMessage() : self::reasonFor($exception), 0, 500),
            ]);
        }

        return MediaClassification::query()->create([
            'media_asset_id' => $asset->id,
            'classifier' => self::bounded($classifier->name()),
            'model_version' => self::bounded($classifier->modelVersion()),
            'mode' => $mode,
            'status' => ClassificationStatus::Succeeded,
            'inputs' => $inputs,
            'scores' => self::scores($result->scores),
            'frame_scores' => $result->frameScores === [] ? null : array_map(
                fn (array $frame): array => ['at_ms' => $frame['at_ms'], 'scores' => self::scores($frame['scores'])],
                $result->frameScores,
            ),
            'raw' => self::trimmed($result->raw),
            'latency_ms' => $result->latencyMs ?? self::millisecondsSince($started),
            'cost_microusd' => $result->costMicroUsd,
        ]);
    }

    /**
     * That a classifier could not be asked at all — nothing could be taken
     * from the media to show it — recorded as its answer would be, so the
     * asset is not queued for it again unless failures are asked for.
     */
    public function unaskable(MediaAsset $asset, MediaContentClassifier $classifier, ClassificationMode $mode, MediaClassificationFailedException $reason): MediaClassification
    {
        return MediaClassification::query()->create([
            'media_asset_id' => $asset->id,
            'classifier' => self::bounded($classifier->name()),
            'model_version' => self::bounded($classifier->modelVersion()),
            'mode' => $mode,
            'status' => ClassificationStatus::Failed,
            'inputs' => ['frames' => []],
            'error' => mb_substr($reason->getMessage(), 0, 500),
        ]);
    }

    /**
     * Only the categories the project keeps, each a finite score in 0..1:
     * whatever a provider adds, or sends as NaN, never reaches the column.
     *
     * @param  array<string, float>  $scores
     * @return array<string, float>
     */
    private static function scores(array $scores): array
    {
        $kept = [];

        foreach (ContentCategory::cases() as $category) {
            $score = $scores[$category->value] ?? null;

            if (is_numeric($score) && is_finite((float) $score)) {
                $kept[$category->value] = max(0.0, min(1.0, (float) $score));
            }
        }

        return $kept;
    }

    /**
     * The provider's answer as JSON can hold it — malformed UTF-8 replaced —
     * while it is small; otherwise only that there was one.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function trimmed(array $raw): ?array
    {
        if ($raw === []) {
            return null;
        }

        // Any failure to encode — malformed numbers, or a JsonSerializable
        // in it that throws — leaves a note, never an unrecorded answer.
        try {
            $json = json_encode($raw, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable) {
            return ['unencodable' => true];
        }

        if (strlen($json) > self::RAW_LIMIT_BYTES) {
            return ['truncated' => true, 'bytes' => strlen($json)];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['unencodable' => true];
    }

    /**
     * An exception other than a classifier's own "no answer", without what
     * its message may carry: its class — an anonymous one's parent, whose
     * name holds no path. A classifier's own exception names no file and
     * holds no key, so its message is kept as it is.
     */
    public static function reasonFor(Throwable $exception): string
    {
        $class = $exception::class;

        return str_contains($class, '@anonymous') ? (get_parent_class($exception) ?: 'anonymous') : $class;
    }

    /** A name or version as its column holds it: at most 64 characters. */
    private static function bounded(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 64);
    }

    private static function millisecondsSince(int|float $started): int
    {
        return (int) intdiv((int) (hrtime(true) - $started), 1_000_000);
    }
}
