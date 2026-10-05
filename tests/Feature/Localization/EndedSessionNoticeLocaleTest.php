<?php

use App\Models\User;
use App\Support\Auth\SessionGeneration;
use App\Support\Locale\LocaleManager;

/*
 * The notice shown when a session is ended because someone else proved control
 * of the account. It is produced by a middleware, which puts it in an unusual
 * position: it has to be translated while the session that carried the reader's
 * language choice is being thrown away.
 */

beforeEach(function () {
    config()->set('app.locale', 'en');
});

it('writes the ended-session notice in the language the visitor is reading in', function () {
    expect(app(LocaleManager::class)->isEnabled('ru'))->toBeTrue('this test needs Russian offered');

    $user = User::factory()->create(['session_generation' => SessionGeneration::next()]);

    // The session generation in the session is stale — someone else has since
    // taken this account over. The locale cookie is the language choice that
    // survives the session being invalidated, which is exactly why it has to be
    // read before the notice is written.
    $response = $this->actingAs($user)
        ->withSession([SessionGeneration::SESSION_KEY => 'an-older-generation'])
        ->withCookie('locale', 'ru')
        ->get('/');

    $response->assertRedirect(route('login'));
    expect($response->getSession()->get('status'))->toBe(__('auth.session_ended', [], 'ru'));

    // And it is genuinely the translated one, not the default that happens to
    // be stored.
    expect($response->getSession()->get('status'))->not->toBe(__('auth.session_ended', [], 'en'));
});

it('falls back to the default language when the visitor expressed no preference', function () {
    $user = User::factory()->create(['session_generation' => SessionGeneration::next()]);

    $response = $this->actingAs($user)
        ->withSession([SessionGeneration::SESSION_KEY => 'an-older-generation'])
        ->get('/');

    $response->assertRedirect(route('login'));
    expect($response->getSession()->get('status'))->toBe(__('auth.session_ended', [], 'en'));
});
