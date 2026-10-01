<?php

use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('draws the records-per-page chooser without a frame, and without a focus ring once a value is picked', function () {
    actingAs(User::factory()->admin()->create());
    Post::factory()->count(12)->published()->create();

    $page = visit('/admin/posts')->resize(1440, 900)->wait(0.5);

    expect($page->script(<<<'JS'
        (() => {
            const select = [...document.querySelectorAll('.fi-pagination-records-per-page-select select')].find((el) => el.offsetParent !== null);
            select.focus();
            const wrapper = select.closest('.fi-input-wrp');

            return {
                focused: document.activeElement === select,
                frame: getComputedStyle(wrapper).boxShadow,
                outline: getComputedStyle(select).outlineStyle,
                cursor: getComputedStyle(select).cursor,
            };
        })()
    JS))->toBe([
        'focused' => true,
        'frame' => 'none',
        'outline' => 'none',
        'cursor' => 'pointer',
    ]);
});
