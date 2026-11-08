<?php

use App\Models\ProjectSettings;

/*
 * The site's icons: declared in the head of every layout, shipped in public/,
 * each image the size its declaration says it is, and the web manifest named
 * after the project.
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
        'manifest '.route('site-manifest'),
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

it('names the web manifest after the project in its settings', function () {
    ProjectSettings::factory()->create(['site_name' => 'Crown Club']);

    $this->get('/site.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJson([
            'name' => 'Crown Club',
            'short_name' => 'Crown Club',
            'start_url' => '/',
            'display' => 'standalone',
        ]);

    // Served by the application, never shadowed by a file of the same name.
    expect(file_exists(public_path('site.webmanifest')))->toBeFalse();
});

it('lists only icons the site really has, at the sizes the manifest gives', function () {
    $icons = $this->get(route('site-manifest'))->assertOk()->json('icons');

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        [$width, $height] = getimagesize(public_path(ltrim((string) parse_url($icon['src'], PHP_URL_PATH), '/')));

        expect("{$width}x{$height}")->toBe($icon['sizes'], $icon['src']);
    }
});
