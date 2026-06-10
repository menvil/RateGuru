<?php

use Illuminate\Support\Facades\Blade;

it('has native web share javascript support', function () {
    $path = resource_path('js/share.js');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('navigator.share');
});

it('native share js exposes rgNativeShare window function with supported property', function () {
    $content = file_get_contents(resource_path('js/share.js'));

    // Verify supported property is declared inside the rgNativeShare object, not just anywhere
    expect($content)->toMatch('/rgNativeShare.*?supported\s*:/s');
});

it('renders native share button marker in component', function () {
    $html = Blade::render(
        '<x-share.native-share-button title="Test" text="Test" url="https://rateguru.test/posts/1" />'
    );

    expect($html)->toContain('data-testid="share-native"');
});

it('native share button component uses rgNativeShare alpine data', function () {
    $html = Blade::render(
        '<x-share.native-share-button title="My Post" text="Check this out" url="https://rateguru.test/posts/1" />'
    );

    expect($html)->toContain('rgNativeShare');
    expect($html)->toContain('x-show="supported"');
});
