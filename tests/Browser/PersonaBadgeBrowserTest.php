<?php

use App\Models\Post;
use App\Models\User;
use Tests\Browser\Support\MobileViewports;

/**
 * The AI persona badge in a real browser: beside a Persona's username in the
 * feed and on its post's page, and never pushed out of sight by a long one —
 * only the username is cut short, the badge and the time stay whole, on a
 * phone too, and the page never scrolls sideways.
 */
function personaBadgeOnScreen(): string
{
    return <<<'JS'
        (() => {
            const badge = document.querySelector('[data-testid="persona-badge"]');
            const line = badge?.parentElement;
            const name = line?.firstElementChild;
            const box = badge?.getBoundingClientRect();
            const lineBox = line?.getBoundingClientRect();

            return {
                shown: !! badge && box.width > 0 && box.left >= lineBox.left - 1 && box.right <= lineBox.right + 1 && box.right <= window.innerWidth,
                text: badge?.firstChild.textContent.trim(),
                nameCut: !! name && name.scrollWidth > name.clientWidth,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS;
}

it('keeps the badge in view beside a long persona username, cutting the username short instead', function (string $route) {
    $persona = User::factory()->persona()->create([
        'username' => 'an_exceptionally_long_persona_nm',
        'name' => 'Velvet Footnote',
        'display_name' => 'Velvet Footnote',
    ]);
    $post = Post::factory()->published()->for($persona, 'user')->create(['title' => 'A harbour at dawn']);

    $page = visit($route === 'feed' ? route('feed') : route('posts.show', $post))->resize(...MobileViewports::SMALL_MOBILE);
    waitForViewportSize($page, ...MobileViewports::SMALL_MOBILE);
    waitForScript($page, "!! document.querySelector('[data-testid=\"persona-badge\"]')");

    expect($page->script(personaBadgeOnScreen()))->toBe([
        'shown' => true,
        'text' => 'AI persona',
        'nameCut' => true,
        'overflow' => false,
    ]);
})->with(['feed', 'post']);
