<?php

it('has phase 50 url import review checklist', function () {
    $path = base_path('docs/import/phase-50-url-import-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('UrlImportValidator');
    expect($content)->toContain('SafeImportHttpClient');
    expect($content)->toContain('OpenGraphImportAdapter');
    expect($content)->toContain('no OAuth');
});
