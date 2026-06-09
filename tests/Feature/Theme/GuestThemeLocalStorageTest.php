<?php

it('theme switcher persists guest preference to local storage via bootstrap script', function () {
    $content = file_get_contents(resource_path('js/theme-bootstrap.js'));

    expect($content)->toContain('rateguru.theme.preference');
    expect($content)->toContain('localStorage');
});

it('theme switcher uses global rgSetTheme function for localStorage persistence', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toContain('rgSetTheme');
    expect($appJs)->toContain('localStorage.setItem');
    expect($appJs)->toContain('rateguru.theme.preference');
});

it('theme switcher view calls rgSetTheme on click', function () {
    $content = file_get_contents(resource_path('views/livewire/theme/theme-switcher.blade.php'));

    expect($content)->toContain('rgSetTheme');
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
