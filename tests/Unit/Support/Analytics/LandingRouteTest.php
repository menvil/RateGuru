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

it('says where a landing is: a game’s page by its slug, a result shared from it marked so, any other page by its route', function (string $key, ?string $slug, string $path) {
    expect(LandingRoute::path($key, $slug))->toBe($path);
})->with([
    ['feed', null, '/'],
    ['posts.show', null, '/posts/…'],
    ['profile.show', null, '/u/…'],
    ['saved-posts.index', null, '/saved'],
    ['pages.about', null, '/about'],
    ['register', null, '/register'],
    ['games.play:12:345', 'trust-your-taste', '/games/trust-your-taste'],
    ['games.play:12', 'trust-your-taste', '/games/trust-your-taste'],
    ['games.result:12:345', 'trust-your-taste', '/games/trust-your-taste · shared result'],
    ['games.player-result:12', 'trust-your-taste', '/games/trust-your-taste · shared result'],
    // A game gone.
    ['games.play:12:345', null, '/games/…'],
    ['other', null, 'Other pages'],
    ['studio.unknown', null, 'Other pages'],
]);

it('says what a landing without a game is, and which landings show a game', function () {
    expect(LandingRoute::page('feed'))->toBe('Home feed')
        ->and(LandingRoute::page('posts.show'))->toBe('Post')
        ->and(LandingRoute::page('login'))->toBe('Sign in')
        ->and(LandingRoute::page('other'))->toBe('Other page')
        ->and(LandingRoute::showsGame('games.play:12:345'))->toBeTrue()
        ->and(LandingRoute::showsGame('games.result:12'))->toBeTrue()
        ->and(LandingRoute::showsGame('games.player-result:12'))->toBeTrue()
        ->and(LandingRoute::showsGame('feed'))->toBeFalse()
        ->and(LandingRoute::showsGame('other'))->toBeFalse();
});

it('names a campaign by its paid campaign or by its tag, within its column', function () {
    expect(CampaignKey::paid(55))->toBe('paid:55')
        ->and(CampaignKey::paid(null))->toBeNull()
        ->and(CampaignKey::tag('Versus October Launch'))->toBe('tag:Versus October Launch')
        ->and(CampaignKey::tag("  \t"))->toBeNull()
        ->and(CampaignKey::tag(null))->toBeNull()
        ->and(mb_strlen((string) CampaignKey::tag(str_repeat('c', 300))))->toBe(140);
});

it('lists a campaign’s visits that came by no creative under a name of their own', function () {
    expect(CampaignKey::creative('video_03'))->toBe('video_03')
        ->and(CampaignKey::creative(null))->toBe('(no creative)')
        ->and(CampaignKey::creative('  '))->toBe(CampaignKey::NO_CREATIVE);
});

it('reads a campaign key as its paid campaign’s name or its tag, and from an address only when a visit could carry it', function () {
    expect(CampaignKey::label('paid:55', [55 => 'US popunder']))->toBe('US popunder')
        ->and(CampaignKey::label('paid:56'))->toBe('Campaign #56')
        ->and(CampaignKey::label('tag:Versus October Launch'))->toBe('Versus October Launch')
        ->and(CampaignKey::paidCampaignId('paid:55'))->toBe(55)
        ->and(CampaignKey::paidCampaignId('tag:55'))->toBeNull()
        ->and(CampaignKey::read('paid:55'))->toBe('paid:55')
        ->and(CampaignKey::read('tag:launch'))->toBe('tag:launch')
        ->and(CampaignKey::read('tag: '))->toBeNull()
        ->and(CampaignKey::read('launch'))->toBeNull()
        ->and(CampaignKey::read(['paid:55']))->toBeNull()
        ->and(CampaignKey::read('tag:'.str_repeat('c', 140)))->toBeNull();
});
