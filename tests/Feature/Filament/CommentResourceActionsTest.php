<?php

use App\Actions\Comments\HideCommentAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\User;
use Livewire\Livewire;

/**
 * Moderating a comment from its row: Hide after asking, with an optional
 * reason, and Restore at once, each through its action, which logs it — both
 * with Undo. Nothing deletes a comment from here.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->moderator()->create());
});

it('hides a visible comment after asking, with the reason when one is given, and Undo restores it', function (string $reason, ?string $logged) {
    $comment = Comment::factory()->create(['body' => 'Visit pet-deals24.top']);

    $page = Livewire::test(ListComments::class)
        ->call('askToHide', $comment->id)
        ->assertSet('confirming', ['action' => 'hide', 'id' => $comment->id])
        ->assertSeeHtml('Hide this comment?')
        ->assertSeeHtml('Spam')
        ->set('reason', $reason)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('confirming', null)
        ->assertDispatched('rg-admin-toast', message: 'Comment #'.$comment->id.' hidden', action: ['label' => 'Undo', 'event' => 'restore-comment', 'params' => ['id' => $comment->id]]);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden)
        ->and(ModerationLog::query()->where('action', ModerationActionType::HideComment)->sole()->reason)->toBe($logged);

    $page->dispatch('restore-comment', id: $comment->id)
        ->assertDispatched('rg-admin-toast', message: 'Comment #'.$comment->id.' is visible again');

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
})->with([
    'with a reason' => ['Spam', 'Spam'],
    'without one' => ['', null],
]);

it('restores a hidden comment at once, and Undo hides it again', function () {
    $comment = Comment::factory()->create(['status' => CommentStatus::Hidden]);

    $page = Livewire::withQueryParams(['status' => 'hidden'])->test(ListComments::class)
        ->call('restore', $comment->id)
        ->assertDispatched('rg-admin-toast', message: 'Comment #'.$comment->id.' restored', action: ['label' => 'Undo', 'event' => 'hide-comment-again', 'params' => ['id' => $comment->id]]);

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);

    $page->dispatch('hide-comment-again', id: $comment->id);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});

it('holds a reason to its limit', function () {
    $comment = Comment::factory()->create();

    Livewire::test(ListComments::class)
        ->call('askToHide', $comment->id)
        ->set('reason', str_repeat('r', ListComments::REASON_LIMIT + 1))
        ->call('confirm')
        ->assertHasErrors(['reason' => 'max']);

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
});

it('says so, and does nothing, when the comment changed meanwhile', function () {
    $comment = Comment::factory()->create();
    $page = Livewire::test(ListComments::class)->call('askToHide', $comment->id);
    app(HideCommentAction::class)->handle(User::factory()->moderator()->create(), $comment, 'Elsewhere');

    $page->call('confirm')
        ->assertDispatched('rg-admin-toast', message: 'This comment changed meanwhile, so nothing was done. The list now shows it as it is.', tone: 'error');

    expect(ModerationLog::query()->where('action', ModerationActionType::HideComment)->count())->toBe(1);
});

it('offers no way to delete a comment, not even to administrators', function () {
    $this->actingAs(User::factory()->admin()->create());
    $comment = Comment::factory()->create();

    expect(Livewire::test(ListComments::class)->html())->not->toContain('wire:click="delete');

    $this->assertNotSoftDeleted('comments', ['id' => $comment->id]);
});
