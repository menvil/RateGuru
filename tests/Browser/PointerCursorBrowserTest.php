<?php

use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Everything a visitor can press or toggle shows the pointer — Tailwind 4
 * leaves buttons on the default arrow, so this is checked in a real browser
 * against the computed cursor, not against the markup.
 */

/** The visible controls on the page whose cursor is neither the pointer nor a deliberate zoom-in. */
function controlsWithoutPointer(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => [...document.querySelectorAll('a[href], button, [role="button"], select, summary, input[type="checkbox"], input[type="radio"], input[type="file"], label')]
            .filter((el) => el.offsetParent !== null && !el.disabled)
            .filter((el) => el.tagName !== 'LABEL' || el.querySelector('input[type="checkbox"], input[type="radio"]'))
            .filter((el) => !['pointer', 'zoom-in'].includes(getComputedStyle(el).cursor))
            .map((el) => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} "${(el.innerText || '').trim().slice(0, 40)}" (${getComputedStyle(el).cursor})`))()
    JS);
}

it('shows the pointer on every control of the feed', function () {
    Post::factory()->count(2)->published()->withImage()->create();

    expect(controlsWithoutPointer(visit(route('feed'))->resize(1440, 900)->wait(0.3)))->toBe([]);
});

it('shows the pointer on the profile controls, including link-styled buttons, checkboxes and the file picker', function () {
    actingAs(User::factory()->unverified()->create());

    $page = visit(route('profile.edit'))->resize(1440, 900)->wait(0.3);

    expect(controlsWithoutPointer($page))->toBe([])
        ->and($page->script(<<<'JS'
            (() => ({
                checkbox: getComputedStyle(document.querySelector('#notify_followed_author_posts')).cursor,
                checkboxLabel: getComputedStyle(document.querySelector('label[for="notify_followed_author_posts"]')).cursor,
                avatarPicker: getComputedStyle(document.querySelector('#edit-avatar')).cursor,
                avatarButton: getComputedStyle(document.querySelector('#edit-avatar'), '::file-selector-button').cursor,
                resendVerification: getComputedStyle(document.querySelector('button[form="send-verification"]')).cursor,
                language: getComputedStyle(document.querySelector('#locale')).cursor,
            }))()
        JS))->toBe([
            'checkbox' => 'pointer',
            'checkboxLabel' => 'pointer',
            'avatarPicker' => 'pointer',
            'avatarButton' => 'pointer',
            'resendVerification' => 'pointer',
            'language' => 'pointer',
        ]);
});

it('shows the pointer on the admin table checkboxes and the settings selects', function (string $path) {
    actingAs(User::factory()->admin()->create());
    Post::factory()->count(3)->published()->withImage()->create();

    expect(controlsWithoutPointer(visit($path)->resize(1440, 900)->wait(0.5)))->toBe([]);
})->with([
    'posts table' => '/admin/posts',
    'project settings' => '/admin/project-settings',
]);

it('never shows the pointer on a disabled button, whatever classes it carries', function (string $route) {
    // Every distinct button the page renders, disabled as it is: its own
    // classes (cursor-pointer among them) must not bring the pointer back.
    actingAs(User::factory()->unverified()->create());
    Post::factory()->published()->withImage()->create();

    $page = visit(route($route))->resize(1440, 900)->wait(0.3);

    $result = $page->script(<<<'JS'
        (() => {
            const seen = new Set();
            const offenders = [];

            for (const button of document.querySelectorAll('button')) {
                if (button.offsetParent === null || seen.has(button.className)) continue;
                seen.add(button.className);

                const disabled = button.cloneNode(true);
                disabled.disabled = true;
                button.parentNode.append(disabled);

                const cursor = getComputedStyle(disabled).cursor;
                disabled.remove();

                if (cursor === 'pointer') offenders.push(button.getAttribute('data-testid') || button.className.toString().slice(0, 60));
            }

            return {checked: seen.size, offenders};
        })()
    JS);

    expect($result['offenders'])->toBe([])
        ->and($result['checked'])->toBeGreaterThan(3);
})->with(['feed', 'profile.edit']);
