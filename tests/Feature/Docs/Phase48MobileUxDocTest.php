<?php

it('mobile UX documentation file exists', function () {
    expect(file_exists(base_path('docs/mobile/mobile-ux-guidelines.md')))->toBeTrue();
});

it('mobile UX doc describes overflow prevention strategy', function () {
    $content = file_get_contents(base_path('docs/mobile/mobile-ux-guidelines.md'));

    expect($content)->toContain('overflow-hidden');
    expect($content)->toContain('min-w-0');
});

it('mobile UX doc documents tap target requirements', function () {
    $content = file_get_contents(base_path('docs/mobile/mobile-ux-guidelines.md'));

    expect($content)->toContain('40px');
});
