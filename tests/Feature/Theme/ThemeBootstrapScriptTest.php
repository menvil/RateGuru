<?php

it('has theme bootstrap script', function () {
    $path = resource_path('js/theme-bootstrap.js');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('localStorage');
    expect($content)->toContain('prefers-color-scheme');
    expect($content)->toContain('dataset.theme');
    expect($content)->toContain('rateguru.theme.preference');
});

it('theme bootstrap script contains localStorage.setItem', function () {
    $content = file_get_contents(resource_path('js/theme-bootstrap.js'));

    expect($content)->toContain('localStorage');
});

it('app layout includes theme bootstrap script inline', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('theme-bootstrap.js');
});
