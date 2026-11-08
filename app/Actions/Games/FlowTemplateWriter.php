<?php

namespace App\Actions\Games;

use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Support\Games\Flow\GameFlowStepSettings;
use App\Support\Games\Flow\GameFlowTemplate;

/**
 * Writes a template's steps to a new flow, each with its type's fixed
 * audience, normalized settings and default texts; the landing hero takes the
 * game's title.
 */
final class FlowTemplateWriter
{
    public static function fromTemplate(GameFlow $flow, GameFlowTemplate $template, string $gameTitle): void
    {
        foreach ($template->steps() as $position => $step) {
            $type = $step['type'];
            $texts = $type->defaultTexts();

            if ($type === GameFlowStepType::Landing && trim($gameTitle) !== '') {
                $texts['title'] = $gameTitle;
            }

            $row = new GameFlowStep;
            $row->forceFill([
                'flow_id' => $flow->id,
                'position' => $position,
                'type' => $type,
                'audience' => $type->fixedAudience() ?? GameStepAudience::All,
                'settings' => GameFlowStepSettings::normalize($type, $step['settings']),
                ...$texts,
            ])->save();
        }
    }
}
