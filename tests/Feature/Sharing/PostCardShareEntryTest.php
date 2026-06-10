<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use Illuminate\Support\Facades\Blade;

it('renders compact share entry on post card when feature enabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_share_buttons' => true,
        ],
    ]);

    $post = Post::factory()->published()->create();

    $view = Blade::render(
        '<x-feed.post-card :post="$post" :selected="false" :canReportPost="false" :canDeletePost="false" :canModeratePost="false" :ratingVotingState="[]" />',
        ['post' => $post]
    );

    expect($view)->toContain('data-testid="post-card-share"');
});

it('hides compact share entry on post card when feature disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_share_buttons' => false,
        ],
    ]);

    $post = Post::factory()->published()->create();

    $view = Blade::render(
        '<x-feed.post-card :post="$post" :selected="false" :canReportPost="false" :canDeletePost="false" :canModeratePost="false" :ratingVotingState="[]" />',
        ['post' => $post]
    );

    expect($view)->not->toContain('data-testid="post-card-share"');
});

it('post card share modal contains share buttons component', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_share_buttons' => true,
        ],
    ]);

    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render(
        '<x-feed.post-card :post="$post" :selected="false" :canReportPost="false" :canDeletePost="false" :canModeratePost="false" :ratingVotingState="[]" />',
        ['post' => $post]
    );

    expect($view)->toContain('data-testid="share-buttons"');
    expect($view)->toContain('data-testid="share-copy-link"');
});
