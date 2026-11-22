<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\EndGameTrafficSplitAction;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Enums\GameVersionStatus;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficSourceStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Pages\Experiments\ExperimentsView;
use App\Filament\Pages\ExperimentsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficCampaign;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Games\GameTrafficQuery;
use App\Queries\Games\TrafficPeriodScopesQuery;
use App\Support\Games\Traffic\TrafficScope;
use App\Support\Games\Traffic\TrafficShareValidator;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * A game's Traffic tab: how each audience's visitors are shared out between
 * its active versions — anonymous visitors and logged-in players apart — each
 * applied on its own (ApplyGameTrafficAction), and every period an allocation
 * was in effect, with how many visitors it assigned and how many attempts
 * they started and completed, and the way to its results in Growth →
 * Experiments.
 *
 * Apply names the shares the audience was drawn with, so shares changed
 * since — by another administrator, or by a version published taking over
 * another's — are never silently put back; the audience is drawn again.
 *
 * Under the general split, the splits for a source (docs/architecture/games.md,
 * "Splits for a source"): anonymous visitors a paid click of one source — or
 * of one of its campaigns — brought get shares of their own, set like the
 * general ones; saving starts the split's own period, and ending it sends
 * those clicks back to the next split that covers them. The form names the
 * split's shares it was drawn with, as Apply does.
 */
final class GameTraffic extends Page
{
    use InteractsWithGameEditor;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-traffic';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** @var array<string, array<int|string, mixed>> the shares on screen, by audience and version id */
    public array $shares = [];

    /** @var array<string, array<int|string, mixed>> the shares in effect */
    #[Locked]
    public array $applied = [];

    /**
     * The split for a source being set, while its form is open: the source and
     * campaign by id ('' for none) and the shares by version id.
     *
     * @var array{source: string, campaign: string, shares: array<int|string, mixed>}|null
     */
    public ?array $split = null;

    /** @var array<int, int> the shares the split being set had when the form drew it: none for a new split */
    #[Locked]
    public array $splitApplied = [];

    /** The split asked to be ended, by its period, while the confirmation is open. */
    #[Locked]
    public ?int $endingSplit = null;

    public function mount(int|string $record): void
    {
        $this->mountGame($record);
        $this->draw();
    }

    public function addVersion(mixed $audience, mixed $versionId): void
    {
        $audience = is_string($audience) ? GameAudience::tryFrom($audience) : null;

        if ($audience === null || ! is_numeric($versionId) || ! in_array((int) $versionId, $this->activeVersionIds(), true)) {
            return;
        }

        $this->shares[$audience->value][(int) $versionId] ??= 0;
    }

    public function removeVersion(mixed $audience, mixed $versionId): void
    {
        if (is_string($audience) && is_numeric($versionId)) {
            unset($this->shares[$audience][(int) $versionId]);
        }
    }

    /** Whole shares as even as they can be, the remainder to the first versions. */
    public function splitEvenly(mixed $audience): void
    {
        $audience = is_string($audience) ? GameAudience::tryFrom($audience) : null;

        if ($audience === null || ($this->shares[$audience->value] ?? []) === []) {
            return;
        }

        $this->shares[$audience->value] = self::even(array_keys($this->shares[$audience->value]));
    }

    public function resetShares(mixed $audience = null): void
    {
        if (is_string($audience) && isset($this->applied[$audience])) {
            $this->shares[$audience] = $this->applied[$audience];
        }
    }

    public function apply(mixed $audience): void
    {
        $audience = is_string($audience) ? GameAudience::tryFrom($audience) : null;
        $game = $this->game();

        if ($audience === null || $game === null) {
            return;
        }

        $shares = self::wholeShares($this->shares[$audience->value] ?? []);

        try {
            $started = app(ApplyGameTrafficAction::class)->handle($this->admin(), $game, $audience, $shares, self::wholeShares($this->applied[$audience->value] ?? []));
        } catch (CannotManageGameException $exception) {
            if ($exception->isStale()) {
                $this->drawAudience($audience);
            }

            $this->toast($exception->isStale() ? 'These shares changed after you opened the page, so nothing was applied. They now show as they are.' : $exception->getMessage(), 'error');

            return;
        }

        // Only this audience is drawn again: the other keeps its unsaved shares, and the shares it was drawn with.
        $this->drawAudience($audience);
        $this->toast($started ? 'Shares applied. A new period started.' : 'Shares applied. The current period had no visitors yet, so it was updated.');
    }

