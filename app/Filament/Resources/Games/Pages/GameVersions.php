<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\ChangeGameVersionAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAudience;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameFormat;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameVersion;
use App\Queries\Games\GameDraftQuery;
use App\Queries\Games\GameTrafficQuery;
use App\Queries\Games\GameUnpublishedChangesQuery;
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Flow\GameFlowValidator;
use App\Support\Games\Publishing\GamePublishReadiness;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;

/**
 * A game's Versions tab: a new version — one content set played through one
 * flow, the publish report line by line, published only when every line
 * passes, without traffic or taking over a version's — and every version
 * published, with what it was made from, its traffic, its attempts, and
 * Archive or Restore.
 */
final class GameVersions extends Page
{
    use InteractsWithGameEditor;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-versions';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    public int $publishSetId = 0;

    public int $publishFlowId = 0;

    /** A version whose traffic the new one takes over, or none. */
    public string $takeOver = '';

    #[Locked]
    public bool $confirmingPublish = false;

    public bool $showRetired = false;

    /**
     * The new version starts from the set and flow the address names — the
     * notice on a tab whose saved changes the players don't see sends them —
     * or the first of each; and from the version in traffic to take over,
     * when the address names one.
     */
    public function mount(int|string $record): void
    {
        $game = $this->mountGame($record);
        $sets = GameContentSet::query()->where('game_id', $game->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id);
        $flows = GameFlow::query()->where('game_id', $game->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id);
        $set = (int) request()->query('set');
        $flow = (int) request()->query('flow');
        $takeOver = (int) request()->query('take_over');

        $this->publishSetId = $sets->contains($set) ? $set : (int) $sets->first();
        $this->publishFlowId = $flows->contains($flow) ? $flow : (int) $flows->first();
        $this->takeOver = in_array($takeOver, app(GameTrafficQuery::class)->versionsInTraffic([(int) $game->id])[(int) $game->id] ?? [], true) ? (string) $takeOver : '';
    }

    public function askPublish(): void
    {
        $this->confirmingPublish = true;
    }

    public function closePublish(): void
    {
        $this->confirmingPublish = false;
    }

