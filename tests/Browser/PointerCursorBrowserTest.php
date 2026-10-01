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

it('keeps the arrow on a disabled button', function () {
    $page = visit(route('feed'))->resize(1440, 900)->wait(0.3);

    expect($page->script(<<<'JS'
        (() => { const button = document.createElement('button'); button.disabled = true; document.body.append(button); return getComputedStyle(button).cursor; })()
    JS))->not->toBe('pointer');
});
