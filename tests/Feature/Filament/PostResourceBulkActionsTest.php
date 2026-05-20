<?php

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('bulk-hides selected published posts via HidePostAction', function () {
    $moderator = User::factory()->moderator()->create();
    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->callTableBulkAction('bulkHide', [$first, $second], data: ['reason' => 'Bulk moderation.']);

    expect($first->fresh()->status)->toBe(PostStatus::Hidden)
        ->and($second->fresh()->status)->toBe(PostStatus::Hidden);

    $this->assertDatabaseHas('moderation_logs', [
        'target_type' => Post::class,
        'target_id' => $first->id,
    ]);
    $this->assertDatabaseHas('moderation_logs', [
        'target_type' => Post::class,
        'target_id' => $second->id,
    ]);
});

it('bulk hide leaves non-published records untouched', function () {
    $moderator = User::factory()->moderator()->create();
    $pending = Post::factory()->pending()->create();
    $published = Post::factory()->published()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->callTableBulkAction('bulkHide', [$pending, $published], data: ['reason' => 'Mixed selection.']);

    expect($pending->fresh()->status)->toBe(PostStatus::Pending)
        ->and($published->fresh()->status)->toBe(PostStatus::Hidden);
});
