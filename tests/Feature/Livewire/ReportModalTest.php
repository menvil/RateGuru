<?php

use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Livewire\Reports\ReportModal;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('renders validation error when reason is missing', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', '')
        ->call('submit')
        ->assertHasErrors(['reason'])
        ->assertSee('data-testid="report-reason-error"', false);

    $this->assertDatabaseCount('reports', 0);
});

it('renders validation error when message is too long', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->set('message', str_repeat('a', 1001))
        ->call('submit')
        ->assertHasErrors(['message'])
        ->assertSee('data-testid="report-message-error"', false);

    $this->assertDatabaseCount('reports', 0);
});

it('renders backend error for duplicate report', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $component = Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit');

    $component
        ->set('submitted', false)
        ->call('submit')
        ->assertHasErrors('report')
        ->assertSee('data-testid="report-submit-error"', false)
        ->assertSee('You have already reported this content.');
});

it('shows report rate limit error in report modal', function () {
    config()->set('rate_limits.report.max_attempts', 1);
    config()->set('rate_limits.report.decay_seconds', 600);

    $user = User::factory()->create();
    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $first->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertSet('submitted', true);

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $second->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertSet('submitted', false)
        ->assertHasErrors('report')
        ->assertSee('You are reporting too quickly. Please try again later.');
});

it('renders report success state after submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertSee('Report submitted')
        ->assertSee('Thanks for helping')
        ->assertDontSee('data-testid="report-reason-selector"', false);
});

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
        ->assertSee('data-testid="report-button"', false)
        ->assertSee('Close modal')
        ->assertDontSee('data-testid="close-report-modal"', false);
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

it('renders compact report trigger with symmetric menu padding support', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('class="leading-none"', false)
        ->assertSee('inline-flex h-5', false)
        ->assertSee('leading-none text-rg-muted', false);
});
