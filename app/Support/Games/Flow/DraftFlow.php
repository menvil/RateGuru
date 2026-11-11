<?php

namespace App\Support\Games\Flow;

use App\Data\Games\GameFlowDraft;
use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use Illuminate\Database\Eloquent\Collection;

/**
 * A flow as an unsaved draft of it stands, for the Flow tab's step preview
 * alone: the saved flow with the draft's steps in place of its own, built in
 * memory so the preview's snapshot reads it as it reads a saved flow. A step
 * keeps its translations while it keeps its id; a new one stands in with an
 * id below zero, its key `s-` and its place. Nothing of it is ever saved,
 * and it queries nothing.
 */
final class DraftFlow
{
    public static function of(GameFlow $saved, GameFlowDraft $draft): GameFlow
    {
        $known = $saved->steps->keyBy('id');
        $steps = new Collection;

        foreach ($draft->steps as $index => $row) {
            $type = GameFlowStepType::tryFrom((string) ($row['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $savedStep = $id > 0 ? $known->get($id) : null;
            $step = $savedStep instanceof GameFlowStep ? clone $savedStep : new GameFlowStep;
            $texts = (array) ($row['texts'] ?? []);
            $attributes = [
                'id' => $savedStep instanceof GameFlowStep ? $id : -($index + 1),
                'flow_id' => $saved->id,
                'position' => $index,
                'type' => $type,
                'audience' => GameStepAudience::tryFrom((string) ($row['audience'] ?? '')) ?? GameStepAudience::All,
                'settings' => GameFlowStepSettings::normalize($type, (array) ($row['settings'] ?? [])),
            ];

            foreach (GameFlowStepType::TEXT_COLUMNS as $column) {
                $attributes[$column] = (string) ($texts[$column] ?? '');
            }

            $step->forceFill($attributes);
            $steps->push($step);
        }

        $flow = clone $saved;
        $flow->setRelation('steps', $steps);

        return $flow;
    }

    /**
     * The key a step of the draft has in a snapshot of it: its saved one, or
     * `s-` and its place for a new one.
     *
     * @param  array<string, mixed>  $step
     */
    public static function keyOf(array $step, int $index): string
    {
        return is_numeric($step['id'] ?? null) && (int) $step['id'] > 0 ? 's'.(int) $step['id'] : 's-'.($index + 1);
    }
}
