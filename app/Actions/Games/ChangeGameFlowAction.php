<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameVersion;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Adds a flow to a game — from a template, or as a copy of another of its
 * flows, texts and translations included — and renames, archives, restores
 * or deletes one. A game always keeps a flow it can edit; a flow a published
 * version was made from is archived, never deleted.
 *
 * A rename or a restore from the flow's editor names the version the editor
 * was drawn from, as its Save does: either moves the flow's version on, so
 * one made over another administrator's save would hide that save from the
 * editor's next Save.
 */
final class ChangeGameFlowAction
{
    use LocksGameDraft;

    public const NAME_MAX = 80;

    /** @throws CannotManageGameException */
    public function create(User $admin, Game $game, string $name, ?GameFlowTemplate $template, ?int $copyOfFlowId = null): GameFlow
    {
        $name = $this->name($name);
        $this->allowed($admin);

        return DB::transaction(function () use ($admin, $game, $name, $template, $copyOfFlowId): GameFlow {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id);
            $source = $copyOfFlowId !== null ? $this->lockFlow($locked, $copyOfFlowId)->load('steps') : null;

            $flow = new GameFlow;
            $flow->forceFill([
                'game_id' => $locked->id,
                'name' => $name,
                'position' => (int) GameFlow::query()->where('game_id', $locked->id)->max('position') + 1,
            ])->save();

            if ($source !== null) {
                foreach ($source->steps as $step) {
                    $copy = $step->replicate(['flow_id']);
                    $copy->forceFill(['flow_id' => $flow->id])->save();
                }
            } else {
                FlowTemplateWriter::fromTemplate($flow, $template ?? GameFlowTemplate::ResultFirst, $locked->title);
            }

            $this->touchGame($locked, $lockedAdmin);

            return $flow;
        });
    }

    /** @throws CannotManageGameException */
    public function rename(User $admin, GameFlow $flow, string $name, ?int $expectedLockVersion = null): void
    {
        $name = $this->name($name);

        $this->change($admin, $flow, fn (GameFlow $locked) => $locked->forceFill(['name' => $name, 'lock_version' => $locked->lock_version + 1])->save(), $expectedLockVersion);
    }

    /** @throws CannotManageGameException */
    public function archive(User $admin, GameFlow $flow): void
    {
        $this->change($admin, $flow, function (GameFlow $locked): void {
            if ($this->othersLeft($locked) === 0) {
                throw CannotManageGameException::because('A game keeps at least one flow. Add another before archiving this one.');
            }

            $locked->forceFill(['archived_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
        });
    }

    /** @throws CannotManageGameException */
    public function restore(User $admin, GameFlow $flow, ?int $expectedLockVersion = null): void
    {
        $this->change($admin, $flow, fn (GameFlow $locked) => $locked->forceFill(['archived_at' => null, 'lock_version' => $locked->lock_version + 1])->save(), $expectedLockVersion);
    }

    /** @throws CannotManageGameException */
    public function delete(User $admin, GameFlow $flow): void
    {
        $this->change($admin, $flow, function (GameFlow $locked): void {
            if (GameVersion::query()->where('flow_id', $locked->id)->exists()) {
                throw CannotManageGameException::because('A published version was made from this flow. Archive it instead.');
            }

            if ($this->othersLeft($locked) === 0 && $locked->archived_at === null) {
                throw CannotManageGameException::because('A game keeps at least one flow. Add another before deleting this one.');
            }

            GameFlowStep::query()->where('flow_id', $locked->id)->delete();
            $locked->delete();
        });
    }

    private function othersLeft(GameFlow $flow): int
    {
        return GameFlow::query()->where('game_id', $flow->game_id)->whereKeyNot($flow->id)->whereNull('archived_at')->count();
    }

    /** @throws CannotManageGameException */
    private function name(string $name): string
    {
        $name = mb_substr(trim($name), 0, self::NAME_MAX);

        if ($name === '') {
            throw CannotManageGameException::because('Give the flow a name.');
        }

        return $name;
    }

    /** @throws CannotManageGameException */
    private function allowed(User $admin): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }
    }

    /**
     * @param  callable(GameFlow): mixed  $change
     *
     * @throws CannotManageGameException
     */
    private function change(User $admin, GameFlow $flow, callable $change, ?int $expectedLockVersion = null): void
    {
        $this->allowed($admin);

        DB::transaction(function () use ($admin, $flow, $change, $expectedLockVersion): void {
            $lockedAdmin = $this->lockAdministrator($admin);
            $game = $this->lockGame((int) $flow->game_id);
            $locked = $this->lockFlow($game, (int) $flow->id, $expectedLockVersion);

            $change($locked);
            $this->touchGame($game, $lockedAdmin);
        });
    }
}
