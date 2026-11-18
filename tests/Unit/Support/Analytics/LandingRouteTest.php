<?php

use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\LandingRoute;

/*
 * The page a session entered by — its route, then the game and version it
 * showed as far as they are known — and the campaign it came from, each as a
 * key that fits its column.
 */

it('names a landing by its route, game and version', function (?string $route, ?int $game, ?int $version, string $key) {
    expect(LandingRoute::key($route, $game, $version))->toBe($key);
})->with([
    ['games.play', 12, 345, 'games.play:12:345'],
    ['games.play', 12, null, 'games.play:12'],
    ['games.player-result', 12, null, 'games.player-result:12'],
    ['posts.show', null, null, 'posts.show'],
    // A version means nothing without its game.
    ['feed', null, 7, 'feed'],
    // A page whose route has no name.
    [null, 12, 345, 'other'],
    ['', null, null, 'other'],
]);

it('never writes a landing longer than its column, keeping the game and version whole', function () {
    $key = LandingRoute::key(str_repeat('r', 80), 9_223_372_036_854_775_807, 9_223_372_036_854_775_806);

    expect(strlen($key))->toBe(60)
        ->and($key)->toEndWith(':9223372036854775807:9223372036854775806')
        ->and(strlen(LandingRoute::key('games.player-result', 9_223_372_036_854_775_807, 9_223_372_036_854_775_806)))->toBeLessThanOrEqual(60);
});

it('gives a landing on a game’s page the version shown once it is known, and leaves any other as it is', function () {
    expect(LandingRoute::withVersion('games.play:12', 12, 345))->toBe('games.play:12:345')
        ->and(LandingRoute::withVersion('games.play:12:300', 12, 345))->toBe('games.play:12:300')
        ->and(LandingRoute::withVersion('games.play:13', 12, 345))->toBe('games.play:13')
        ->and(LandingRoute::withVersion('games.result:12', 12, 345))->toBe('games.result:12')
        ->and(LandingRoute::withVersion(null, 12, 345))->toBeNull();
});

it('reads back what a landing names', function () {
    expect(LandingRoute::parts('games.play:12:345'))->toBe(['route' => 'games.play', 'gameId' => 12, 'versionId' => 345])
        ->and(LandingRoute::parts('posts.show'))->toBe(['route' => 'posts.show', 'gameId' => null, 'versionId' => null]);
});

it('names a campaign by its paid campaign or by its tag, within its column', function () {
    expect(CampaignKey::paid(55))->toBe('paid:55')
        ->and(CampaignKey::paid(null))->toBeNull()
        ->and(CampaignKey::tag('Versus October Launch'))->toBe('tag:Versus October Launch')
        ->and(CampaignKey::tag("  \t"))->toBeNull()
        ->and(CampaignKey::tag(null))->toBeNull()
        ->and(mb_strlen((string) CampaignKey::tag(str_repeat('c', 300))))->toBe(140);
});
