<?php

namespace App\Filament\Resources\Games\Concerns;

use App\Actions\Games\ChangeGameStatusAction;
use App\Data\Games\UnpublishedChanges;
use App\Enums\GameStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\GameFormat;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\User;
use App\Queries\Games\GameTrafficQuery;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

/**
 * What every tab of a game's editor shares: the game it edits, the header
 * band — the game's title and status, its status menu and the tabs — and the
 * status changes, each asked first (OVL-01) and made through
 * ChangeGameStatusAction. A tab is a page of its own; moving between tabs is
 * a page load, and each tab asks before leaving with unsaved changes.
 */
trait InteractsWithGameEditor
{
    #[Locked]
    public int $gameId = 0;

    /** The status the confirmation on screen would move the game to. */
    #[Locked]
    public ?string $confirmingStatus = null;

    public function getTitle(): string
    {
        $game = $this->game();

        return $game === null ? 'Game' : $game->title;
    }

    /** Resolves the game the address names, for an administrator only. */
    protected function mountGame(int|string $record): Game
    {
        $game = is_numeric($record) ? Game::query()->find((int) $record) : null;

        abort_unless(GameResource::canAccess(), 403);
        abort_unless($game !== null && GameResource::canEdit($game), 404);

        $this->gameId = (int) $game->id;

        $toast = session('rg-admin-toast');

        if (is_array($toast) && is_string($toast['message'] ?? null)) {
            $this->toast($toast['message'], is_string($toast['tone'] ?? null) ? $toast['tone'] : 'success');
        }

        return $game;
    }

    /** The game as it is now; null once it is gone. */
    protected function game(): ?Game
    {
        return Game::query()->find($this->gameId);
    }

    public function askStatus(mixed $target): void
    {
        $this->confirmingStatus = null;
        $status = is_string($target) ? GameStatus::tryFrom($target) : null;

        if ($status === null || $this->game() === null) {
            return;
        }

        $this->confirmingStatus = $status->value;
    }

    public function closeStatus(): void
    {
        $this->confirmingStatus = null;
    }

    public function changeStatus(): void
    {
        $target = $this->confirmingStatus !== null ? GameStatus::tryFrom($this->confirmingStatus) : null;
        $this->confirmingStatus = null;
        $game = $this->game();

        if ($target === null || $game === null) {
            return;
        }

        try {
            app(ChangeGameStatusAction::class)->handle($this->admin(), $game, $target);
        } catch (CannotManageGameException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast(match ($target) {
            GameStatus::Live => 'The game is live',
            GameStatus::Paused => 'The game is paused',
            GameStatus::Archived => 'The game is archived',
            GameStatus::Draft => 'The game is a draft again',
        });

        $this->statusChanged($game);
    }

    /** A tab that holds something drawn from the game's version draws it again. */
    protected function statusChanged(Game $game): void {}

    /**
     * The header band's data: the game, its status, the moves its status menu
     * offers — each with why it cannot be made now, if it cannot — the tabs,
     * and, on a tab whose saved changes the players don't see yet, which
     * versions they play instead and the Versions tab set to publish one that
     * has them, taking over the newest one's traffic.
     *
     * @param  array<string, string|null>  $badges
     * @return array<string, mixed>
     */
    protected function editorHeader(Game $game, string $tab, array $badges = [], ?UnpublishedChanges $unpublished = null): array
    {
        $hasTraffic = app(GameTrafficQuery::class)->hasPlayableTraffic((int) $game->id);
        $moves = [];

        foreach ($this->statusMoves($game->status, $game->hasBeenPublished()) as $target => $label) {
            $status = GameStatus::from($target);
            $moves[] = [
                'target' => $target,
                'label' => $label,
                'icon' => GameFormat::statusChange($game->status, $status)['icon'],
                'hint' => $status === GameStatus::Live && ! $hasTraffic
                    ? ($game->hasBeenPublished() ? 'Give a version a share in Traffic first' : 'Publish a version and give it traffic first')
                    : null,
            ];
        }

        $confirming = $this->confirmingStatus !== null ? GameStatus::tryFrom($this->confirmingStatus) : null;

        return [
            'game' => [
                'id' => (int) $game->id,
                'title' => $game->title,
                'internal' => $game->internal_name,
                'slug' => $game->slug,
                'mechanic' => $game->mechanic->label(),
                'icon' => $game->mechanic->icon(),
                'status' => GameFormat::status($game->status),
                'published' => $game->hasBeenPublished(),
                'url' => url('/games/'.$game->slug),
            ],
            'moves' => $moves,
            'tabs' => GameResource::editorTabs($game, $tab, $badges),
            'unpublished' => $unpublished === null ? null : [
                'versions' => Arr::join($unpublished->versions, ', ', ' and '),
                'plural' => count($unpublished->versions) > 1,
                'next' => 'V'.($game->last_version_number + 1),
                'url' => GameResource::getUrl('versions', ['record' => $game, 'set' => $unpublished->setId, 'flow' => $unpublished->flowId, 'take_over' => $unpublished->takeOverId]),
            ],
            'confirmingStatus' => $confirming === null ? null : [...GameFormat::statusChange($game->status, $confirming), 'subject' => $game->title],
        ];
    }

    /**
     * The statuses a game can be moved to from where it stands.
     *
     * @return array<string, string>
     */
    private function statusMoves(GameStatus $status, bool $published): array
    {
        return match ($status) {
            GameStatus::Draft => [GameStatus::Live->value => 'Go live', GameStatus::Archived->value => 'Archive'],
            GameStatus::Live => [GameStatus::Paused->value => 'Pause', GameStatus::Archived->value => 'Archive'],
            GameStatus::Paused => [GameStatus::Live->value => 'Resume', GameStatus::Archived->value => 'Archive'],
            GameStatus::Archived => $published ? [GameStatus::Paused->value => 'Restore'] : [GameStatus::Draft->value => 'Restore'],
        };
    }

    protected function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone);
    }

    protected function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
