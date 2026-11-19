<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Actions\Translations\RecordProjectTranslationSourceAction;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameVersion;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Translations\ProjectContentSection;
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

    public function __construct(private readonly RecordProjectTranslationSourceAction $sources) {}

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
                // A copy keeps the goal it was given; a new flow takes the one its steps give it.
                'goal' => $source?->goal,
                'position' => (int) GameFlow::query()->where('game_id', $locked->id)->max('position') + 1,
            ])->save();

            if ($source !== null) {
                foreach ($source->steps as $step) {
                    $copy = $step->replicate(['flow_id']);
                    $copy->forceFill(['flow_id' => $flow->id])->save();
                    $this->sources->copy(ProjectContentSection::GameFlowSteps, (int) $step->id, (int) $copy->id);
                }
            } else {
                FlowTemplateWriter::fromTemplate($flow, $template ?? GameFlowTemplate::ResultFirst, $locked->title);
            }

            $this->touchGame($locked, $lockedAdmin);

            return $flow;
        });
    }

    /**
     * The flow's name, which only the editor shows: a name kept as it was
     * writes nothing, and a new one doesn't count as an edit players would
     * see (GameUnpublishedChangesQuery reads when a flow was saved).
     * Whether it wrote one.
     *
     * @throws CannotManageGameException
     */
    public function rename(User $admin, GameFlow $flow, string $name, ?int $expectedLockVersion = null): bool
    {
        $name = $this->name($name);

        return $this->change($admin, $flow, function (GameFlow $locked) use ($name): bool {
            if ($locked->name === $name) {
                return false;
            }

            $locked->timestamps = false;
            $locked->forceFill(['name' => $name, 'lock_version' => $locked->lock_version + 1])->save();

            return true;
        }, $expectedLockVersion);
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
        $this->change($admin, $flow, function (GameFlow $locked): void {
            $locked->forceFill(['archived_at' => null, 'lock_version' => $locked->lock_version + 1])->save();
        }, $expectedLockVersion);
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
     * Makes a change to the flow under its locks, and records it on the game
     * — unless the change returns false: it wrote nothing, so neither does
     * the game. Whether it wrote.
     *
     * @param  callable(GameFlow): mixed  $change
     *
     * @throws CannotManageGameException
     */
    private function change(User $admin, GameFlow $flow, callable $change, ?int $expectedLockVersion = null): bool
    {
        $this->allowed($admin);

        return DB::transaction(function () use ($admin, $flow, $change, $expectedLockVersion): bool {
            $lockedAdmin = $this->lockAdministrator($admin);
            $game = $this->lockGame((int) $flow->game_id);
            $locked = $this->lockFlow($game, (int) $flow->id, $expectedLockVersion);

            if ($change($locked) === false) {
                return false;
            }

            $this->touchGame($game, $lockedAdmin);

            return true;
        });
    }
}
