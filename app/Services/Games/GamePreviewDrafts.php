<?php

namespace App\Services\Games;

use App\Data\Games\ContentSetDraft;
use App\Data\Games\GameFlowDraft;
use Illuminate\Support\Facades\Cache;

/**
 * A Content tab's unsaved content set, handed to its live preview with the
 * pictures chosen for its options that are not stored yet — or a Flow tab's
 * unsaved steps, handed to its step preview. The tab puts the
 * draft as it stands under a stamp made from the draft itself; the preview
 * frame asks for it by that stamp, for the same administrator and set only. A stamp names one draft for good, so a frame never reads edits
 * made after it was drawn. Nothing is stored anywhere else, and a draft
 * left alone expires.
 */
final class GamePreviewDrafts
{
    private const MINUTES = 30;

    /**
     * Hands the draft over, with the pictures chosen for its options but not
     * stored yet, and returns the stamp the preview asks for them by.
     *
     * @param  array<string, array{src: string, width: int|null, height: int|null}>  $pictures  by "round index:option position"
     */
    public function put(int $userId, int $setId, ContentSetDraft $draft, array $pictures = []): string
    {
        $payload = $draft->toArray();
        $stamp = substr(hash('sha256', $setId.':'.json_encode([$payload, $pictures], JSON_THROW_ON_ERROR)), 0, 32);

        Cache::put($this->key($userId, $stamp), ['set' => $setId, 'draft' => $payload, 'pictures' => $pictures], now()->addMinutes(self::MINUTES));

        return $stamp;
    }

    /**
     * The pictures chosen for the draft's options but not stored yet, by
     * "round index:option position", when the stamp is this administrator's.
     *
     * @return array<string, array{src: string, width: int|null, height: int|null}>
     */
    public function pictures(int $userId, int $setId, string $stamp): array
    {
        $stored = preg_match('/\A[0-9a-f]{32}\z/', $stamp) === 1 ? Cache::get($this->key($userId, $stamp)) : null;

        if (! is_array($stored) || ($stored['set'] ?? null) !== $setId || ! is_array($stored['pictures'] ?? null)) {
            return [];
        }

        $pictures = [];

        foreach ($stored['pictures'] as $place => $picture) {
            if (is_string($place) && is_array($picture) && is_string($picture['src'] ?? null)) {
                $pictures[$place] = [
                    'src' => $picture['src'],
                    'width' => is_int($picture['width'] ?? null) ? $picture['width'] : null,
                    'height' => is_int($picture['height'] ?? null) ? $picture['height'] : null,
                ];
            }
        }

        return $pictures;
    }

    /** The draft the stamp names, if it is this administrator's, for this set, and still there. */
    public function get(int $userId, int $setId, string $stamp): ?ContentSetDraft
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $stamp) !== 1) {
            return null;
        }

        $stored = Cache::get($this->key($userId, $stamp));

        if (! is_array($stored) || ($stored['set'] ?? null) !== $setId || ! is_array($stored['draft'] ?? null)) {
            return null;
        }

        $draft = $stored['draft'];
        $scoring = is_array($draft['scoring'] ?? null) ? $draft['scoring'] : [];

        return new ContentSetDraft(
            scoring: [
                'points_per_round' => (int) ($scoring['points_per_round'] ?? 1),
                'crowd_min_votes' => (int) ($scoring['crowd_min_votes'] ?? config('games.crowd.min_votes')),
                'crowd_min_gap' => (int) ($scoring['crowd_min_gap'] ?? config('games.crowd.min_gap')),
            ],
            rounds: $this->rows($draft['rounds'] ?? null),
            profiles: $this->rows($draft['profiles'] ?? null),
        );
    }

    /** Hands a flow's unsaved steps over and returns the stamp the preview asks for them by. */
    public function putFlow(int $userId, int $flowId, GameFlowDraft $draft): string
    {
        $stamp = substr(hash('sha256', 'flow:'.$flowId.':'.json_encode($draft->steps, JSON_THROW_ON_ERROR)), 0, 32);

        Cache::put($this->key($userId, $stamp), ['flow' => $flowId, 'steps' => $draft->steps], now()->addMinutes(self::MINUTES));

        return $stamp;
    }

    /** The flow's steps the stamp names, if they are this administrator's, for this flow, and still there. */
    public function getFlow(int $userId, int $flowId, string $stamp): ?GameFlowDraft
    {
        $stored = preg_match('/\A[0-9a-f]{32}\z/', $stamp) === 1 ? Cache::get($this->key($userId, $stamp)) : null;

        if (! is_array($stored) || ($stored['flow'] ?? null) !== $flowId) {
            return null;
        }

        return new GameFlowDraft($this->rows($stored['steps'] ?? null));
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    private function key(int $userId, string $stamp): string
    {
        return 'games.preview-draft.'.$userId.'.'.$stamp;
    }
}
