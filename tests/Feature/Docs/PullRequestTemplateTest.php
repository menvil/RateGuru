<?php

it('has pull request template with visual review checklist', function () {
    $template = base_path('.github/pull_request_template.md');

    expect(file_exists($template))->toBeTrue();

    $content = file_get_contents($template);

    expect($content)->toContain('Visual Review')
        ->and($content)->toContain('visual:screenshot')
        ->and($content)->toContain('baselines')
        ->and($content)->toContain('browser smoke')
        ->and($content)->toContain('desktop feed')
        ->and($content)->toContain('mobile feed')
        ->and($content)->toContain('upload modal')
        ->and($content)->toContain('post drawer')
        ->and($content)->toContain('post show');
});
