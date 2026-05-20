<?php

use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('allows moderator to hide a visible comment via the hide table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create(['comments_count' => 1]);
    $comment = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Visible,
        'body' => 'Bad comment',
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListComments::class)
        ->callTableAction('hide', $comment, data: ['reason' => 'Abusive language.']);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
    expect($post->fresh()->comments_count)->toBe(0);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::HideComment->value,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'reason' => 'Abusive language.',
    ]);
});

it('shows the hide action only for visible comments', function () {
    $moderator = User::factory()->moderator()->create();
    $hidden = Comment::factory()->create(['status' => CommentStatus::Hidden]);

    $this->actingAs($moderator);

    Livewire::test(ListComments::class)
        ->assertTableActionHidden('hide', $hidden);
});

it('hides the hide action from normal users', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);

    $this->actingAs($user)
        ->get(\App\Filament\Resources\Comments\CommentResource::getUrl('index'))
        ->assertForbidden();
});
