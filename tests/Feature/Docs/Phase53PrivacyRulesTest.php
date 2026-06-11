<?php

it('has profile privacy rules document', function () {
    $path = base_path('docs/profile/profile-privacy-rules.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('saved posts');
    expect($content)->toContain('private by default');
    expect($content)->toContain('rating activity');
    expect($content)->toContain('public posts');
});
