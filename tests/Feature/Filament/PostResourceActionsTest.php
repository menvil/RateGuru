<?php

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('approves a pending post via the approve table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->callTableAction('approve', $post, data: ['reason' => 'Valid post.']);

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'target_type' => Post::class,
        'target_id' => $post->id,
        'moderator_id' => $moderator->id,
    ]);
});

it('hides the approve action for non-pending posts', function () {
    $moderator = User::factory()->moderator()->create();
    $published = Post::factory()->published()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->assertTableActionHidden('approve', $published);
});
