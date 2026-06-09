<?php

it('theme switcher persists guest preference to local storage via bootstrap script', function () {
    $path = resource_path('js/theme-bootstrap.js');

    $content = file_get_contents($path);

    expect($content)->toContain('rateguru.theme.preference');
    expect($content)->toContain('localStorage');
});

it('theme switcher view contains localStorage setItem call', function () {
    $content = file_get_contents(resource_path('views/livewire/theme/theme-switcher.blade.php'));

    expect($content)->toContain('localStorage.setItem');
    expect($content)->toContain('rateguru.theme.preference');
});

it('bootstrap script reads localStorage and applies theme before css renders', function () {
    $content = file_get_contents(resource_path('js/theme-bootstrap.js'));

    expect($content)->toContain('localStorage.getItem');
    expect($content)->toContain('dataset.theme');
});

it('bootstrap script handles system preference via matchMedia', function () {
    $content = file_get_contents(resource_path('js/theme-bootstrap.js'));

    expect($content)->toContain('prefers-color-scheme: dark');
    expect($content)->toContain('matchMedia');
});
