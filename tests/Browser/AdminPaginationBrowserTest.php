<?php

use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

/** The look of the visible records-per-page select, and whether it has the focus. */
function perPageSelect(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const select = [...document.querySelectorAll('.fi-pagination-records-per-page-select select')].find((el) => el.offsetParent !== null);

            return {
                focused: document.activeElement === select,
                focusVisible: select.matches(':focus-visible'),
                frame: getComputedStyle(select.closest('.fi-input-wrp')).boxShadow,
                outline: getComputedStyle(select).outlineStyle,
                cursor: getComputedStyle(select).cursor,
            };
        })()
    JS);
}

it('draws the records-per-page chooser without a frame or a focus ring, when clicked and after a value is picked', function () {
    actingAs(User::factory()->admin()->create());
    Post::factory()->count(30)->published()->create();
    $select = '.fi-pagination-records-per-page-select:not(.fi-compact) select';

    $page = visit('/admin/posts')->resize(1440, 900)->wait(0.5);
    $page->click($select);

    // A mouse click is what puts the ring on a select — it matches
    // :focus-visible as well — so this is the state the ring showed in.
    expect(perPageSelect($page))->toBe([
        'focused' => true,
        'focusVisible' => true,
        'frame' => 'none',
        'outline' => 'none',
        'cursor' => 'pointer',
    ]);

    $page->select($select, '25')->wait(0.5);

    expect(perPageSelect($page))
        ->frame->toBe('none')
        ->outline->toBe('none')
        ->cursor->toBe('pointer');
});
