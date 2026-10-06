<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The live overlay and toast specimens of /admin/dev/ui-kit in a real
 * browser: they are the reusable components themselves, so what holds here
 * holds wherever a screen uses them.
 */
function adminUiKitOverlays(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => ({
            dialog: !! document.querySelector('.rg-admin-dialog'),
            drawer: !! document.querySelector('.rg-admin-drawer'),
            focusInside: !! document.activeElement?.closest('.rg-admin-dialog, .rg-admin-drawer'),
            scrollLocked: getComputedStyle(document.documentElement).overflow === 'hidden',
            toasts: [...document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast')].map((toast) => toast.querySelector('.rg-admin-toast__text').textContent.trim()),
        }))()
    JS);
}

/**
 * The kit at one section, laid out at desktop size, once $ready holds: a
 * statement about the Alpine component the test is about to drive, true only
 * once Alpine has started it.
 */
function adminUiKitAt(string $section, string $ready): mixed
{
    $page = resizeAndSettle(visit("/admin/dev/ui-kit#{$section}"), 1440, 900);

    waitForScript($page, $ready);

    return $page;
}

/** How many toasts the live stack shows. */
function adminUiKitToastCount(): string
{
    return 'document.querySelectorAll(".rg-admin-toast-stack .rg-admin-toast").length';
}

beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

it('opens each confirmation level, keeps focus inside and returns it to the opener', function (string $level) {
    $page = adminUiKitAt('OVL-01', 'Alpine.$data(document.querySelector("[data-kit-dialog]")).dialog === null');
    $opener = "[data-kit-dialog=\"{$level}\"]";

    // The focus trap takes focus last, a moment after the dialog is drawn.
    $page->keys($opener, 'Enter');
    waitForScript($page, '!! document.activeElement?.closest(".rg-admin-dialog")');

    expect(adminUiKitOverlays($page))->toMatchArray(['dialog' => true, 'focusInside' => true, 'scrollLocked' => true]);

    foreach (range(1, 6) as $press) {
        $page->keys(':focus', 'Tab');
    }

    expect(adminUiKitOverlays($page)['focusInside'])->toBeTrue();

    $page->keys(':focus', 'Escape');
    waitForScript($page, "! document.querySelector('.rg-admin-dialog') && document.activeElement === document.querySelector('{$opener}')");

    expect(adminUiKitOverlays($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false])
        ->and($page->script("document.activeElement === document.querySelector('{$opener}')"))->toBeTrue();
})->with(['light', 'warning', 'blocked']);

it('confirms a specimen without leaving anything behind', function () {
    $page = adminUiKitAt('OVL-01', 'Alpine.$data(document.querySelector("[data-kit-dialog]")).dialog === null');

    $page->click('[data-kit-dialog="light"]');
    waitForScript($page, '!! document.activeElement?.closest(".rg-admin-dialog")');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    waitForScript($page, '! document.querySelector(".rg-admin-dialog")');

    expect(adminUiKitOverlays($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false]);
});

it('opens a 448 drawer whose body scrolls under its header and footer', function () {
    $page = adminUiKitAt('OVL-02', 'Alpine.$data(document.querySelector("[data-kit-drawer]")).drawer === false');

    // Measured once the trap has taken focus, the last step of opening, and nothing on the panel still moves.
    $page->keys('[data-kit-drawer]', 'Enter');
    waitForScript($page, '!! document.activeElement?.closest(".rg-admin-drawer") && document.querySelector(".rg-admin-drawer").getAnimations().length === 0');

    expect(adminUiKitOverlays($page))->toMatchArray(['drawer' => true, 'focusInside' => true, 'scrollLocked' => true])
        ->and($page->script(<<<'JS'
            (() => {
                const body = document.querySelector('.rg-admin-drawer__body')

                return {
                    width: Math.round(document.querySelector('.rg-admin-drawer').getBoundingClientRect().width),
                    scrolls: body.scrollHeight > body.clientHeight,
                    footer: Math.round(document.querySelector('.rg-admin-drawer__footer').getBoundingClientRect().bottom) === window.innerHeight,
                }
            })()
        JS))->toBe(['width' => 448, 'scrolls' => true, 'footer' => true]);

    $page->keys(':focus', 'Escape');
    waitForScript($page, '! document.querySelector(".rg-admin-drawer") && document.activeElement === document.querySelector("[data-kit-drawer]")');

    expect(adminUiKitOverlays($page)['drawer'])->toBeFalse()
        ->and($page->script("document.activeElement === document.querySelector('[data-kit-drawer]')"))->toBeTrue();
});

