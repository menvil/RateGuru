<?php

use App\Models\Post;

/*
 * Inter is one variable WOFF2 per script. A page downloads the scripts its
 * text uses and no others, and every weight comes from that one file.
 */

/** The status of each Inter face once the page's fonts have settled, keyed by its unicode-range. */
function interFaceStatuses(mixed $page): array
{
    waitForScript($page, 'document.fonts.status === "loaded"');

    return $page->script(<<<'JS'
        Object.fromEntries([...document.fonts]
            .filter((face) => face.family.replaceAll('"', '') === 'Inter Variable')
            .map((face) => [face.unicodeRange, face.status]))
    JS);
}

function interFaceFor(array $statuses, string $codePoint): string
{
    foreach ($statuses as $range => $status) {
        if (preg_match('/(?:^|,\s*)U\+0*'.$codePoint.'(?:-|,|$)/i', $range) === 1) {
            return $status;
        }
    }

    throw new RuntimeException("No Inter face covers U+{$codePoint}.");
}

it('draws Cyrillic in Inter, fetching only the scripts the page shows and no WOFF fallbacks', function () {
    Post::factory()->published()->create(['title' => 'Закат над заливом']);

    $page = visit(route('feed'))->assertSee('Закат над заливом');
    $statuses = interFaceStatuses($page);

    expect(interFaceFor($statuses, '400'))->toBe('loaded', 'Cyrillic')
        ->and(interFaceFor($statuses, '0'))->toBe('loaded', 'Latin')
        ->and(interFaceFor($statuses, '370'))->toBe('unloaded', 'Greek, which the page does not show')
        ->and($page->script('performance.getEntriesByType("resource").filter((entry) => /\.woff(\?|$)/.test(entry.name)).length'))->toBe(0);
});
