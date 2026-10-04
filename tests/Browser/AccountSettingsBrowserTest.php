<?php

use App\Enums\MediaKind;
use App\Models\User;
use Tests\Browser\Support\ImageFixtures;
use Tests\Browser\Support\MobileViewports;

use function Pest\Laravel\actingAs;

/*
 * The account settings page as a person meets it: the deletion dialog opens
 * on top of a long page and can be typed into, and the header's avatar
 * button draws a round ring, not an oval.
 */

afterEach(function () {
    ImageFixtures::cleanup();
});

const DELETE_DIALOG = '[data-testid="delete-account-modal"]';

/** Whether the deletion dialog is the thing a pointer meets in the middle of its panel. */
const DELETE_DIALOG_ON_TOP = <<<'JS'
    (() => {
        const panel = document.querySelector('[data-testid="delete-account-modal"] [data-modal-panel]');
        const r = panel.getBoundingClientRect();
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);

        return r.height > 0 && panel.contains(hit);
    })()
JS;

it('opens the deletion dialog on top of a long page and lets the password be typed', function (array $screen) {
    actingAs(User::factory()->create());

    $page = visit(route('profile.edit'))->resize(...$screen)->wait(0.3);

    $page->script('window.scrollTo(0, document.body.scrollHeight)');
    $page->click('[data-testid="delete-account-open"]');

    waitForScript($page, DELETE_DIALOG_ON_TOP);
    waitForScript($page, 'document.activeElement.id', 'delete_account_password');

    // Still open, still on top, and the typed text is where it was typed.
    $page->type('[data-testid="delete-account-password"]', 'typed-password')
        ->wait(1.0)
        ->assertValue('[data-testid="delete-account-password"]', 'typed-password');

    waitForScript($page, DELETE_DIALOG_ON_TOP);

    $page->keys('#delete_account_password', 'Escape');

    waitForScript($page, 'getComputedStyle(document.querySelector(\'[data-testid="delete-account-modal"]\')).display', 'none');
})->with([
    'laptop' => [[1440, 790]],
    'phone' => [MobileViewports::SMALL_MOBILE],
]);

it('asks an account without a password to confirm with its email', function () {
    actingAs(User::factory()->withoutPassword()->create(['email' => 'social-only@rateguru.test']));

    $page = visit(route('profile.edit'))->resize(1440, 790)->wait(0.3);

    $page->script('window.scrollTo(0, document.body.scrollHeight)');
    $page->click('[data-testid="delete-account-open"]');

    waitForScript($page, DELETE_DIALOG_ON_TOP);
    waitForScript($page, 'document.activeElement.id', 'delete_account_email');

    $page->assertSeeIn(DELETE_DIALOG, 'social-only@rateguru.test')
        ->assertNotPresent('[data-testid="delete-account-password"]');
});

it('draws the header avatar ring as a circle', function (array $screen) {
    // A real photo matters: an <img> sits on the text baseline and used to
    // stretch the button to 36x42, turning its round ring into an oval.
    // Initials never showed it.
    $avatar = ImageFixtures::write(...ImageFixtures::SQUARE);
    $avatar->update(['kind' => MediaKind::Avatar]);

    actingAs(User::factory()->create(['avatar_asset_id' => $avatar->id]));

    $page = visit(route('feed'))->resize(...$screen)->wait(0.3);

    $box = $page->script(<<<'JS'
        (() => {
            const r = document.querySelector('[data-testid="header-user-menu-trigger"]').getBoundingClientRect();

            return [Math.round(r.width), Math.round(r.height)];
        })()
    JS);

    expect($box[0])->toBe(36)
        ->and($box[1])->toBe(36);
})->with([
    'laptop' => [[1440, 900]],
    'phone' => [MobileViewports::SMALL_MOBILE],
]);
