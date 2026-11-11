<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Data\Games\GameGeneralData;
use App\Enums\GameMechanic;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\User;
use App\Support\Games\GameGeneralRules;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator saves a game's public identity from the General tab. Once
 * a version has been published the slug and the mechanic stay as they are:
 * shared links point at the slug, and published versions play their mechanic.
 * Saving changes nothing a published version shows.
 */
final class SaveGameGeneralAction
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
    public function handle(User $admin, Game $game, GameGeneralData $data, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $data = $this->rules->validated($data, $game);

        DB::transaction(function () use ($admin, $game, $data, $expectedLockVersion): void {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id, $expectedLockVersion);

            if ($locked->hasBeenPublished() && ($data->slug !== $locked->slug || $data->mechanic !== $locked->mechanic->value)) {
                throw CannotManageGameException::because('The slug and the mechanic are locked once a version has been published.');
            }

            $locked->forceFill([
                'slug' => $data->slug,
                'internal_name' => $data->internalName,
                'title' => $data->title,
                'description' => $data->description !== '' ? $data->description : null,
                'mechanic' => GameMechanic::from($data->mechanic),
            ]);
            $this->touchGame($locked, $lockedAdmin);

            $game->setRawAttributes($locked->getAttributes(), true);
        });

        $this->logger->info('game.general.saved', ['game_id' => $game->id, 'admin_id' => $admin->id]);
    }
}
