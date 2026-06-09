<?php

it('PR template exists', function () {
    expect(file_exists(base_path('.github/pull_request_template.md')))->toBeTrue();
});

it('PR template contains mobile QA checklist section', function () {
    $content = file_get_contents(base_path('.github/pull_request_template.md'));

    expect($content)->toContain('Mobile QA');
});

it('PR template mobile checklist references 375px', function () {
    $content = file_get_contents(base_path('.github/pull_request_template.md'));

    expect($content)->toContain('375px');
});