    public function publish(): void
    {
        $this->confirmingPublish = false;
        $game = $this->game();

        if ($game === null) {
            return;
        }

        try {
            $version = app(PublishGameVersionAction::class)->handle($this->admin(), $game, $this->publishSetId, $this->publishFlowId, $this->takeOver !== '' ? (int) $this->takeOver : null);
        } catch (CannotManageGameException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $tookOver = $this->takeOver !== '';
        $this->takeOver = '';
        $this->toast($version->label().($tookOver ? ' published and given that version’s traffic' : ' published. It gets no traffic until you give it a share in Traffic.'));
    }

    public function retire(mixed $id): void
    {
        $this->changeVersion($id, 'retire');
    }

    public function restore(mixed $id): void
    {
        $this->changeVersion($id, 'restore');
    }

    private function changeVersion(mixed $id, string $change): void
    {
        $version = is_numeric($id) ? GameVersion::query()->where('game_id', $this->gameId)->find((int) $id) : null;

        if ($version === null) {
            return;
        }

        try {
            $change === 'retire'
                ? app(ChangeGameVersionAction::class)->retire($this->admin(), $version)
                : app(ChangeGameVersionAction::class)->restore($this->admin(), $version);
        } catch (CannotManageGameException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast($version->label().($change === 'retire' ? ' archived' : ' restored'));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $sets = app(GameDraftQuery::class)->contentSets((int) $game->id);
        $flows = GameFlow::query()->where('game_id', $game->id)->whereNull('archived_at')->with('steps')->orderBy('position')->orderBy('id')->get();
        $versions = GameVersion::query()->where('game_id', $game->id)->with(['contentSet', 'flow', 'publishedBy'])->withCount('attempts')->orderByDesc('number')->get();
        $gameIds = Game::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $traffic = app(GameTrafficQuery::class);
        $shares = [];

        foreach (GameAudience::cases() as $audience) {
            $period = $traffic->currentPeriod((int) $game->id, $audience);
            $shares[$audience->value] = $period?->shares() ?? [];
        }

        $reports = [];

        foreach ($sets as $set) {
            $reports[(int) $set->id] = ContentSetReport::of($set);
        }

        $chosenSet = $reports[$this->publishSetId] ?? null;
        $chosenFlow = $flows->firstWhere('id', $this->publishFlowId);
        $readiness = $chosenSet !== null && $chosenFlow !== null ? GamePublishReadiness::of($game, $chosenSet, $chosenFlow, $gameIds) : null;
        $latestFromPair = $versions->first(fn (GameVersion $version): bool => (int) $version->content_set_id === $this->publishSetId && (int) $version->flow_id === $this->publishFlowId);
        $edits = app(GameUnpublishedChangesQuery::class);
        $changedSince = $latestFromPair === null ? null : $edits->setEditedAfter($this->publishSetId, $latestFromPair->published_at) || $edits->flowEditedAfter($this->publishFlowId, $latestFromPair->published_at);
        $inTraffic = array_values(array_unique(array_merge(array_keys($shares['anonymous']), array_keys($shares['authenticated']))));
        $unplayable = $traffic->unplayableVersionIds($versions->modelKeys());

        return [
            'header' => $this->editorHeader($game, 'versions', ['versions' => $versions->isNotEmpty() ? (string) $versions->count() : null]),
            'sets' => $sets->map(fn (GameContentSet $set): array => [
                'id' => (int) $set->id,
                'name' => $set->name,
                'rounds' => count($reports[(int) $set->id]->rounds),
                'state' => match (true) {
                    $reports[(int) $set->id]->rounds === [] => ['label' => 'Empty', 'tone' => 'neutral'],
                    $reports[(int) $set->id]->isPublishable() => ['label' => 'Ready', 'tone' => 'success'],
                    default => ['label' => ($reports[(int) $set->id]->roundsWithIssues() + count($reports[(int) $set->id]->profileIssues)).' to fix', 'tone' => 'warning'],
                },
            ])->all(),
            'flows' => $flows->map(fn (GameFlow $flow): array => [
                'id' => (int) $flow->id,
                'name' => $flow->name,
                'chain' => $flow->steps->map(fn (GameFlowStep $step): string => $step->type->label())->implode(' → '),
                'valid' => GameFlowValidator::issues($flow->steps->map(fn (GameFlowStep $step) => $step->draft())->values()->all()) === [],
            ])->all(),
            'readiness' => $readiness,
            'changedSince' => $latestFromPair !== null && $changedSince === true ? $latestFromPair->label() : null,
            'duplicateOf' => $latestFromPair !== null && $changedSince === false ? $latestFromPair->label() : null,
            'takeOvers' => $versions->filter(fn (GameVersion $version): bool => in_array((int) $version->id, $inTraffic, true))->map(fn (GameVersion $version): array => ['id' => (int) $version->id, 'label' => $version->label()])->values()->all(),
            'versions' => $versions->filter(fn (GameVersion $version): bool => $this->showRetired || $version->isActive())->map(fn (GameVersion $version): array => [
                'id' => (int) $version->id,
                'label' => $version->label(),
                'state' => GameFormat::versionStatus($version, in_array((int) $version->id, $inTraffic, true)),
                'active' => $version->isActive(),
                'inTraffic' => in_array((int) $version->id, $inTraffic, true),
                'unplayable' => in_array((int) $version->id, $unplayable, true),
                'set' => $version->contentSet->name,
                'flow' => $version->flow->name,
                'rounds' => $version->round_count,
                'traffic' => collect(GameAudience::cases())->map(fn (GameAudience $audience): ?string => isset($shares[$audience->value][(int) $version->id]) ? ($audience === GameAudience::Anonymous ? 'Anonymous' : 'Logged in').' '.$shares[$audience->value][(int) $version->id].'%' : null)->filter()->implode(' · '),
                'published' => $version->published_at->format('d M Y, H:i'),
                'by' => $version->publishedBy->display_name ?? $version->publishedBy->username ?? null,
                'attempts' => (int) $version->attempts_count,
                'previewUrl' => GameResource::getUrl('preview', ['record' => $game, 'source' => (string) $version->id]),
            ])->values()->all(),
            'retiredCount' => $versions->reject(fn (GameVersion $version): bool => $version->isActive())->count(),
            'trafficUrl' => GameResource::getUrl('traffic', ['record' => $game]),
            'nextLabel' => 'V'.($game->last_version_number + 1),
        ];
    }
}
