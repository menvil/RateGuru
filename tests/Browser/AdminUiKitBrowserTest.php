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

beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

it('opens each confirmation level, keeps focus inside and returns it to the opener', function (string $level) {
    $page = visit('/admin/dev/ui-kit#OVL-01')->resize(1440, 900)->wait(0.4);
    $opener = "[data-kit-dialog=\"{$level}\"]";

    $page->keys($opener, 'Enter')->wait(0.4);

    expect(adminUiKitOverlays($page))->toMatchArray(['dialog' => true, 'focusInside' => true, 'scrollLocked' => true]);

    foreach (range(1, 6) as $press) {
        $page->keys(':focus', 'Tab');
    }

    expect(adminUiKitOverlays($page)['focusInside'])->toBeTrue();

    $page->keys(':focus', 'Escape')->wait(0.4);

    expect(adminUiKitOverlays($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false])
        ->and($page->script("document.activeElement === document.querySelector('{$opener}')"))->toBeTrue();
})->with(['light', 'warning', 'blocked']);

it('confirms a specimen without leaving anything behind', function () {
    $page = visit('/admin/dev/ui-kit#OVL-01')->resize(1440, 900)->wait(0.4);

    $page->click('[data-kit-dialog="light"]')->wait(0.4);
    $page->click('.rg-admin-dialog .rg-admin-button--primary')->wait(0.4);

    expect(adminUiKitOverlays($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false]);
});

it('opens a 448 drawer whose body scrolls under its header and footer', function () {
    $page = visit('/admin/dev/ui-kit#OVL-02')->resize(1440, 900)->wait(0.4);

    $page->keys('[data-kit-drawer]', 'Enter')->wait(0.4);

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

    $page->keys(':focus', 'Escape')->wait(0.4);

    expect(adminUiKitOverlays($page)['drawer'])->toBeFalse()
        ->and($page->script("document.activeElement === document.querySelector('[data-kit-drawer]')"))->toBeTrue();
});

it('shows a real toast, which Dismiss removes', function () {
    $page = visit('/admin/dev/ui-kit#FBK-01')->resize(1440, 900)->wait(0.4);

    $page->click('[data-kit-toast="success"]')->wait(0.3);

    expect(adminUiKitOverlays($page)['toasts'])->toBe(['3 posts approved and published']);

    $page->click('.rg-admin-toast-stack button[aria-label="Dismiss"]')->wait(0.3);

    expect(adminUiKitOverlays($page)['toasts'])->toBe([]);
});

it('announces an error assertively and the rest politely, once each', function () {
    $page = visit('/admin/dev/ui-kit#FBK-01')->resize(1440, 900)->wait(0.4);

    $page->click('[data-kit-toast="error"]')->wait(0.3);
    $page->click('[data-kit-toast="info"]')->wait(0.3);

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
    $page = visit('/admin/dev/ui-kit#FBK-01')->resize(1440, 900)->wait(0.4);

    $page->script("window.dispatchEvent(new CustomEvent('rg-admin-toast', { detail: { message: 'Short-lived', duration: 300 } }))");
    $page->wait(0.15);
    expect(adminUiKitOverlays($page)['toasts'])->toBe(['Short-lived']);
    $page->wait(0.5);
    expect(adminUiKitOverlays($page)['toasts'])->toBe([]);

    $page->script("window.dispatchEvent(new CustomEvent('rg-admin-toast', { detail: { message: 'Being read', duration: 400 } }))");
    $page->wait(0.1);
    $page->hover('.rg-admin-toast-stack .rg-admin-toast');
    $page->wait(0.8);
    expect(adminUiKitOverlays($page)['toasts'])->toBe(['Being read']);

    $page->hover('.rg-admin-kit__title');
    $page->wait(0.8);
    expect(adminUiKitOverlays($page)['toasts'])->toBe([]);
});