it('shows a real toast, which Dismiss removes', function () {
    $page = adminUiKitAt('FBK-01', 'Array.isArray(Alpine.$data(document.querySelector(".rg-admin-toast-stack")).toasts)');

    $page->click('[data-kit-toast="success"]');
    waitForScript($page, adminUiKitToastCount(), 1);

    expect(adminUiKitOverlays($page)['toasts'])->toBe(['3 posts approved and published']);

    $page->click('.rg-admin-toast-stack button[aria-label="Dismiss"]');
    waitForScript($page, adminUiKitToastCount(), 0);

    expect(adminUiKitOverlays($page)['toasts'])->toBe([]);
});

it('announces an error assertively and the rest politely, once each', function () {
    $page = adminUiKitAt('FBK-01', 'Array.isArray(Alpine.$data(document.querySelector(".rg-admin-toast-stack")).toasts)');

    // Each region is written a moment after it is cleared, so a repeated message is heard again.
    $page->click('[data-kit-toast="error"]');
    waitForScript($page, 'document.querySelector(".rg-admin-toast-stack [role=alert]").textContent.trim() !== ""');
    $page->click('[data-kit-toast="info"]');
    waitForScript($page, 'document.querySelector(".rg-admin-toast-stack [role=status]").textContent.trim() !== ""');

    expect($page->script(<<<'JS'
        (() => ({
            alert: document.querySelector('.rg-admin-toast-stack [role="alert"]').textContent.trim(),
            status: document.querySelector('.rg-admin-toast-stack [role="status"]').textContent.trim(),
            liveToasts: document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast[role], .rg-admin-toast-stack .rg-admin-toast [aria-live]').length,
        }))()
    JS))->toBe([
        'alert' => 'PurgeMediaAsset failed again: asset is still referenced',
        'status' => 'Regeneration dispatched for A-209452',
        'liveToasts' => 0,
    ]);
});

it('takes a toast away when its time is up, and not while it is being read', function () {
    $page = adminUiKitAt('FBK-01', 'Array.isArray(Alpine.$data(document.querySelector(".rg-admin-toast-stack")).toasts)');

    $page->script("window.dispatchEvent(new CustomEvent('rg-admin-toast', { detail: { message: 'Short-lived', duration: 300 } }))");
    // Halfway through its 300 ms: still there, not taken away early.
    $page->wait(0.15);
    expect(adminUiKitOverlays($page)['toasts'])->toBe(['Short-lived']);
    waitForScript($page, adminUiKitToastCount(), 0);
    expect(adminUiKitOverlays($page)['toasts'])->toBe([]);

    $page->script("window.dispatchEvent(new CustomEvent('rg-admin-toast', { detail: { message: 'Being read', duration: 400 } }))");
    waitForScript($page, adminUiKitToastCount(), 1);
    $page->hover('.rg-admin-toast-stack .rg-admin-toast');
    // Twice its 400 ms under the pointer; nothing marks it not being taken away, so the test waits that long.
    $page->wait(0.8);
    expect(adminUiKitOverlays($page)['toasts'])->toBe(['Being read']);

    $page->hover('.rg-admin-kit__title');
    waitForScript($page, adminUiKitToastCount(), 0);
    expect(adminUiKitOverlays($page)['toasts'])->toBe([]);
});

it('moves a segmented control with the arrow keys, Home and End, one tab stop for the group', function () {
    $page = adminUiKitAt('FRM-08', 'Alpine.$data(document.getElementById("kit-segmented")).selected === "missing"');
    $checked = '#kit-segmented [aria-checked="true"]';
    // Focus follows the choice a tick later, the last thing a key press does.
    $chosen = fn (string $label) => waitForScript($page, "document.querySelector('{$checked}').textContent.trim() === '{$label}' && document.activeElement?.textContent.trim() === '{$label}'");
    $state = fn (): array => $page->script(<<<'JS'
        (() => ({
            checked: document.querySelector('#kit-segmented [aria-checked="true"]').textContent.trim(),
            focused: document.activeElement?.textContent.trim(),
            tabStops: [...document.querySelectorAll('#kit-segmented [role="radio"]')].filter((radio) => radio.tabIndex === 0).length,
            weight: getComputedStyle(document.querySelector('#kit-segmented [aria-checked="true"]')).fontWeight,
            caption: document.querySelector('#kit-segmented').nextElementSibling.textContent.trim(),
        }))()
    JS);

    $page->keys($checked, 'ArrowRight');
    $chosen('All');
    expect($state())->toMatchArray(['checked' => 'All', 'focused' => 'All', 'tabStops' => 1, 'weight' => '500', 'caption' => 'default · mode = all']);

    $page->keys(':focus', 'ArrowRight');
    $chosen('Missing only');
    expect($state())->toMatchArray(['checked' => 'Missing only', 'focused' => 'Missing only']);

    $page->keys(':focus', 'End');
    $chosen('All');
    expect($state()['checked'])->toBe('All');

    $page->keys(':focus', 'Home');
    $chosen('Missing only');
    expect($state())->toMatchArray(['checked' => 'Missing only', 'caption' => 'default · mode = missing']);
});

