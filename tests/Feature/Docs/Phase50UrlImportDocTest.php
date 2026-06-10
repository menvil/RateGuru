<?php

it('has url import documentation', function () {
    $path = base_path('docs/import/url-import.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Direct image URL');
    expect($content)->toContain('OpenGraph');
    expect($content)->toContain('SSRF');
    expect($content)->toContain('Instagram');
});