    /**
     * Opens the form for a new split, or for the open split of the period
     * given: its source, campaign and shares.
     */
    public function openSplit(mixed $periodId = null): void
    {
        $period = is_numeric($periodId) ? $this->openSplitPeriod((int) $periodId) : null;
        $this->split = ['source' => '', 'campaign' => '', 'shares' => []];
        $this->splitApplied = [];

        if ($period !== null) {
            $this->split = ['source' => (string) $period->source_id, 'campaign' => $period->campaign_id === null ? '' : (string) $period->campaign_id, 'shares' => $period->shares()];
            $this->splitApplied = $period->shares();
        }
    }

    public function closeSplit(): void
    {
        $this->split = null;
        $this->splitApplied = [];
    }

    /**
     * A source or campaign chosen: the form takes up the split already open
     * for it, if there is one, so it is changed rather than started over;
     * otherwise it keeps the shares typed. A new source has no campaign.
     */
    public function updatedSplit(mixed $value, string $key): void
    {
        if ($this->split === null || ! in_array($key, ['source', 'campaign'], true)) {
            return;
        }

        if ($key === 'source') {
            $this->split['campaign'] = '';
        }

        $scope = $this->splitScope();
        $current = $scope === null ? null : app(GameTrafficQuery::class)->currentPeriod($this->gameId, GameAudience::Anonymous, $scope);

        if ($current !== null) {
            $this->split['shares'] = $current->shares();
        }

        $this->splitApplied = $current?->shares() ?? [];
    }

    public function addSplitVersion(mixed $versionId): void
    {
        if ($this->split === null || ! is_numeric($versionId) || ! in_array((int) $versionId, $this->activeVersionIds(), true)) {
            return;
        }

        $this->split['shares'][(int) $versionId] ??= 0;
    }

    public function removeSplitVersion(mixed $versionId): void
    {
        if ($this->split !== null && is_numeric($versionId)) {
            unset($this->split['shares'][(int) $versionId]);
        }
    }

    /** The split's shares as even as they can be, the remainder to the first versions. */
    public function spreadSplitEvenly(): void
    {
        if ($this->split === null || $this->split['shares'] === []) {
            return;
        }

        $this->split['shares'] = self::even(array_keys($this->split['shares']));
    }

    public function saveSplit(): void
    {
        $game = $this->game();
        $scope = $this->splitScope();

        if ($game === null || $this->split === null) {
            return;
        }

        if ($scope === null) {
            $this->toast('Choose a source for the split.', 'error');

            return;
        }

        try {
            $started = app(ApplyGameTrafficAction::class)->handle($this->admin(), $game, GameAudience::Anonymous, self::wholeShares($this->split['shares']), $this->splitApplied, $scope);
        } catch (CannotManageGameException $exception) {
            if ($exception->isStale()) {
                $this->updatedSplit($this->split['campaign'], 'campaign');
            }

            $this->toast($exception->isStale() ? 'This split changed after you opened it, so nothing was saved. It now shows as it is.' : $exception->getMessage(), 'error');

            return;
        }

        $this->closeSplit();
        $this->toast($started ? 'Split saved. Its own period started.' : 'Split saved. Its current period had no visitors yet, so it was updated.');
    }

    public function askEndSplit(mixed $periodId): void
    {
        $this->endingSplit = is_numeric($periodId) && $this->openSplitPeriod((int) $periodId) !== null ? (int) $periodId : null;
    }

    public function closeEndSplit(): void
    {
        $this->endingSplit = null;
    }

    public function endSplit(): void
    {
        $game = $this->game();
        $periodId = $this->endingSplit;
        $this->endingSplit = null;

        if ($game === null || $periodId === null) {
            return;
        }

        $ended = $this->openSplitPeriod($periodId)?->scope();

        try {
            app(EndGameTrafficSplitAction::class)->handle($this->admin(), $game, $periodId);
        } catch (CannotManageGameException $exception) {
            $this->toast($exception->isStale() ? 'This split has already ended.' : $exception->getMessage(), 'error');

            return;
        }

        // Only a form of the split ended is drawn again; one of another split keeps what is typed in it.
        if ($this->split !== null && $ended !== null && $this->splitScope()?->equals($ended) === true) {
            $this->updatedSplit($this->split['campaign'], 'campaign');
        }

        $this->toast('Split ended. Its period stays in the history.');
    }

