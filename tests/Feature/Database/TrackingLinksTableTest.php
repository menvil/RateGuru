<?php

use App\Enums\GameStatus;
use App\Enums\TrackingLinkDestination;
use App\Models\Game;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tracking links, alike on every engine: no two carry the same five tags —
 * an empty content or term is '' so the unique index sees it — read by game
 * and by the administrators who made and last changed them through indexes
 * of their own; neither their game nor those administrators can be deleted
 * under them; and down() takes the table off again.
 */

function trackingLinksMigration(): object
{
    return require database_path('migrations/2026_11_18_101100_create_tracking_links_table.php');
}

it('keeps a link as saved, its empty content and term as empty text', function () {
    $game = Game::factory()->status(GameStatus::Live)->create();
    $link = TrackingLink::factory()->create(['destination' => TrackingLinkDestination::Game, 'game_id' => $game->id, 'path' => '/games/'.$game->slug, 'utm_content' => ''])->fresh();

    expect($link->destination)->toBe(TrackingLinkDestination::Game)
        ->and($link->game_id)->toBe($game->id)
        ->and($link->utm_content)->toBe('')
        ->and($link->utm_term)->toBe('')
        ->and($link->updated_by_user_id)->toBeNull()
        ->and($link->reference())->toBe('TL-'.$link->id)
        ->and($link->tags()->columns())->toBe(['utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'utm_campaign' => 'launch', 'utm_content' => null, 'utm_term' => null]);
});

it('refuses a second link with the same five tags, the empty ones included', function () {
    $tags = ['utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'utm_campaign' => 'launch', 'utm_content' => '', 'utm_term' => ''];
    TrackingLink::factory()->create($tags);

    expect(fn () => DB::transaction(fn () => TrackingLink::factory()->create($tags)))->toThrow(QueryException::class);

    // Any tag apart makes another link.
    TrackingLink::factory()->create([...$tags, 'utm_term' => 'quiz']);

    expect(TrackingLink::query()->count())->toBe(2)
        ->and(collect(Schema::getIndexes('tracking_links'))->firstWhere('name', 'tracking_links_tags_unique'))
        ->columns->toBe(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])
        ->unique->toBeTrue();
});

it('reads links by game and by the administrators who made and changed them', function () {
    $indexes = collect(Schema::getIndexes('tracking_links'));

    expect($indexes->firstWhere('name', 'tracking_links_game_index')['columns'])->toBe(['game_id'])
        ->and($indexes->firstWhere('name', 'tracking_links_created_by_index')['columns'])->toBe(['created_by_user_id'])
        ->and($indexes->firstWhere('name', 'tracking_links_updated_by_index')['columns'])->toBe(['updated_by_user_id']);
});

it('keeps the game and the administrators of a link from being deleted under it', function () {
    $editor = User::factory()->admin()->create();
    $game = Game::factory()->create();
    $link = TrackingLink::factory()->create(['destination' => TrackingLinkDestination::Game, 'game_id' => $game->id, 'updated_by_user_id' => $editor->id]);

    expect(fn () => DB::transaction(fn () => DB::table('games')->where('id', $game->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('users')->where('id', $link->created_by_user_id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('users')->where('id', $editor->id)->delete()))->toThrow(QueryException::class);

    expect(Game::query()->whereKey($game->id)->exists())->toBeTrue();
});

it('undoes what it did and does it again', function () {
    TrackingLink::factory()->create();

    trackingLinksMigration()->down();

    expect(Schema::hasTable('tracking_links'))->toBeFalse();

    trackingLinksMigration()->up();

    expect(Schema::hasTable('tracking_links'))->toBeTrue()
        ->and(TrackingLink::query()->count())->toBe(0);
});
