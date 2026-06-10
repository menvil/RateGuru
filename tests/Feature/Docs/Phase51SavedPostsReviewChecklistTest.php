<?php

it('has phase 51 saved posts review checklist', function () {
    $path = base_path('docs/saved-posts/phase-51-saved-posts-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('post_saves');
    expect($content)->toContain('private');
    expect($content)->toContain('SavePostAction');
    expect($content)->toContain('SavedPostsQuery');
});

it('has saved posts documentation', function () {
    $path = base_path('docs/saved-posts/saved-posts.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('post_saves');
    expect($content)->toContain('private');
    expect($content)->toContain('show_saved_posts');
});
