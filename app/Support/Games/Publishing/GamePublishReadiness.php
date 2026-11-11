<?php

namespace App\Support\Games\Publishing;

use App\Enums\GameFlowStepType;
use App\Models\Game;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Flow\FlowIssue;
use App\Support\Games\Flow\GameFlowValidator;

/**
 * Whether a content set played through a flow can be published as a version
 * of its game, line by line: the set's rounds, its result profiles, the flow.
 * Publishing is refused unless every line passes; the editor shows the same
 * lines.
 */
final readonly class GamePublishReadiness
{
    /**
     * @param  list<ReadinessLine>  $lines
     * @param  list<FlowIssue>  $flowIssues
     */
    private function __construct(
        public array $lines,
        public array $flowIssues,
    ) {}

    /**
     * @param  list<int>  $existingGameIds  the games a Next Game step may name
     */
    public static function of(Game $game, ContentSetReport $content, GameFlow $flow, array $existingGameIds): self
    {
        $lines = [];
        $set = $content->set;

        if ($set->isArchived()) {
            $lines[] = new ReadinessLine('content', false, "The content set “{$set->name}” is archived. Restore it or choose another.");
        }

        if ($content->setIssues !== []) {
            foreach ($content->setIssues as $issue) {
                $lines[] = new ReadinessLine('content', false, $issue);
            }
        } else {
            $total = count($content->rounds);
            $broken = $content->roundsWithIssues();
            $lines[] = $broken === 0
                ? new ReadinessLine('content', true, $total === 1 ? '1 round ready.' : "{$total} rounds ready.")
                : new ReadinessLine('content', false, "{$broken} of {$total} rounds need attention in Content.");
        }

        if ($content->rounds !== []) {
            $lines[] = $content->profileIssues === []
                ? new ReadinessLine('results', true, 'Every result has a profile.')
                : new ReadinessLine('results', false, "Result profiles don't cover every result from 0 to {$content->scoredRounds} matches. Fix them in Content → Results.");
        }

        if ($flow->isArchived()) {
            $lines[] = new ReadinessLine('flow', false, "The flow “{$flow->name}” is archived. Restore it or choose another.");
        }

        $steps = $flow->steps->map(fn (GameFlowStep $step) => $step->draft())->values()->all();
        $flowIssues = GameFlowValidator::issues($steps);

        foreach ($flow->steps as $index => $step) {
            if ($step->type !== GameFlowStepType::NextGame) {
                continue;
            }

            $next = $step->settings['next_game_id'] ?? null;

            if ($next !== null && ((int) $next === (int) $game->id || ! in_array((int) $next, $existingGameIds, true))) {
                $flowIssues[] = new FlowIssue($index, 'The next game must be another game of this site.');
            }
        }

        $lines[] = $flowIssues === []
            ? new ReadinessLine('flow', true, 'Flow is valid: '.implode(' → ', $flow->steps->map(fn (GameFlowStep $step): string => $step->type->label())->all()).'.')
            : new ReadinessLine('flow', false, count($flowIssues) === 1 ? 'The flow has 1 problem. Fix it in Flow.' : 'The flow has '.count($flowIssues).' problems. Fix it in Flow.');

        return new self($lines, $flowIssues);
    }

    public function isReady(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->ok) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return array_values(array_map(
            fn (ReadinessLine $line): string => $line->text,
            array_filter($this->lines, fn (ReadinessLine $line): bool => ! $line->ok),
        ));
    }
}
