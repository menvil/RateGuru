<?php

/*
 * The site's icons: declared in the head of every layout, shipped in public/,
 * and each image the size its declaration says it is.
 */

it('declares the site icons in the head of the app and the guest layout', function (string $url) {
    $links = collect(livewireDom($this->get($url)->assertOk()->getContent())
        ->query('//head/link[@rel="icon" or @rel="apple-touch-icon" or @rel="manifest"]'))
        ->map(fn (DOMElement $link): string => $link->getAttribute('rel').' '.$link->getAttribute('href'))
        ->all();

    expect($links)->toBe([
        'icon '.asset('favicon.ico'),
        'icon '.asset('favicon.svg'),
        'icon '.asset('favicon-32x32.png'),
        'apple-touch-icon '.asset('apple-touch-icon.png'),
        'manifest '.asset('site.webmanifest'),
    ]);
})->with([
    'the feed, on the app layout' => ['/'],
    'the sign-up page, on the guest layout' => ['/register'],
]);

it('ships each declared icon at the size it is declared at', function () {
    expect(file_get_contents(public_path('favicon.ico'), length: 4))->toBe("\0\0\1\0")
        ->and(file_get_contents(public_path('favicon.svg')))->toStartWith('<svg')
        ->and(array_slice(getimagesize(public_path('favicon-32x32.png')), 0, 2))->toBe([32, 32])
        ->and(array_slice(getimagesize(public_path('apple-touch-icon.png')), 0, 2))->toBe([180, 180]);
});

it('lists only icons the manifest really has, at the sizes it gives', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['icons'])->not->toBeEmpty();

    foreach ($manifest['icons'] as $icon) {
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));

        expect("{$width}x{$height}")->toBe($icon['sizes'], $icon['src']);
    }
});
