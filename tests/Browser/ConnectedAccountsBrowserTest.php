<?php

use App\Models\SocialAccount;
use App\Models\User;
use Tests\Browser\Support\MobileViewports;

use function Pest\Laravel\actingAs;

/*
 * The Connected accounts card as a person meets it: disconnecting asks
 * first, cancelling changes nothing, and confirming removes the provider.
 */

const DISCONNECT_GOOGLE_DIALOG = '[data-testid="disconnect-google-modal"]';

/** Whether the disconnect dialog is the thing a pointer meets in the middle of its panel. */
const DISCONNECT_GOOGLE_DIALOG_ON_TOP = <<<'JS'
    (() => {
        const panel = document.querySelector('[data-testid="disconnect-google-modal"] [data-modal-panel]');
        const r = panel.getBoundingClientRect();
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);

        return r.height > 0 && panel.contains(hit);
    })()
JS;

const DISCONNECT_GOOGLE_DIALOG_HIDDEN = <<<'JS'
    getComputedStyle(document.querySelector('[data-testid="disconnect-google-modal"]')).display
JS;

it('asks before disconnecting, and cancelling keeps the provider', function (array $screen) {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_email' => 'ivan.personal@gmail.com']);
    actingAs($user);

    $page = visit(route('profile.edit'))->resize(...$screen);

    $page->click('[data-testid="disconnect-google"]');

    waitForScript($page, DISCONNECT_GOOGLE_DIALOG_ON_TOP);
    $page->assertSeeIn(DISCONNECT_GOOGLE_DIALOG, 'ivan.personal@gmail.com');

    $page->click('[data-testid="disconnect-google-cancel"]');

    waitForScript($page, DISCONNECT_GOOGLE_DIALOG_HIDDEN, 'none');
    expect($user->socialAccounts()->count())->toBe(1);
})->with([
    'laptop' => [[1440, 790]],
    'phone' => [MobileViewports::SMALL_MOBILE],
]);

it('disconnects the provider once confirmed', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_email' => 'ivan.personal@gmail.com']);
    actingAs($user);

    $page = visit(route('profile.edit'))->resize(1440, 790);

    $page->click('[data-testid="disconnect-google"]');
    waitForScript($page, DISCONNECT_GOOGLE_DIALOG_ON_TOP);
    $page->click('[data-testid="disconnect-google-confirm"]');

    $page->assertSeeIn('[data-testid="connected-accounts-status"]', __('profile.connected.disconnected', ['provider' => 'Google']))
        ->assertPresent('[data-testid="connect-google"]');
    expect($user->socialAccounts()->count())->toBe(0);
});
