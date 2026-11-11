<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\GameContentSet;
use App\Models\GameVersion;
use App\Models\User;
use App\Services\Media\MediaLifecycleService;
use Illuminate\Support\Facades\DB;

/**
 * Renames, archives, restores or deletes a game's content set. A game always
 * keeps at least one set it can edit. A set a published version was made from
 * is archived, never deleted: the version names it; a set no version used is
 * deleted with its rounds and profiles, and the pictures only it showed are
 * released.
 *
 * A rename or a restore from the set's editor names the version the editor
 * was drawn from, as its Save does: either moves the set's version on, so one
 * made over another administrator's save would hide that save from the
 * editor's next Save.
 */
final class ChangeContentSetAction
{
    use LocksGameDraft;

    public function __construct(private readonly MediaLifecycleService $lifecycleService) {}

    /** @throws CannotManageGameException */
    public function rename(User $admin, GameContentSet $set, string $name, ?int $expectedLockVersion = null): void
    {
        $name = mb_substr(trim($name), 0, CreateContentSetAction::NAME_MAX);

        if ($name === '') {
            throw CannotManageGameException::because('Give the content set a name.');
        }

        $this->change($admin, $set, function (GameContentSet $locked) use ($name): void {
            $locked->forceFill(['name' => $name, 'lock_version' => $locked->lock_version + 1])->save();
        }, $expectedLockVersion);
    }

    /** @throws CannotManageGameException */
    public function archive(User $admin, GameContentSet $set): void
    {
        $this->change($admin, $set, function (GameContentSet $locked): void {
            if ($this->othersLeft($locked) === 0) {
                throw CannotManageGameException::because('A game keeps at least one content set. Add another before archiving this one.');
            }

            $locked->forceFill(['archived_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
        });
    }

    /** @throws CannotManageGameException */
    public function restore(User $admin, GameContentSet $set, ?int $expectedLockVersion = null): void
    {
        $this->change($admin, $set, function (GameContentSet $locked): void {
            $locked->forceFill(['archived_at' => null, 'lock_version' => $locked->lock_version + 1])->save();
        }, $expectedLockVersion);
    }

    /** @throws CannotManageGameException */
    public function delete(User $admin, GameContentSet $set): void
    {
        $this->change($admin, $set, function (GameContentSet $locked): void {
            if (GameVersion::query()->where('content_set_id', $locked->id)->exists()) {
                throw CannotManageGameException::because('A published version was made from this set. Archive it instead.');
            }

            if ($this->othersLeft($locked) === 0 && $locked->archived_at === null) {
                throw CannotManageGameException::because('A game keeps at least one content set. Add another before deleting this one.');
            }

            $locked->load(['rounds.options', 'profiles']);
            $assets = $locked->rounds->flatMap(fn ($round) => $round->options->pluck('asset_id'))
                ->merge($locked->profiles->pluck('share_asset_id'))
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            $locked->profiles()->delete();
            $locked->rounds()->delete();
            $locked->delete();

            if ($assets->isNotEmpty()) {
                $this->lifecycleService->releaseUnreferenced($assets);
            }
        });
    }

    /** The game's other sets that are not archived. */
    private function othersLeft(GameContentSet $set): int
    {
        return GameContentSet::query()->where('game_id', $set->game_id)->whereKeyNot($set->id)->whereNull('archived_at')->count();
    }

    /**
     * @param  callable(GameContentSet): void  $change
     *
     * @throws CannotManageGameException
     */
    private function change(User $admin, GameContentSet $set, callable $change, ?int $expectedLockVersion = null): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $set, $change, $expectedLockVersion): void {
            $lockedAdmin = $this->lockAdministrator($admin);
            $game = $this->lockGame((int) $set->game_id);
            $locked = $this->lockContentSet($game, (int) $set->id, $expectedLockVersion);

            $change($locked);
            $this->touchGame($game, $lockedAdmin);
        });
    }
}
