<?php

namespace App\Data\Games;

use App\Models\Game;

/** What the General tab edits: a game's public identity. */
final readonly class GameGeneralData
{
    public function __construct(
        public string $title = '',
        public string $internalName = '',
        public string $slug = '',
        public string $description = '',
        public string $mechanic = 'versus',
    ) {}

    public static function of(Game $game): self
    {
        return new self(
            title: $game->title,
            internalName: $game->internal_name,
            slug: $game->slug,
            description: (string) $game->description,
            mechanic: $game->mechanic->value,
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'internalName' => $this->internalName,
            'slug' => $this->slug,
            'description' => $this->description,
            'mechanic' => $this->mechanic,
        ];
    }
}