    /** The scope the split form names, when it names a source of Traffic sources; a campaign of another source names none. */
    private function splitScope(): ?TrafficScope
    {
        $source = $this->split['source'] ?? '';
        $campaign = $this->split['campaign'] ?? '';

        if (! is_numeric($source) || ! TrafficSource::query()->whereKey((int) $source)->exists()) {
            return null;
        }

        if (! is_numeric($campaign) || ! TrafficCampaign::query()->whereKey((int) $campaign)->where('source_id', (int) $source)->exists()) {
            return TrafficScope::source((int) $source);
        }

        return TrafficScope::campaign((int) $source, (int) $campaign);
    }

    /** One of this game's open splits for a source, by its period. */
    private function openSplitPeriod(int $periodId): ?GameTrafficPeriod
    {
        return GameTrafficPeriod::query()->where('game_id', $this->gameId)->whereKey($periodId)->whereNotNull('source_id')->whereNull('ended_at')->with('versions')->first();
    }

    /**
     * Whole shares as even as they can be, the remainder to the first versions.
     *
     * @param  list<int|string>  $ids
     * @return array<int, int>
     */
    private static function even(array $ids): array
    {
        $each = intdiv(100, count($ids));
        $left = 100 - $each * count($ids);
        $shares = [];

        foreach ($ids as $index => $id) {
            $shares[(int) $id] = $each + ($index < $left ? 1 : 0);
        }

        return $shares;
    }

    private function draw(): void
    {
        foreach (GameAudience::cases() as $audience) {
            $this->drawAudience($audience);
        }
    }

    private function drawAudience(GameAudience $audience): void
    {
        $shares = app(GameTrafficQuery::class)->currentPeriod($this->gameId, $audience)?->shares() ?? [];
        $this->shares[$audience->value] = $shares;
        $this->applied[$audience->value] = $shares;
    }

    /**
     * Shares as typed, version id to a whole number, the way Apply sends them
     * and the page checks them: whole-number text such as "05" counts as 5,
     * anything else, "12.5" too, as -1, which no share may be.
     *
     * @param  array<int|string, mixed>  $shares
     * @return array<int, int>
     */
    private static function wholeShares(array $shares): array
    {
        $whole = [];

        foreach ($shares as $versionId => $share) {
            $whole[(int) $versionId] = match (true) {
                is_int($share) => $share,
                is_string($share) && preg_match('/\A-?\d{1,9}\z/', trim($share)) === 1 => (int) trim($share),
                default => -1,
            };
        }

        return $whole;
    }

    /**
     * The split form as drawn: the sources of Traffic sources to choose from
     * — ad networks and banners, none ended unless the split is already for
     * it — the chosen source's latest 200 campaigns not archived, and the one
     * chosen whatever its age or status, and the shares checked the way saving
     * checks them.
     *
     * @param  Collection<int|string, GameVersion>  $versions
     * @param  Collection<int|string, GameVersion>  $active
     * @param  list<int>  $unplayable
     * @return array<string, mixed>
     */
    private function splitForm(Collection $versions, Collection $active, array $unplayable): array
    {
        $split = $this->split ?? ['source' => '', 'campaign' => '', 'shares' => []];
        $clean = self::wholeShares($split['shares']);
        $sourceId = is_numeric($split['source']) ? (int) $split['source'] : 0;
        $sources = TrafficSource::query()
            ->where(fn ($query) => $query->where('status', '!=', TrafficSourceStatus::Ended->value)->orWhere('id', $sourceId))
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
        $campaignId = is_numeric($split['campaign']) ? (int) $split['campaign'] : 0;
        $campaigns = $sourceId === 0 ? collect() : TrafficCampaign::query()
            ->where('source_id', $sourceId)
            ->where('status', '!=', TrafficCampaignStatus::Archived->value)
            ->orderByDesc('first_click_at')
            ->limit(200)
            ->get(['id', 'source_id', 'external_id', 'name']);
        // The campaign chosen is offered whatever the cap leaves out: an older one, or one archived since.
        $chosen = $sourceId === 0 || $campaignId === 0 || $campaigns->contains('id', $campaignId) ? null
            : TrafficCampaign::query()->whereKey($campaignId)->where('source_id', $sourceId)->first(['id', 'source_id', 'external_id', 'name']);
        $campaigns = $chosen === null ? $campaigns : $campaigns->prepend($chosen);
        $scope = $this->splitScope();
        $issues = match (true) {
            $scope === null => ['Choose a source for the split.'],
            $clean === [] => ['Give at least one version a share.'],
            default => TrafficShareValidator::issues($clean, $active->keys()->map(fn (mixed $id): int => (int) $id)->all()),
        };

        return [
            'sources' => $sources->map(fn (TrafficSource $source): array => ['id' => (int) $source->id, 'label' => $source->name.' · '.$source->type->label()])->all(),
            'campaigns' => $campaigns->map(fn (TrafficCampaign $campaign): array => ['id' => (int) $campaign->id, 'label' => $campaign->label()])->all(),
            'rows' => self::shareRows(array_keys($clean), $versions, $unplayable),
            'addable' => self::addable($active, $clean),
            'issues' => $issues,
            'existing' => $this->splitApplied !== [],
            'changed' => $clean != $this->splitApplied,
        ];
    }

