<?php

use App\Actions\Games\CreateGameAction;
use App\Data\Games\GameGeneralData;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * Games are not made in the admin: each arrives with the release that brings
 * it, as a draft an administrator then fills, publishes and switches on. This
 * one is Trust Your Taste, the first Versus game — made the way the admin
 * would have made it (CreateGameAction: an empty Standard content set and a
 * Result First flow) and credited to the site's first active administrator.
 *
 * A database with no administrator yet — a fresh install, a test run — gets
 * no game; one that already has the game keeps it as it is.
 */
return new class extends Migration
{
    private const SLUG = 'trust-your-taste';

    public function up(): void
    {
        if (Game::query()->where('slug', self::SLUG)->exists()) {
            return;
        }

        $admin = User::query()
            ->where('role', UserRole::Admin)
            ->where('status', UserStatus::Active)
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            return;
        }

        app(CreateGameAction::class)->handle($admin, new GameGeneralData(
            title: 'Trust Your Taste',
            slug: self::SLUG,
            description: 'Pick the picture you think people liked more.',
        ));
    }

    /**
     * Takes the game away again only while it has never been published: once
     * it has versions, players' attempts and results hang on it.
     */
    public function down(): void
    {
        $game = Game::query()->where('slug', self::SLUG)->first();

        if ($game === null || $game->first_published_at !== null) {
            return;
        }

        // Their steps, rounds, options and profiles go with them.
        GameFlow::query()->where('game_id', $game->id)->delete();
        GameContentSet::query()->where('game_id', $game->id)->delete();
        $game->delete();
    }
};
