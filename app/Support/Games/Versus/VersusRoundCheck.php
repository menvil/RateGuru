<?php

namespace App\Support\Games\Versus;

use App\Enums\GameCrowdState;
use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;

/**
 * Whether a versus round can be published, and if so how it scores. A round
 * is publishable when it has its question, both pictures can be shown, and —
 * unless it plays without points — its point is decided: by a clear crowd
 * pick (CrowdComparison) or by exactly one option marked correct.
 *
 * The messages are the administrator's, in the order an editor fixes them.
 */
final readonly class VersusRoundCheck
{
    /**
     * @param  list<string>  $issues
     */
    private function __construct(
        public array $issues,
        public GameRoundMode $mode,
        public bool $scored,
        public ?CrowdComparison $crowd,
        /** The option that earns the point, for a scored round that is publishable. */
        public ?int $answer,
        /**
         * The option that earns the point as far as the votes or the correct
         * mark decide it, whatever else the round still lacks — what a preview
         * of an unfinished draft plays by.
         */
        public ?int $decided = null,
    ) {}

    public static function of(VersusRoundDraft $round, int $minVotes, int $minGap): self
    {
        $issues = [];
        $mode = $round->effectiveMode();
        $scored = ! $round->unscored;

        if (trim((string) $round->question) === '') {
            $issues[] = 'Question missing';
        }

        foreach ($round->options as $position => $option) {
            $name = self::optionName($position);

            if ($option->source === GameOptionSource::Upload) {
                if ($option->assetId === null) {
                    $issues[] = "{$name} photo missing";

                    continue;
                }
            } elseif ($option->postId === null) {
                $issues[] = "{$name}: choose a post";

                continue;
            } elseif (! $option->postPublic) {
                $issues[] = "{$name}: the post is no longer public";

                continue;
            }

            if ($option->image === ImageState::Unavailable) {
                $issues[] = "{$name} image unavailable";
            } elseif ($option->image === ImageState::Processing) {
                $issues[] = "{$name} image still processing";
            }
        }

        $crowd = null;
        $answer = null;

        if ($mode === GameRoundMode::Crowd && count($round->options) === 2) {
            $crowd = CrowdComparison::of($round->options[0]->crowdSide(), $round->options[1]->crowdSide(), $minVotes, $minGap);

            if ($scored) {
                $crowdIssue = self::crowdIssue($crowd);

                if ($crowdIssue !== null) {
                    $issues[] = $crowdIssue;
                }

                $answer = $crowd->preferred;
            }
        } elseif ($mode === GameRoundMode::Objective && $scored) {
            $correct = array_keys(array_filter($round->options, fn (VersusOptionDraft $option): bool => $option->isCorrect));

            if ($correct === []) {
                $issues[] = 'Correct answer not set';
            } elseif (count($correct) > 1) {
                $issues[] = 'Mark only one correct answer';
            } else {
                $answer = $correct[0];
            }
        }

        return new self($issues, $mode, $scored, $crowd, $issues === [] ? $answer : null, $scored ? $answer : null);
    }

    public function isPublishable(): bool
    {
        return $this->issues === [];
    }

    public static function optionName(int $position): string
    {
        return 'Option '.chr(ord('A') + $position);
    }

    private static function crowdIssue(CrowdComparison $crowd): ?string
    {
        return match ($crowd->state) {
            GameCrowdState::Majority => null,
            // The options' own messages already say what is wrong.
            GameCrowdState::Ineligible => null,
            GameCrowdState::Missing => $crowd->a->postId !== null && $crowd->a->postId === $crowd->b->postId
                ? 'Both options show the same post'
                : null,
            GameCrowdState::Tie => 'Same upvote share · no crowd pick',
            GameCrowdState::Ambiguous => sprintf(
                'Too close to call: %s%% vs %s%% upvotes, the crowd pick needs a gap of %d points',
                number_format((float) $crowd->a->displayShare(), 1),
                number_format((float) $crowd->b->displayShare(), 1),
                $crowd->minGap,
            ),
            GameCrowdState::Insufficient => sprintf(
                '%s: only %d of %d site votes',
                self::optionName((int) $crowd->shortSide()),
                $crowd->shortSide() === 0 ? $crowd->a->votes() : $crowd->b->votes(),
                max(1, $crowd->minVotes),
            ),
        };
    }
}
