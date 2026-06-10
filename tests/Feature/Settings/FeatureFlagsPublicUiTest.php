<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

it('hides comments when comments feature flag is disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_comments' => false,
            'show_share_buttons' => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts' => false,
            'allow_user_uploads' => true,
            'allow_guest_viewing' => true,
        ],
    ]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertDontSee('data-testid="comment-form"', false);
});

it('hides share buttons when share feature flag is disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_comments' => true,
            'show_share_buttons' => false,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts' => false,
            'allow_user_uploads' => true,
            'allow_guest_viewing' => true,
        ],
    ]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertDontSee('data-testid="post-card-share"', false);
});

it('hides upload button when allow_user_uploads is disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_comments' => true,
            'show_share_buttons' => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts' => false,
            'allow_user_uploads' => false,
            'allow_guest_viewing' => true,
        ],
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertDontSee('data-testid="open-upload-button"', false);
});

it('shows upload button when allow_user_uploads is enabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'allow_user_uploads' => true,
        ],
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="open-upload-button"', false);
});
