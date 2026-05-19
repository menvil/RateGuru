<?php

use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Livewire\Reports\ReportModal;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('submits comment report from report modal', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'comment',
            'reportableId' => $comment->id,
        ])
        ->set('reason', ReportReason::Offensive->value)
        ->call('submit')
        ->assertSet('submitted', true);

    $this->assertDatabaseHas('reports', [
        'reporter_id' => $user->id,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'reason' => ReportReason::Offensive->value,
    ]);
});

it('rejects unsupported reportable type', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'user',
            'reportableId' => $user->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertStatus(404);

    $this->assertDatabaseCount('reports', 0);
});

it('does not let a guest create a report via the submit action', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertSet('submitted', false)
        ->assertHasErrors('report')
        ->assertNotDispatched('content-reported');

    $this->assertDatabaseCount('reports', 0);
});

it('cannot report a hidden comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Hidden,
    ]);

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'comment',
            'reportableId' => $comment->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertStatus(404);

    $this->assertDatabaseCount('reports', 0);
});

it('dispatches content-reported after successful submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertDispatched('content-reported');
});

it('submits post report from report modal', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->set('message', 'This looks like spam.')
        ->call('submit')
        ->assertSet('submitted', true);

    $this->assertDatabaseHas('reports', [
        'reporter_id' => $user->id,
        'target_type' => Post::class,
        'target_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'message' => 'This looks like spam.',
    ]);
});

it('renders report message textarea', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('name="message"', false)
        ->assertSee('maxlength="1000"', false)
        ->assertSee('Optional details')
        ->set('message', 'This content is spam.')
        ->assertSet('message', 'This content is spam.');
});

it('renders report reason selector and updates reason', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('data-testid="report-reason-selector"', false)
        ->assertSee('name="reason"', false)
        ->assertSet('reason', '')
        ->set('reason', ReportReason::Spam->value)
        ->assertSet('reason', ReportReason::Spam->value);
});

it('has alpine report modal open close behavior', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('x-data', false)
        ->assertSee('reportOpen', false)
        ->assertSee('x-show', false)
        ->assertSee('x-cloak', false)
        ->assertSee('@keydown.escape.window', false)
        ->assertSee('data-testid="open-report-modal"', false)
        ->assertSee('data-testid="close-report-modal"', false);
});

it('renders report reasons', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('Spam')
        ->assertSee('Offensive')
        ->assertSee('Fake')
        ->assertSee('Copyright')
        ->assertSee('Not food')
        ->assertSee('Other');
});

it('can render report modal component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])->assertStatus(200)
        ->assertSee('data-testid="report-modal"', false);
});
