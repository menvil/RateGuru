<?php

namespace App\Filament\Resources\Games\Pages;

use App\Enums\GameAudience;
use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Queries\Games\GameMetricsQuery;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * A game's Analytics tab: how far its visitors got — anonymous visitors and
 * logged-in players apart — over a window, for every version or one: the
 * figures, the funnel, how many attempts reached each round, which results
 * players earned, the versions, flows or content sets side by side, and the
 * traffic periods the figures were made in.
 *
 * Every figure is read from what playing recorded (GameMetricsQuery); nothing
 * is estimated, and nothing claims one version is better than another.
 */
final class GameAnalytics extends Page
{
    use InteractsWithGameEditor;

    /** The windows a game's figures are read over, by days; all time has none. */
    public const PERIODS = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'all' => 'All time'];

    public const BREAKDOWNS = ['version' => 'Version', 'flow' => 'Flow', 'set' => 'Content set'];

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-analytics';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    #[Url(as: 'group', except: 'anonymous')]
    public string $audience = 'anonymous';

    #[Url(except: '30d')]
    public string $period = '30d';

    #[Url(except: 0)]
    public int $version = 0;

    #[Url(as: 'by', except: 'version')]
    public string $breakdown = 'version';

    public function mount(int|string $record): void
    {
        $this->mountGame($record);
    }

    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $audience = GameAudience::tryFrom($this->audience) ?? GameAudience::Anonymous;
        $period = array_key_exists($this->period, self::PERIODS) ? $this->period : '30d';
        $breakdown = array_key_exists($this->breakdown, self::BREAKDOWNS) ? $this->breakdown : 'version';
        /** @var Collection<int, GameVersion> $versions */
        $versions = GameVersion::query()->where('game_id', $game->id)->with(['contentSet', 'flow'])->orderByDesc('number')->get()->keyBy('id');
        $versionId = $versions->has($this->version) ? $this->version : null;
        // Up to and including the current second: the database keeps whole seconds.
        $now = CarbonImmutable::now()->startOfSecond()->addSecond();
        $scope = new GameMetricsScope((int) $game->id, $audience, self::windowStart($period, $now), $now, $versionId);
        $metrics = app(GameMetricsQuery::class);

        $total = $metrics->funnels($scope)[0];
        $byVersion = $metrics->funnels($scope, 'version');
        // Of the versions the figures are of: the one chosen, or every one.
        $emailVersions = ($versionId !== null ? $versions->only([$versionId]) : $versions)->filter(fn (GameVersion $version): bool => self::asksForEmail($version))->keys()->all();
        $finishedWithEmail = array_sum(array_map(fn (GameFunnel $funnel): int => $funnel->finishers, array_intersect_key($byVersion, array_flip($emailVersions))));
        $focus = $versionId !== null ? $versions->get($versionId) : self::busiest($versions, $byVersion);

        return [
            'header' => $this->editorHeader($game, 'analytics'),
            'audience' => $audience->value,
            'anonymous' => $audience === GameAudience::Anonymous,
            'audiences' => ['anonymous' => 'Anonymous visitors', 'authenticated' => 'Logged-in players'],
            'period' => $period,
            'periods' => self::PERIODS,
            'version' => $versionId ?? 0,
            'versionOptions' => [
                ['value' => '0', 'label' => 'All versions', 'trigger' => 'All'],
                ...$versions->map(fn (GameVersion $version): array => [
                    'value' => (string) $version->id,
                    'label' => $version->label().' · '.$version->contentSet->name.' · '.$version->flow->name,
                    'trigger' => $version->label(),
                ])->values()->all(),
            ],
            'breakdown' => $breakdown,
            'breakdowns' => self::BREAKDOWNS,
            'scopeText' => implode(' · ', [$audience === GameAudience::Anonymous ? 'Anonymous visitors' : 'Logged-in players', self::PERIODS[$period], $versionId !== null ? $versions->get($versionId)?->label() : 'all versions']),
            'noVersions' => $versions->isEmpty(),
            'noData' => $total->isEmpty(),
            'kpis' => $audience === GameAudience::Anonymous ? self::anonymousKpis($total, $finishedWithEmail, $emailVersions !== [], $versionId !== null) : self::playerKpis($total),
            'funnel' => $audience === GameAudience::Anonymous ? self::anonymousFunnel($total, $finishedWithEmail, $emailVersions !== [], $metrics->signUpMethods($scope)) : self::playerFunnel($total),
            'focus' => $focus !== null ? self::focus($focus, $metrics, $scope, $byVersion[(int) $focus->id] ?? new GameFunnel, $versionId === null) : null,
            'breakdownRows' => self::breakdownRows($breakdown, $breakdown === 'version' ? $byVersion : $metrics->funnels($scope, $breakdown), $versions, $audience),
            'periodRows' => self::periodRows($game, $audience, $metrics->periods((int) $game->id)),
            'trafficUrl' => GameResource::getUrl('traffic', ['record' => $game]),
            'versionsUrl' => GameResource::getUrl('versions', ['record' => $game]),
        ];
    }

    private static function windowStart(string $period, CarbonImmutable $now): CarbonImmutable
    {
        return match ($period) {
            '7d' => $now->subDays(7),
            '90d' => $now->subDays(90),
            'all' => CarbonImmutable::createFromTimestampUTC(0),
            default => $now->subDays(30),
        };
    }

    /** Whether an anonymous visitor of the version can meet an Email capture step. */
    private static function asksForEmail(GameVersion $version): bool
    {
        foreach ($version->read()->steps as $step) {
            if ($step->type === GameFlowStepType::EmailCapture && $step->audience !== GameStepAudience::Authenticated) {
                return true;
            }
        }

        return false;
    }

    /**
     * The version with the most attempts started in the window, the newest
     * first among equals; the newest version when nobody started any.
     *
     * @param  Collection<int, GameVersion>  $versions
     * @param  array<int, GameFunnel>  $byVersion
     */
    private static function busiest(Collection $versions, array $byVersion): ?GameVersion
    {
        return $versions->sortByDesc(fn (GameVersion $version): array => [$byVersion[(int) $version->id]->started ?? 0, (int) $version->number])->first();
    }

    /**
     * Started counts people, as Visitors does; Completion is of plays, a
     * replay one more.
     *
     * @return list<array{label: string, value: string, note: string}>
     */
    private static function anonymousKpis(GameFunnel $total, int $finishedWithEmail, bool $asksForEmail, bool $oneVersion): array
    {
        $noEmail = $oneVersion ? 'This version doesn’t ask for an email' : 'No version asks for an email';

        return [
            ['label' => 'Visitors', 'value' => number_format($total->visitors), 'note' => 'People who opened the game'],
            ['label' => 'Started', 'value' => number_format($total->starters), 'note' => self::percent($total->starters, $total->visitors).' of visitors'],
            ['label' => 'Completion', 'value' => self::percent($total->completed, $total->started), 'note' => self::plays($total)],
            ['label' => 'Email capture', 'value' => $asksForEmail ? self::percent($total->emailers, $finishedWithEmail) : '—', 'note' => $asksForEmail ? number_format($total->emails).' '.str('email')->plural($total->emails) : $noEmail],
            ['label' => 'New accounts', 'value' => number_format($total->registered), 'note' => self::percent($total->registered, $total->visitors).' of visitors'],
            ['label' => 'Signed in', 'value' => number_format($total->signedIn), 'note' => 'Existing accounts'],
        ];
    }

    /** @return list<array{label: string, value: string, note: string}> */
    private static function playerKpis(GameFunnel $total): array
    {
        return [
            ['label' => 'Players', 'value' => number_format($total->visitors), 'note' => 'Logged-in players who opened the game'],
            ['label' => 'Started', 'value' => number_format($total->starters), 'note' => self::percent($total->starters, $total->visitors).' of players'],
            ['label' => 'Completion', 'value' => self::percent($total->completed, $total->started), 'note' => self::plays($total)],
            ['label' => 'Shared', 'value' => self::percent($total->shared, $total->completed), 'note' => number_format($total->shared).' '.str('result')->plural($total->shared).' shared'],
            ['label' => 'Played again', 'value' => number_format($total->replayers), 'note' => 'Players who started more than once'],
        ];
    }

    /** How many plays were completed, of how many, a replay one more. */
    private static function plays(GameFunnel $total): string
    {
        return number_format($total->completed).' of '.number_format($total->started).' '.str('play')->plural($total->started).' completed';
    }

    /**
     * The anonymous funnel, people at every step: Email captured only where
     * a version asks for one, then who began signing up — by how — and the
     * accounts made.
     *
     * @param  array<string, int>  $methods
     * @return list<array<string, mixed>>
     */
    private static function anonymousFunnel(GameFunnel $total, int $finishedWithEmail, bool $asksForEmail, array $methods): array
    {
        return self::funnelRows($total->visitors, array_values(array_filter([
            ['Visitors', $total->visitors, null, 'People who opened the game'],
            ['Game started', $total->starters, $total->visitors, 'People who started at least once'],
            ['Completed', $total->finishers, $total->starters, 'People who finished at least once'],
            $asksForEmail ? ['Email captured', $total->emailers, $finishedWithEmail, 'Of the people who finished a version that asks for an email'] : null,
            ['Sign-up begun', $total->signUpStarters, $total->finishers, self::methods($methods)],
            ['New account', $total->registered, $total->finishers, 'Made within a day of an attempt, each person once'],
        ])));
    }

    /**
     * How the people who began signing up began it.
     *
     * @param  array<string, int>  $methods
     */
    private static function methods(array $methods): string
    {
        $labels = ['email' => 'Email', 'google' => 'Google', 'facebook' => 'Facebook', 'register' => 'Sign-up dialog', 'login' => 'Log in'];
        $parts = [];

        foreach ($labels as $method => $label) {
            if (($methods[$method] ?? 0) > 0) {
                $parts[] = $label.' '.number_format($methods[$method]);
            }
        }

        return $parts === [] ? 'The email form opened, a provider chosen, or the site\'s sign-up or log-in' : implode(' · ', $parts);
    }

    /** @return list<array<string, mixed>> */
    private static function playerFunnel(GameFunnel $total): array
    {
        return self::funnelRows($total->visitors, [
            ['Players', $total->visitors, null, 'Logged-in players who opened the game'],
            ['Game started', $total->starters, $total->visitors, 'Players who started at least once'],
            ['Completed', $total->finishers, $total->starters, 'Players who finished at least once'],
            ['Shared result', $total->sharers, $total->finishers, null],
            ['Played again', $total->replayers, $total->starters, 'Players who started more than once'],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int|null, 3: string|null}>  $steps
     * @return list<array<string, mixed>>
     */
    private static function funnelRows(int $start, array $steps): array
    {
        return array_map(fn (array $step): array => [
            'label' => $step[0],
            'hint' => $step[3],
            'count' => number_format($step[1]),
            'bar' => $start > 0 ? min(100, round($step[1] * 100 / $start, 1)) : 0,
            'fromPrevious' => $step[2] === null ? '—' : self::percent($step[1], $step[2]),
            'fromStart' => self::percent($step[1], $start),
        ], $steps);
    }

    /**
     * The rounds and results of the version the figures look into: the one
     * chosen, or the busiest of the window.
     *
     * @return array<string, mixed>
     */
    private static function focus(GameVersion $version, GameMetricsQuery $metrics, GameMetricsScope $scope, GameFunnel $funnel, bool $chosenForYou): array
    {
        $snapshot = $version->read();
        $reach = $metrics->reach($scope, (int) $version->id);
        $results = $metrics->results($scope, (int) $version->id);
        $rounds = [];
        $previous = $funnel->started;

        foreach ($snapshot->rounds as $index => $round) {
            $answered = $reach[$index] ?? 0;
            $rounds[] = [
                'label' => 'Round '.($index + 1),
                'question' => $round->question->english(),
                'unscored' => ! $round->scored,
                'bar' => $funnel->started > 0 ? min(100, round($answered * 100 / $funnel->started, 1)) : 0,
                'reached' => self::percent($answered, $funnel->started),
                'count' => number_format($answered),
                'lost' => $index > 0 && $previous > $answered ? number_format($previous - $answered).' left' : null,
            ];
            $previous = $answered;
        }

        $completed = array_sum($results);

        return [
            'label' => $version->label(),
            'note' => ($chosenForYou ? "{$version->label()} has the most attempts here; choose a version above to see another. " : '')
                .'Of the '.number_format($funnel->started).' '.str('attempt')->plural($funnel->started).' started, how many answered each round.',
            'rounds' => $rounds,
            'resultsNote' => number_format($completed).' completed '.str('attempt')->plural($completed).', by the result each earned.',
            'results' => array_map(fn ($profile): array => [
                'label' => $profile->name->english(),
                'sub' => $profile->min === $profile->max ? "{$profile->min} ".str('match')->plural($profile->min) : "{$profile->min}–{$profile->max} matches",
                'count' => number_format($results[$profile->key] ?? 0),
                'bar' => $completed > 0 ? round(($results[$profile->key] ?? 0) * 100 / $completed, 1) : 0,
                'share' => self::percent($results[$profile->key] ?? 0, $completed),
            ], $snapshot->profiles),
        ];
    }

    /**
     * @param  array<int, GameFunnel>  $funnels
     * @param  Collection<int, GameVersion>  $versions
     * @return array{head: list<string>, rows: list<array<string, mixed>>}
     */
    private static function breakdownRows(string $by, array $funnels, Collection $versions, GameAudience $audience): array
    {
        $head = $audience === GameAudience::Anonymous
            ? [self::BREAKDOWNS[$by], 'Visitors', 'Started', 'Completed', 'Emails', 'New accounts']
            : [self::BREAKDOWNS[$by], 'Players', 'Started', 'Completed', 'Shared', 'Played again'];
        $rows = [];

        foreach ($funnels as $key => $funnel) {
            $named = match ($by) {
                'flow' => $versions->filter(fn (GameVersion $version): bool => (int) $version->flow_id === $key),
                'set' => $versions->filter(fn (GameVersion $version): bool => (int) $version->content_set_id === $key),
                default => $versions->only([$key]),
            };
            $first = $named->first();

            if ($first === null) {
                continue;
            }

            $rows[] = [
                'name' => match ($by) {
                    'flow' => $first->flow->name,
                    'set' => $first->contentSet->name,
                    default => $first->label(),
                },
                'sub' => $by === 'version' ? $first->contentSet->name.' · '.$first->flow->name : 'Used by '.$named->map(fn (GameVersion $version): string => $version->label())->sortBy(fn (string $label): int => (int) substr($label, 1))->implode(', '),
                'cells' => $audience === GameAudience::Anonymous
                    ? [
                        [number_format($funnel->visitors), null],
                        [number_format($funnel->starters), self::percent($funnel->starters, $funnel->visitors)],
                        [number_format($funnel->finishers), self::percent($funnel->finishers, $funnel->starters)],
                        [number_format($funnel->emailers), self::percent($funnel->emailers, $funnel->finishers)],
                        [number_format($funnel->registered), self::percent($funnel->registered, $funnel->visitors)],
                    ]
                    : [
                        [number_format($funnel->visitors), null],
                        [number_format($funnel->starters), self::percent($funnel->starters, $funnel->visitors)],
                        [number_format($funnel->finishers), self::percent($funnel->finishers, $funnel->starters)],
                        [number_format($funnel->sharers), self::percent($funnel->sharers, $funnel->finishers)],
                        [number_format($funnel->replayers), self::percent($funnel->replayers, $funnel->starters)],
                    ],
            ];
        }

        return ['head' => $head, 'rows' => $rows];
    }

    /**
     * The audience's traffic periods, newest first, with what was assigned,
     * started and completed in each.
     *
     * @param  array<int, array{started: int, completed: int}>  $attempts
     * @return list<array<string, mixed>>
     */
    private static function periodRows(Game $game, GameAudience $audience, array $attempts): array
    {
        return GameTrafficPeriod::query()
            ->where('game_id', $game->id)
            ->where('audience', $audience->value)
            ->with('versions')
            ->withCount('assignments')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (GameTrafficPeriod $period): array => [
                'dates' => $period->started_at->format('d M Y').' — '.($period->ended_at?->format('d M Y') ?? 'now'),
                'shares' => $period->versions->map(fn (GameVersion $version): string => $version->label().' '.((int) $version->getRelation('pivot')->getAttribute('share_percent')).'%')->implode(' · ') ?: 'No traffic',
                'figures' => number_format((int) $period->assignments_count).' assigned · '
                    .number_format($attempts[(int) $period->id]['started'] ?? 0).' started · '
                    .number_format($attempts[(int) $period->id]['completed'] ?? 0).' completed',
                'current' => $period->ended_at === null,
            ])
            ->all();
    }

    private static function percent(int $part, int $whole): string
    {
        $rate = GameFunnel::rate($part, $whole);

        return $rate === null ? '—' : number_format($rate, 1).'%';
    }
}