    /**
     * The rows of a set of shares, by version id: its label, what it is made
     * of, when it was published and whether it can still be played.
     *
     * @param  list<int>  $versionIds
     * @param  Collection<int|string, GameVersion>  $versions
     * @param  list<int>  $unplayable
     * @return list<array<string, mixed>>
     */
    private static function shareRows(array $versionIds, Collection $versions, array $unplayable): array
    {
        return array_map(fn (int $versionId): array => [
            'id' => $versionId,
            'label' => $versions->get($versionId)?->label() ?? 'V?',
            'made' => $versions->has($versionId) ? $versions[$versionId]->contentSet->name.' · '.$versions[$versionId]->flow->name : '',
            'published' => $versions->get($versionId)?->published_at->format('d M Y'),
            'unplayable' => in_array($versionId, $unplayable, true),
        ], $versionIds);
    }

    /**
     * The active versions not among these shares yet, to add.
     *
     * @param  Collection<int|string, GameVersion>  $active
     * @param  array<int, int>  $shares
     * @return list<array{id: int, label: string}>
     */
    private static function addable(Collection $active, array $shares): array
    {
        return $active->reject(fn (GameVersion $version): bool => array_key_exists((int) $version->id, $shares))->map(fn (GameVersion $version): array => ['id' => (int) $version->id, 'label' => $version->label()])->values()->all();
    }

    /**
     * Per period given, the visitors it assigned and the attempts they
     * started and completed, from its funnel (GameMetricsQuery::periodFunnel())
     * summed over its versions: each visitor is an assignment of the period,
     * and an attempt is started from the visitor's assignment, on its game and
     * its version (StartGameAttemptAction), so these are every assignment and
     * every attempt of the period — read from the hours of its assignments
     * once they are counted.
     *
     * @param  list<GameTrafficPeriod>  $periods
     * @return array<int, array{visitors: int, started: int, completed: int}>
     */
    private static function figures(array $periods): array
    {
        $figures = [];
        $funnels = app(GameMetricsQuery::class)->periodFunnel(array_values(array_unique(array_map(fn (GameTrafficPeriod $period): int => (int) $period->id, $periods))), ExperimentsView::maturedBefore());

        foreach ($funnels as $periodId => $versions) {
            foreach ($versions as $funnel) {
                $figures[$periodId]['visitors'] = ($figures[$periodId]['visitors'] ?? 0) + $funnel->assigned;
                $figures[$periodId]['started'] = ($figures[$periodId]['started'] ?? 0) + $funnel->attempts;
                $figures[$periodId]['completed'] = ($figures[$periodId]['completed'] ?? 0) + $funnel->attemptsCompleted;
            }
        }

        return $figures;
    }

    /** A period's versions with their shares: “V1 50% · V2 50%”. */
    private static function mix(GameTrafficPeriod $period): string
    {
        return $period->versions->map(fn (GameVersion $version): string => $version->label().' '.((int) $version->getRelation('pivot')->getAttribute('share_percent')).'%')->implode(' · ');
    }

