<?php

namespace App\Filament\Resources\Games\Pages;

use App\Enums\GameMechanic;
use App\Enums\GameStatus;
use App\Filament\Resources\Games\GameFormat;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Support\ListPageNumber;
use App\Filament\Support\ModerationListFormat;
use App\Models\Game;
use App\Models\GameVersion;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Games\GamesQuery;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Analytics\GameFunnel;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

/**
 * Growth → Games in Admin v2: the library. Every game not archived, the most
 * recently saved first, searched and narrowed by status and mechanic on the
 * server, 25 at a time, each with its newest version and where its traffic
 * stands, its status and the way into its editor. A game's status is changed
 * in its editor. Archived games are listed under their own status.
 */
final class ListGames extends Page
{
    /** The days the library's figures look back over. */
    public const METRICS_DAYS = 30;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.games';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    #[Url(except: '')]
    public mixed $q = '';

    #[Url(except: '')]
    public mixed $status = '';

    #[Url(except: '')]
    public mixed $mechanic = '';

    #[Url(except: 'updated')]
    public mixed $sort = 'updated';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    public static function canAccess(array $parameters = []): bool
    {
        return GameResource::canAccess();
    }

    public function getTitle(): string
    {
        return 'Games';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'status', 'mechanic', 'sort'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = ListPageNumber::from($page);
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->status = '';
        $this->mechanic = '';
        $this->pageNumber = 1;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $query = app(GamesQuery::class);
        $status = GameStatus::tryFrom((string) $this->status);
        $mechanic = GameMechanic::tryFrom((string) $this->mechanic);
        $paginator = $query->paginate((string) $this->q, $status, $mechanic, (string) $this->sort, (int) $this->pageNumber);

        if ($paginator->currentPage() > $paginator->lastPage()) {
            $this->pageNumber = $paginator->lastPage();
            $paginator = $query->paginate((string) $this->q, $status, $mechanic, (string) $this->sort, $this->pageNumber);
        }

        $stats = $query->stats();

        return [
            'stats' => array_map(fn (int $count): string => number_format($count), $stats),
            'statuses' => [
                ['value' => '', 'label' => 'All but archived', 'trigger' => 'All'],
                ...array_map(fn (GameStatus $case): array => ['value' => $case->value, 'label' => $case->label()], GameStatus::cases()),
            ],
            'mechanics' => [
                ['value' => '', 'label' => 'Any mechanic', 'trigger' => 'All'],
                ...array_map(fn (GameMechanic $case): array => ['value' => $case->value, 'label' => $case->label()], GameMechanic::cases()),
            ],
            'sorts' => [
                ['value' => 'updated', 'label' => 'Last updated'],
                ['value' => 'name', 'label' => 'Name A–Z'],
            ],
            'statusName' => $status?->label(),
            'mechanicName' => $mechanic?->label(),
            'paginator' => $paginator,
            'rows' => $this->rows($paginator->items()),
            'filtered' => trim((string) $this->q) !== '' || $status !== null || $mechanic !== null,
            'empty' => (int) $stats['total'] === 0 && $status === null,
        ];
    }

    /**
     * @param  list<Game>  $games
     * @return list<array<string, mixed>>
     */
    private function rows(array $games): array
    {
        $ids = array_map(fn (Game $game): int => (int) $game->id, $games);
        $inTraffic = app(GameTrafficQuery::class)->versionsInTraffic($ids);
        // Up to and including the current second: the database keeps whole seconds.
        $now = CarbonImmutable::now()->startOfSecond()->addSecond();
        $metrics = app(GameMetricsQuery::class)->library($ids, $now->subDays(self::METRICS_DAYS), $now);

        return array_map(fn (Game $game): array => $this->row($game, $inTraffic[(int) $game->id] ?? [], $metrics[(int) $game->id] ?? null), $games);
    }

    /**
     * @param  list<int>  $inTraffic  the game's versions in traffic now
     * @param  array{started: int, completed: int, visitors: int, registered: int}|null  $metrics  the last 30 days
     * @return array<string, mixed>
     */
    private function row(Game $game, array $inTraffic, ?array $metrics): array
    {
        /** @var GameVersion|null $latest */
        $latest = $game->versions->first();

        return [
            'id' => (int) $game->id,
            'title' => $game->title,
            'internal' => $game->internal_name,
            'slug' => $game->slug,
            'editUrl' => GameResource::getUrl('edit', ['record' => $game]),
            'links' => [
                ['label' => 'Edit', 'icon' => 'pencil', 'url' => GameResource::getUrl('edit', ['record' => $game])],
                ['label' => 'Preview', 'icon' => 'play', 'url' => GameResource::getUrl('preview', ['record' => $game])],
                ['label' => 'Analytics', 'icon' => 'chart-no-axes-column', 'url' => GameResource::getUrl('analytics', ['record' => $game])],
                ['label' => 'Versions', 'icon' => 'git-branch', 'url' => GameResource::getUrl('versions', ['record' => $game])],
            ],
            'mechanic' => $game->mechanic->label(),
            'icon' => $game->mechanic->icon(),
            'status' => GameFormat::status($game->status),
            'version' => $latest === null ? null : [
                'label' => $latest->label(),
                'published' => $latest->published_at->format('d M Y'),
                'state' => GameFormat::versionStatus($latest, in_array((int) $latest->id, $inTraffic, true)),
            ],
            'updated' => ModerationListFormat::when($game->updated_at),
            'plays' => $metrics === null ? null : number_format($metrics['started']),
            'completion' => $metrics === null ? null : self::percent($metrics['completed'], $metrics['started']),
            'signups' => $metrics === null ? null : self::percent($metrics['registered'], $metrics['visitors']),
        ];
    }

    private static function percent(int $part, int $whole): ?string
    {
        $rate = GameFunnel::rate($part, $whole);

        return $rate === null ? null : number_format($rate, 1).'%';
    }

    private function normalize(): void
    {
        $this->status = is_string($this->status) && GameStatus::tryFrom($this->status) !== null ? $this->status : '';
        $this->mechanic = is_string($this->mechanic) && GameMechanic::tryFrom($this->mechanic) !== null ? $this->mechanic : '';
        $this->sort = is_string($this->sort) && in_array($this->sort, GamesQuery::SORTS, true) ? $this->sort : 'updated';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->pageNumber = ListPageNumber::from($this->pageNumber);
    }
}
