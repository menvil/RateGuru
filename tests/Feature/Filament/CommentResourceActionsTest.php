<?php

use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Filament\Resources\Comments\CommentResource;
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
        ->get(CommentResource::getUrl('index'))
        ->assertForbidden();
});

it('allows moderator to restore a hidden comment via the restore table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create(['comments_count' => 0]);
    $comment = Comment::factory()->for($post)->create(['status' => CommentStatus::Hidden]);

    $this->actingAs($moderator);

    Livewire::test(ListComments::class)
        ->callTableAction('restore', $comment, data: ['reason' => 'Restored after review.']);

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
    expect($post->fresh()->comments_count)->toBe(1);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::RestoreComment->value,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'reason' => 'Restored after review.',
    ]);
});

it('shows the restore action only for hidden comments', function () {
    $moderator = User::factory()->moderator()->create();
    $visible = Comment::factory()->create(['status' => CommentStatus::Visible]);

    $this->actingAs($moderator);

    Livewire::test(ListComments::class)
        ->assertTableActionHidden('restore', $visible);
});

it('allows admin to delete a comment via the delete table action', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create(['comments_count' => 1]);
    $comment = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Visible,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->callTableAction('delete', $comment);

    $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    expect($post->fresh()->comments_count)->toBe(0);
});

it('hides the delete action from moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->create();

    $this->actingAs($moderator);

    Livewire::test(ListComments::class)
        ->assertTableActionHidden('delete', $comment);
});
