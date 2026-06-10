<?php

it('has current sharing flow audit document', function () {
    $path = base_path('docs/sharing/phase-49-sharing-audit.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('### canonical URL');
    expect($content)->toContain('### OpenGraph meta tags');
    expect($content)->toContain('### Share buttons');
});
