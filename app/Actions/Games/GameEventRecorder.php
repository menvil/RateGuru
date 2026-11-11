<?php

namespace App\Actions\Games;

use App\Enums\GameEventType;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Support\Games\Play\GameVisitor;

/**
 * Writes a game's tracked moments (game_events), called by the actions that
 * make them, inside their own transactions: an event exists only if what it
 * records does. Ids and codes only — never anything a visitor typed.
 */
final class GameEventRecorder
{
    /**
     * @param  array{step_type?: string|null, round_index?: int|null, provider?: string|null}  $detail
     */
    public function record(
        GameEventType $type,
        int $gameId,
        GameVisitor $visitor,
        ?int $versionId = null,
        ?GameAttempt $attempt = null,
        ?GameAssignment $assignment = null,
        array $detail = [],
    ): void {
        $versionId ??= $attempt !== null ? $attempt->version_id : $assignment?->version_id;
        $assignmentId = $assignment !== null ? $assignment->id : $attempt?->assignment_id;

        $event = new GameEvent;
        $event->forceFill([
            'game_id' => $gameId,
            'version_id' => $versionId,
            'attempt_id' => $attempt?->id,
            'assignment_id' => $assignmentId,
            // An attempt keeps the audience it started in.
            'audience' => $attempt !== null ? $attempt->audience : $visitor->audience,
            'user_id' => $visitor->userId ?? $attempt?->user_id,
            'visitor_hash' => $visitor->visitorHash ?? $attempt?->visitor_hash,
            'type' => $type,
            'step_type' => $detail['step_type'] ?? null,
            'round_index' => $detail['round_index'] ?? null,
            'provider' => $detail['provider'] ?? null,
            'occurred_at' => now(),
        ])->save();
    }
}
