<?php

it('has url import requirements audit document', function () {
    $path = base_path('docs/import/phase-50-url-import-audit.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('SSRF');
    expect($content)->toContain('OpenGraph');
    expect($content)->toContain('Instagram');
    expect($content)->toContain('unsupported');
});
