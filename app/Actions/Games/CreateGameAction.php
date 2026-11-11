<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Data\Games\GameGeneralData;
use App\Enums\GameMechanic;
use App\Enums\GameStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Games\GameGeneralRules;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator creates a game: a Draft with its public identity, an empty
 * content set to add rounds to, and a Result First flow to play them through.
 * Nothing is published and no visitor can reach it until a version is
 * published and given traffic.
 */
final class CreateGameAction
{
    use LocksGameDraft;

    public function __construct(
        private readonly GameGeneralRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws CannotManageGameException
     * @throws ValidationException keyed as the General tab's fields
     */
    public function handle(User $admin, GameGeneralData $data): Game
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $data = $this->rules->validated($data);

        $game = DB::transaction(function () use ($admin, $data): Game {
            $lockedAdmin = $this->lockAdministrator($admin);

            $game = new Game;
            $game->forceFill([
                'slug' => $data->slug,
                'internal_name' => $data->internalName,
                'title' => $data->title,
                'description' => $data->description !== '' ? $data->description : null,
                'mechanic' => GameMechanic::from($data->mechanic),
                'status' => GameStatus::Draft,
                'created_by_user_id' => $lockedAdmin->id,
                'updated_by_user_id' => $lockedAdmin->id,
                'lock_version' => 1,
            ])->save();

            $set = new GameContentSet;
            $set->forceFill([
                'game_id' => $game->id,
                'name' => 'Standard',
                'position' => 0,
                'crowd_min_votes' => (int) config('games.crowd.min_votes'),
                'crowd_min_gap' => (int) config('games.crowd.min_gap'),
            ])->save();

            $flow = new GameFlow;
            $flow->forceFill(['game_id' => $game->id, 'name' => GameFlowTemplate::ResultFirst->label(), 'position' => 0])->save();
            FlowTemplateWriter::fromTemplate($flow, GameFlowTemplate::ResultFirst, $data->title);

            return $game;
        });

        $this->logger->info('game.created', ['game_id' => $game->id, 'admin_id' => $admin->id]);

        return $game;
    }
}
