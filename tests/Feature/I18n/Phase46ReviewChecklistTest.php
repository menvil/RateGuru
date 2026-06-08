<?php

it('has phase 46 multilingual ui review checklist', function () {
    $path = base_path('docs/i18n/phase-46-multilingual-ui-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('LocaleManager');
    expect($content)->toContain('SetLocale');
    expect($content)->toContain('language switcher');
    expect($content)->toContain('translation keys');
});