    /** @return list<int> */
    private function activeVersionIds(): array
    {
        return GameVersion::query()->where('game_id', $this->gameId)->where('status', GameVersionStatus::Active)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $versions = GameVersion::query()->where('game_id', $game->id)->with(['contentSet', 'flow'])->orderBy('number')->get()->keyBy('id');
        $active = $versions->filter(fn (GameVersion $version): bool => $version->isActive());
        $activeIds = $active->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $unplayable = app(GameTrafficQuery::class)->unplayableVersionIds($versions->keys()->map(fn (mixed $id): int => (int) $id)->all());
        $splits = app(GameTrafficQuery::class)->currentSplits((int) $game->id, GameAudience::Anonymous);
        $results = ExperimentsPage::canAccess();
        $groups = [];

        foreach (GameAudience::cases() as $audience) {
            $clean = self::wholeShares($this->shares[$audience->value] ?? []);

            $groups[] = [
                'audience' => $audience->value,
                'label' => $audience->label(),
                'note' => match (true) {
                    $audience === GameAudience::Authenticated => 'Logged-in players skip the steps shown only to anonymous visitors, such as sign-up.',
                    $splits->isNotEmpty() => 'Everyone else: each new visitor no split for a source covers gets one version and keeps it when they come back.',
                    default => 'Each new visitor gets one version and keeps it when they come back.',
                },
                'rows' => self::shareRows(array_keys($clean), $versions, $unplayable),
                'addable' => self::addable($active, $clean),
                'total' => array_sum(array_map(fn (int $share): int => max(0, $share), $clean)),
                'issues' => TrafficShareValidator::issues($clean, $activeIds),
                'changed' => $clean != array_map('intval', $this->applied[$audience->value] ?? []),
            ];
        }

        $periods = GameTrafficPeriod::query()->where('game_id', $game->id)->with('versions')->orderByDesc('started_at')->orderByDesc('id')->limit(50)->get();
        $figures = self::figures([...$periods, ...$splits]);
        $scopes = app(TrafficPeriodScopesQuery::class)->labels($periods);

        return [
            'header' => $this->editorHeader($game, 'traffic'),
            'paused' => $game->status === GameStatus::Paused,
            'archived' => $game->status === GameStatus::Archived,
            'noVersions' => $versions->isEmpty(),
            'groups' => $groups,
            'splits' => $splits->map(fn (GameTrafficPeriod $period): array => [
                'id' => (int) $period->id,
                'scope' => (string) $period->scopeLabel(),
                'shares' => self::mix($period),
                'since' => $period->started_at->format('d M Y, H:i'),
                'visitors' => $figures[(int) $period->id]['visitors'] ?? 0,
                // A split none of whose versions can be played sends its clicks on to the next split that covers them.
                'idle' => $period->versions->every(fn (GameVersion $version): bool => ! $version->isActive() || in_array((int) $version->id, $unplayable, true)),
                'resultsUrl' => $results ? ExperimentsPage::getUrl(['period' => $period->id]) : null,
            ])->all(),
            'splitForm' => $this->split === null ? null : $this->splitForm($versions, $active, $unplayable),
            // Only a recorded click knows its source: while Record visits is off, splits send nobody.
            'recording' => TrafficSetting::captureFlags()['capture'],
            'trafficUrl' => TrafficSourcesPage::canAccess() ? TrafficSourcesPage::getUrl(['tab' => 'sources']) : null,
            'ending' => $this->endingSplit === null ? null : $splits->firstWhere('id', $this->endingSplit)?->scopeLabel(),
            'periods' => $periods->map(fn (GameTrafficPeriod $period): array => [
                'audience' => implode(' · ', array_filter([$period->audience->label(), $scopes[(int) $period->id] ?? null])),
                'from' => $period->started_at->format('d M Y, H:i'),
                'to' => $period->ended_at?->format('d M Y, H:i'),
                'shares' => self::mix($period) ?: 'No traffic',
                'visitors' => $figures[(int) $period->id]['visitors'] ?? 0,
                'started' => $figures[(int) $period->id]['started'] ?? 0,
                'completed' => $figures[(int) $period->id]['completed'] ?? 0,
                // A period with shares has results in Growth → Experiments.
                'resultsUrl' => $results && $period->versions->isNotEmpty() ? ExperimentsPage::getUrl(['period' => $period->id]) : null,
            ])->all(),
            'versionsUrl' => GameResource::getUrl('versions', ['record' => $game]),
        ];
    }
}
