<?php

namespace App\Filament\Resources\Games\Pages;

use App\Enums\GameStepAudience;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameVersion;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

/**
 * A game's Preview: the game as visitors will play it, drawn by the site
 * itself (the games.preview page) in an iPhone, in a browser window or across
 * the page — a draft's content set through one of its flows, or a published
 * version; as an anonymous visitor or a signed-in player; in any installed
 * language; light or dark. Journey lists the flow's
 * steps, marks the one on screen and starts the game again at any of them, or
 * at the result for a number of matches. Nothing it does is recorded.
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

    /** `anonymous` or `authenticated`. */
    #[Url]
    public string $visitor = 'anonymous';

    #[Url]
    public string $locale = 'en';

    #[Url]
    public string $start = 'landing';

    /** `mobile` in an iPhone, `desktop` in a browser window, or `page` across the page. */
    #[Url]
    public string $device = 'mobile';

    public function mount(int|string $record): void
    {
        $game = $this->mountGame($record);
        $this->visitor = $this->visitor === 'authenticated' ? 'authenticated' : 'anonymous';
        $this->set = $this->gameSetId((int) $game->id);
        $this->flow = $this->gameFlowId((int) $game->id);
    }

    /** The set the address names when it is this game's, or its first set not archived. */
    private function gameSetId(int $gameId): int
    {
        $sets = GameContentSet::query()->where('game_id', $gameId);

        return (clone $sets)->whereKey($this->set)->exists()
            ? $this->set
            : (int) $sets->whereNull('archived_at')->orderBy('position')->orderBy('id')->value('id');
    }

    /** The flow the address names when it is this game's, or its first flow not archived. */
    private function gameFlowId(int $gameId): int
    {
        $flows = GameFlow::query()->where('game_id', $gameId);

        return (clone $flows)->whereKey($this->flow)->exists()
            ? $this->flow
            : (int) $flows->whereNull('archived_at')->orderBy('position')->orderBy('id')->value('id');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $versions = GameVersion::query()->where('game_id', $game->id)->orderByDesc('number')->get();
        $version = $this->source !== 'draft' ? $versions->firstWhere('id', (int) $this->source) : null;
        // A set or flow the browser names is played only when it is this game's.
        $setId = $this->gameSetId((int) $game->id);
        $flowId = $this->gameFlowId((int) $game->id);
        $params = ['game' => $game->id, 'visitor' => $this->visitor, 'locale' => $this->locale, 'start' => $this->start, 'framed' => 1];
        $params += $version !== null ? ['version' => (int) $version->id] : ['set' => $setId, 'flow' => $flowId];

        $rounds = $version !== null
            ? (int) $version->round_count
            : GameContentSet::query()->where('game_id', $game->id)->find($setId)?->rounds()->count() ?? 0;

        // The steps the frame plays: the version's frozen ones, or the chosen flow's as they stand.
        $steps = $version !== null
            ? array_map(fn ($step): array => [$step->key, $step->type, $step->audience], $version->read()->steps)
            : (GameFlow::query()->where('game_id', $game->id)->with('steps')->find($flowId)?->steps->map(fn (GameFlowStep $step): array => [$step->key(), $step->type, $step->audience])->all() ?? []);

        return [
            'header' => $this->editorHeader($game, 'preview'),
            'versions' => $versions->map(fn (GameVersion $version): array => ['id' => (int) $version->id, 'label' => $version->label()])->all(),
            'sets' => GameContentSet::query()->where('game_id', $game->id)->orderBy('position')->get(['id', 'name'])->all(),
            'flows' => GameFlow::query()->where('game_id', $game->id)->orderBy('position')->get(['id', 'name'])->all(),
            'locales' => collect(app(LocaleManager::class)->supported())->map(fn (array $locale, string $code): array => ['code' => $code, 'label' => $locale['label']])->values()->all(),
            'journey' => array_map(fn (array $step): array => [
                'key' => $step[0],
                'label' => $step[1]->label(),
                'icon' => $step[1]->icon(),
                'audience' => $step[2] === GameStepAudience::All ? null : $step[2]->label(),
            ], $steps),
            'results' => collect(range(0, $rounds))->mapWithKeys(fn (int $matches): array => ['result:'.$matches => $matches.' of '.$rounds.' '.($rounds === 1 ? 'match' : 'matches')])->all(),
            'frameUrl' => route('games.preview', $params),
            'address' => preg_replace('#\Ahttps?://#', '', url('/games/'.$game->slug)),
            'tabTitle' => $game->title.' · '.app(ProjectSettingsManager::class)->current()->siteName(),
        ];
    }
}
