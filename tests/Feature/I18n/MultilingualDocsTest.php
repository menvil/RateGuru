<?php

it('has multilingual ui documentation', function () {
    $path = base_path('docs/i18n/multilingual-ui.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('supported locales');
    expect($content)->toContain('LocaleManager');
    expect($content)->toContain('ProjectSettings');
    expect($content)->toContain('RatingGroup');
    expect($content)->toContain('not auto-translated');
});

it('has translatable settings documentation', function () {
    $path = base_path('docs/i18n/translatable-settings.md');

    expect(file_exists($path))->toBeTrue();
});
