<?php

namespace App\Filament\Resources\Games\Concerns;

use App\Data\Games\GameGeneralData;
use App\Support\Games\GameGeneralRules;

/**
 * The General tab's form, on a new game and on a game's editor: the public
 * title, the internal name, the slug — made from the title until it is typed
 * — and the description. What is typed is a draft until Save in the top bar:
 * the browser compares it with `saved` to count what is unsaved, and Discard
 * puts the saved values back.
 */
trait EditsGameGeneral
{
    public string $gameTitle = '';

    public string $internalName = '';

    public string $gameSlug = '';

    public string $gameDescription = '';

    /** Whether the slug was typed, so the title stops making it. */
    public bool $slugTyped = false;

    /** @var array<string, string> the values as saved, for the browser's count of what is unsaved */
    public array $saved = [];

    public function updatedGameTitle(): void
    {
        if (! $this->slugTyped && $this->slugIsFree()) {
            $this->gameSlug = GameGeneralRules::slugFrom($this->gameTitle);
        }
    }

    public function updatedGameSlug(): void
    {
        $this->slugTyped = true;
    }

    /** Whether the slug may still change: always, until a version has been published. */
    protected function slugIsFree(): bool
    {
        return true;
    }

    protected function fillGeneral(GameGeneralData $data): void
    {
        $this->gameTitle = $data->title;
        $this->internalName = $data->internalName;
        $this->gameSlug = $data->slug;
        $this->gameDescription = $data->description;
        $this->saved = [
            'gameTitle' => $data->title,
            'internalName' => $data->internalName,
            'gameSlug' => $data->slug,
            'gameDescription' => $data->description,
        ];
    }

    protected function generalData(): GameGeneralData
    {
        return new GameGeneralData(
            title: $this->gameTitle,
            internalName: $this->internalName,
            slug: $this->gameSlug,
            description: $this->gameDescription,
        );
    }

    /** @return array<string, int> */
    protected function generalLimits(): array
    {
        return [
            'title' => GameGeneralRules::TITLE_MAX,
            'description' => GameGeneralRules::DESCRIPTION_MAX,
            'slug' => GameGeneralRules::SLUG_MAX,
        ];
    }
}