it('moves a segmented control with the arrow keys, Home and End, one tab stop for the group', function () {
    $page = visit('/admin/dev/ui-kit#FRM-08')->resize(1440, 900)->wait(0.4);
    $checked = '#kit-segmented [aria-checked="true"]';
    $state = fn (): array => $page->script(<<<'JS'
        (() => ({
            checked: document.querySelector('#kit-segmented [aria-checked="true"]').textContent.trim(),
            focused: document.activeElement?.textContent.trim(),
            tabStops: [...document.querySelectorAll('#kit-segmented [role="radio"]')].filter((radio) => radio.tabIndex === 0).length,
            weight: getComputedStyle(document.querySelector('#kit-segmented [aria-checked="true"]')).fontWeight,
            caption: document.querySelector('#kit-segmented').nextElementSibling.textContent.trim(),
        }))()
    JS);

    $page->keys($checked, 'ArrowRight')->wait(0.2);
    expect($state())->toMatchArray(['checked' => 'All', 'focused' => 'All', 'tabStops' => 1, 'weight' => '500', 'caption' => 'default · mode = all']);

    $page->keys(':focus', 'ArrowRight')->wait(0.2);
    expect($state())->toMatchArray(['checked' => 'Missing only', 'focused' => 'Missing only']);

    $page->keys(':focus', 'End')->wait(0.2);
    expect($state()['checked'])->toBe('All');

    $page->keys(':focus', 'Home')->wait(0.2);
    expect($state())->toMatchArray(['checked' => 'Missing only', 'caption' => 'default · mode = missing']);
});

it('opens a filter dropdown on its checked value, moves through it and chooses from the keyboard', function () {
    $page = visit('/admin/dev/ui-kit#FRM-10')->resize(1440, 900)->wait(0.4);

    $page->keys('#kit-filter-section', 'Enter')->wait(0.3);

    expect($page->script("({ expanded: document.getElementById('kit-filter-section').getAttribute('aria-expanded'), focused: document.activeElement?.innerText.trim() })"))
        ->toBe(['expanded' => 'true', 'focused' => 'All sections']);

    $page->keys(':focus', 'ArrowDown')->wait(0.1);
    $page->keys(':focus', 'ArrowDown')->wait(0.1);
    $page->keys(':focus', 'Enter')->wait(0.3);

    expect($page->script("({ expanded: document.getElementById('kit-filter-section').getAttribute('aria-expanded'), focused: document.activeElement?.id, trigger: document.getElementById('kit-filter-section').innerText.trim() })"))
        ->toBe(['expanded' => 'false', 'focused' => 'kit-filter-section', 'trigger' => 'Section: Static Pages']);

    $page->keys('#kit-filter-section', 'Enter')->wait(0.3);
    $page->keys(':focus', 'Escape')->wait(0.3);

    expect($page->script("({ expanded: document.getElementById('kit-filter-section').getAttribute('aria-expanded'), focused: document.activeElement?.id })"))
        ->toBe(['expanded' => 'false', 'focused' => 'kit-filter-section']);
});

it('searches and chooses in the combobox, which takes the value when nothing cancels it', function () {
    $page = visit('/admin/dev/ui-kit#FRM-11')->resize(1440, 900)->wait(0.4);

    $page->keys('#kit-combobox-trigger', 'Space')->wait(0.3);
    expect($page->script('document.activeElement?.id'))->toBe('kit-combobox-search');

    $page->typeSlowly('#kit-combobox-search', 'deu', 30)->wait(0.3);
    expect($page->script("[...document.querySelectorAll('#kit-combobox-listbox [role=option]')].filter((option) => option.style.display !== 'none').map((option) => option.dataset.value)"))->toBe(['de']);

    $page->keys('#kit-combobox-search', 'Enter')->wait(0.3);

    expect($page->script("({ focused: document.activeElement?.id, value: document.getElementById('kit-combobox-trigger').innerText, caption: document.getElementById('kit-combobox-trigger').closest('.rg-admin-kit__stack').querySelector('.rg-admin-kit__caption').textContent.trim() })"))
        ->toMatchArray(['focused' => 'kit-combobox-trigger', 'caption' => 'language = de'])
        ->and($page->script("document.getElementById('kit-combobox-trigger').innerText"))->toContain('German — Deutsch')->toContain('Disabled');

    // A search that matches nothing says so.
    $page->keys('#kit-combobox-trigger', 'Enter')->wait(0.3);
    $page->typeSlowly('#kit-combobox-search', 'klingon', 30)->wait(0.3);

    expect($page->script("document.querySelector('#kit-combobox-popover .rg-admin-combobox__empty').textContent"))->toBe('No installed language matches.');

    $page->keys('#kit-combobox-search', 'Escape')->wait(0.3);
    expect($page->script('document.activeElement?.id'))->toBe('kit-combobox-trigger');
});
