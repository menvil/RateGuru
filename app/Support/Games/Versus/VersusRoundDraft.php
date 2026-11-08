<?php

namespace App\Support\Games\Versus;

use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;

/** A versus round as it stands in a draft: its question, mode and two options. */
final readonly class VersusRoundDraft
{
    /**
     * @param  list<VersusOptionDraft>  $options  A first, B second
     */
    public function __construct(
        public ?string $question,
        public GameRoundMode $mode,
        public bool $unscored,
        public array $options,
    ) {}

    /**
     * The mode the round really plays in: an uploaded picture has no site
     * votes, so a round with one is decided by its correct answer.
     */
    public function effectiveMode(): GameRoundMode
    {
        return $this->hasUpload() ? GameRoundMode::Objective : $this->mode;
    }

    public function hasUpload(): bool
    {
        foreach ($this->options as $option) {
            if ($option->source === GameOptionSource::Upload) {
                return true;
            }
        }

        return false;
    }
}
