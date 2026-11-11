<?php

namespace App\Filament\Resources\Games;

use App\Filament\Resources\Games\Pages\EditGameContent;
use App\Filament\Resources\Games\Pages\EditGameFlow;
use App\Filament\Resources\Games\Pages\EditGameGeneral;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Filament\Resources\Games\Pages\GameTraffic;
use App\Filament\Resources\Games\Pages\GameVersions;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Filament\Resources\Games\Pages\PreviewGame;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Game;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Growth → Games: the library (ListGames) and a game's editor, one page per
 * tab — General, Content, Flow, Versions, Traffic, Analytics — drawn in
 * Admin v2 over Game. What visitors play is a published version's snapshot;
 * nothing edited here reaches them until a version is published and given
 * traffic.
 *
 * Only an active administrator gets here (`manage-games`): the navigation
 * item, every page and every Livewire request on them ask, and the game
 * actions ask again on the locked rows. Every write goes through those
 * actions; the pages hold no rule of their own.
 */
class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static ?string $slug = 'games';

    protected static ?string $modelLabel = 'game';

    protected static ?string $pluralModelLabel = 'games';

    protected static ?string $navigationLabel = 'Games';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    /**
     * The editor's tabs, in order, by the page that draws each.
     *
     * @var array<string, array{label: string, page: string}>
     */
    public const EDITOR_TABS = [
        'general' => ['label' => 'General', 'page' => 'edit'],
        'content' => ['label' => 'Content', 'page' => 'content'],
        'flow' => ['label' => 'Flow', 'page' => 'flow'],
        'versions' => ['label' => 'Versions', 'page' => 'versions'],
        'traffic' => ['label' => 'Traffic', 'page' => 'traffic'],
        'analytics' => ['label' => 'Analytics', 'page' => 'analytics'],
        'preview' => ['label' => 'Preview', 'page' => 'preview'],
    ];

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::GROWTH;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-games') === true;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    /** No page here makes a game: the library lists the games there are. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess() && $record instanceof Game;
    }

    /** A game is archived, never deleted: its versions, attempts and numbers stay. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGames::route('/'),
            'edit' => EditGameGeneral::route('/{record}'),
            'content' => EditGameContent::route('/{record}/content'),
            'flow' => EditGameFlow::route('/{record}/flow'),
            'versions' => GameVersions::route('/{record}/versions'),
            'traffic' => GameTraffic::route('/{record}/traffic'),
            'analytics' => GameAnalytics::route('/{record}/analytics'),
            'preview' => PreviewGame::route('/{record}/preview'),
        ];
    }

    /**
     * The editor's tabs for a game, the current one marked.
     *
     * @param  array<string, string|null>  $badges  a tab's count or note, by key
     * @return list<array{key: string, label: string, url: string, current: bool, badge: ?string}>
     */
    public static function editorTabs(Game $game, string $current, array $badges = []): array
    {
        $tabs = [];

        foreach (self::EDITOR_TABS as $key => $tab) {
            $tabs[] = [
                'key' => $key,
                'label' => $tab['label'],
                'url' => static::getUrl($tab['page'], ['record' => $game]),
                'current' => $key === $current,
                'badge' => $badges[$key] ?? null,
            ];
        }

        return $tabs;
    }
}
