<?php

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameVersion;
use App\Support\Locale\LocaleManager;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

/**
 * A game's Preview: the game as visitors will play it, in a phone-sized or a
 * desktop-sized frame, drawn by the site itself (the games.preview page) —
 * a draft's content set through one of its flows, or a published version; as
 * an anonymous visitor, one whose email is known, or a signed-in player; in
 * any installed language; from the landing, a round, or the result for a
 * number of matches. Nothing it does is recorded.
 */
final class PreviewGame extends Page
{
    use InteractsWithGameEditor;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-preview';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** `draft` or a version's id. */
    #[Url]
    public string $source = 'draft';

    #[Url]
    public int $set = 0;

    #[Url]
    public int $flow = 0;

    #[Url]
    public string $visitor = 'anonymous';

    #[Url]
    public string $locale = 'en';

    #[Url]
    public string $start = 'landing';

    #[Url]
    public string $device = 'mobile';

    public function mount(int|string $record): void
    {
        $game = $this->mountGame($record);
        $this->set = $this->set ?: (int) GameContentSet::query()->where('game_id', $game->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->value('id');
        $this->flow = $this->flow ?: (int) GameFlow::query()->where('game_id', $game->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->value('id');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $versions = GameVersion::query()->where('game_id', $game->id)->orderByDesc('number')->get();
        $params = ['game' => $game->id, 'visitor' => $this->visitor, 'locale' => $this->locale, 'start' => $this->start];
        $params += $this->source !== 'draft' && $versions->contains('id', (int) $this->source)
            ? ['version' => (int) $this->source]
            : ['set' => $this->set, 'flow' => $this->flow];

        $rounds = $this->source !== 'draft' && $versions->firstWhere('id', (int) $this->source) !== null
            ? (int) $versions->firstWhere('id', (int) $this->source)->round_count
            : GameContentSet::query()->find($this->set)?->rounds()->count() ?? 0;

        return [
            'header' => $this->editorHeader($game, 'preview'),
            'versions' => $versions->map(fn (GameVersion $version): array => ['id' => (int) $version->id, 'label' => $version->label()])->all(),
            'sets' => GameContentSet::query()->where('game_id', $game->id)->orderBy('position')->get(['id', 'name'])->all(),
            'flows' => GameFlow::query()->where('game_id', $game->id)->orderBy('position')->get(['id', 'name'])->all(),
            'locales' => collect(app(LocaleManager::class)->supported())->map(fn (array $locale, string $code): array => ['code' => $code, 'label' => $locale['label']])->values()->all(),
            'starts' => [
                'landing' => 'From the start',
                ...collect(range(0, max(0, $rounds - 1)))->mapWithKeys(fn (int $index): array => ['round:'.$index => 'Round '.($index + 1)])->all(),
                ...collect(range(0, $rounds))->mapWithKeys(fn (int $matches): array => ['result:'.$matches => 'Result with '.$matches.' '.($matches === 1 ? 'match' : 'matches')])->all(),
            ],
            'frameUrl' => route('games.preview', $params),
        ];
    }
}