it('opens a filter dropdown on its checked value, moves through it and chooses from the keyboard', function () {
    $page = adminUiKitAt('FRM-10', 'Alpine.$data(document.getElementById("kit-filter-section")).expanded === false');
    // Opening moves focus into the menu a tick after it is drawn; choosing or closing returns it to the button.
    $inMenu = fn () => waitForScript($page, '!! document.activeElement?.closest("#kit-filter-section-menu")');
    $onItem = fn (string $label) => waitForScript($page, 'document.activeElement?.querySelector(".rg-admin-filter-dropdown__label")?.textContent.trim()', $label);
    $backOnButton = fn () => waitForScript($page, 'document.activeElement?.id === "kit-filter-section" && document.activeElement.getAttribute("aria-expanded") === "false"');

    $page->keys('#kit-filter-section', 'Enter');
    $inMenu();

    expect($page->script("({ expanded: document.getElementById('kit-filter-section').getAttribute('aria-expanded'), focused: document.activeElement?.innerText.trim() })"))
        ->toBe(['expanded' => 'true', 'focused' => 'All sections']);

    $page->keys(':focus', 'ArrowDown');
    $onItem('Project Settings');
    $page->keys(':focus', 'ArrowDown');
    $onItem('Static Pages');
    $page->keys(':focus', 'Enter');
    $backOnButton();

    expect($page->script("({ expanded: document.getElementById('kit-filter-section').getAttribute('aria-expanded'), focused: document.activeElement?.id, trigger: document.getElementById('kit-filter-section').innerText.trim() })"))
        ->toBe(['expanded' => 'false', 'focused' => 'kit-filter-section', 'trigger' => 'Section: Static Pages']);

    $page->keys('#kit-filter-section', 'Enter');
    $inMenu();
    $page->keys(':focus', 'Escape');
    $backOnButton();

    expect($page->script("({ expanded: document.getElementById('kit-filter-section').getAttribute('aria-expanded'), focused: document.activeElement?.id })"))
        ->toBe(['expanded' => 'false', 'focused' => 'kit-filter-section']);
});

it('searches and chooses in the combobox, which takes the value when nothing cancels it', function () {
    $page = adminUiKitAt('FRM-11', 'Alpine.$data(document.getElementById("kit-combobox-trigger")).expanded === false');
    // Opening moves focus into the search a tick after the list is drawn; choosing or closing returns it to the trigger.
    $inSearch = fn () => waitForScript($page, 'document.activeElement?.id', 'kit-combobox-search');
    $backOnTrigger = fn () => waitForScript($page, 'document.activeElement?.id === "kit-combobox-trigger" && document.activeElement.getAttribute("aria-expanded") === "false"');

    $page->keys('#kit-combobox-trigger', 'Space');
    $inSearch();
    expect($page->script('document.activeElement?.id'))->toBe('kit-combobox-search');

    // Enter picks the active option, which follows the search a tick after the list is filtered.
    $page->typeSlowly('#kit-combobox-search', 'deu', 30);
    waitForScript($page, <<<'JS'
        (() => {
            const shown = [...document.querySelectorAll('#kit-combobox-listbox [role=option]')].filter((option) => option.style.display !== 'none')

            return shown.length === 1 && shown[0].dataset.active === 'true'
        })()
    JS);
    expect($page->script("[...document.querySelectorAll('#kit-combobox-listbox [role=option]')].filter((option) => option.style.display !== 'none').map((option) => option.dataset.value)"))->toBe(['de']);

    $page->keys('#kit-combobox-search', 'Enter');
    $backOnTrigger();

    expect($page->script("({ focused: document.activeElement?.id, value: document.getElementById('kit-combobox-trigger').innerText, caption: document.getElementById('kit-combobox-trigger').closest('.rg-admin-kit__stack').querySelector('.rg-admin-kit__caption').textContent.trim() })"))
        ->toMatchArray(['focused' => 'kit-combobox-trigger', 'caption' => 'language = de'])
        ->and($page->script("document.getElementById('kit-combobox-trigger').innerText"))->toContain('German — Deutsch')->toContain('Disabled');

    // A search that matches nothing says so.
    $page->keys('#kit-combobox-trigger', 'Enter');
    $inSearch();
    $page->typeSlowly('#kit-combobox-search', 'klingon', 30);
    waitForScript($page, 'document.querySelector("#kit-combobox-popover .rg-admin-combobox__empty").textContent !== ""');

    expect($page->script("document.querySelector('#kit-combobox-popover .rg-admin-combobox__empty').textContent"))->toBe('No installed language matches.');

    $page->keys('#kit-combobox-search', 'Escape');
    $backOnTrigger();
    expect($page->script('document.activeElement?.id'))->toBe('kit-combobox-trigger');
});
