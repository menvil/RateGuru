<?php

use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Filament\Pages\StudioActionQueue;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Studio\StudioExecutionSwitch;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Content Studio's Action queue: every action approved plans wrote, from the
 * business queue itself, narrowed and searched on the server, each with
 * Retry, Resume or Cancel as its state allows — Cancel asked first — and the
 * executor's switch, asked first too, for an administrator who may also
 * manage the project's settings.
 */
beforeEach(function () {
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('opens for an administrator only, and sits in Content Studio after Publications & plans', function () {
    $this->get(StudioActionQueue::getUrl())->assertOk()->assertSee('Action queue')->assertSee('Executor off.');

    $this->actingAs(User::factory()->moderator()->create())->get(StudioActionQueue::getUrl())->assertForbidden();
    $this->actingAs(User::factory()->create())->get(StudioActionQueue::getUrl())->assertForbidden();
});

it('draws each action: its publication, what it does, its persona, when it is planned and runs, its state and why it stopped', function () {
    $persona = User::factory()->persona()->create(['username' => 'night_owl', 'display_name' => 'Night Owl']);
    $plan = StudioPlan::factory()->status(StudioPlanStatus::Running)->create(['title' => 'A quiet harbour']);
    $failed = StudioAction::factory()->create([
        'studio_plan_id' => $plan->id,
        'actor_user_id' => $persona->id,
        'status' => StudioActionStatus::Failed,
        'failure_code' => StudioActionFailure::VoteConflict->value,
        'type' => StudioActionType::VotePost,
        'payload' => ['vote_type' => 'down'],
        'attempts' => 2,
        'occurs_at' => '2026-10-20 12:00:00',
        'run_after' => '2026-10-25 12:00:00',
        'finished_at' => '2026-10-25 12:00:05',
    ]);

    Livewire::test(StudioActionQueue::class)
        ->assertSee('A quiet harbour')
        ->assertSee('#'.$plan->id.' · action #'.$failed->id)
        ->assertSee('Downvote the post')
        ->assertSee('@night_owl')
        ->assertSee('Night Owl')
        ->assertSee('20 Oct 2026, 12:00')
        ->assertSee('Failed 25 Oct 2026, 12:00')
        ->assertSee('After 2 attempts')
        ->assertSee('vote_conflict')
        ->assertSee('The persona already voted the other way, or chose another option. Nothing was taken back.')
        ->assertSeeHtml('wire:click="retry('.$failed->id.')"')
        ->assertSeeHtml('wire:click="askToCancel('.$failed->id.')"')
        ->assertDontSeeHtml('wire:click="resume('.$failed->id.')"');
});

it('narrows by state, kind and publication from the address, dropping what it does not offer', function () {
    $post = StudioAction::factory()->create();
    $comment = StudioAction::factory()->childOf($post)->create(['status' => StudioActionStatus::Held, 'failure_code' => 'author_restricted']);

    Livewire::withQueryParams(['status' => 'held'])->test(StudioActionQueue::class)
        ->assertSee('1 action match')
        ->assertSeeHtml('wire:click="resume('.$comment->id.')"')
        ->assertDontSeeHtml('wire:click="askToCancel('.$post->id.')"');

    Livewire::withQueryParams(['type' => 'create_post', 'plan' => (string) $post->studio_plan_id])->test(StudioActionQueue::class)
        ->assertSee('1 action match')
        ->assertSee('Publish the post');

    Livewire::withQueryParams(['status' => 'published', 'type' => ['x'], 'plan' => 'abc', 'page' => 'nope'])->test(StudioActionQueue::class)
        ->assertSet('status', '')
        ->assertSet('type', '')
        ->assertSet('plan', '')
        ->assertSet('pageNumber', 1)
        ->assertSee('2 actions');
});

it('retries a failed action and resumes a held one, each said, and says why when it has moved on meanwhile', function () {
    $failed = StudioAction::factory()->create(['status' => StudioActionStatus::Failed, 'failure_code' => 'unexpected_failure', 'attempts' => 3]);
    $held = StudioAction::factory()->create(['status' => StudioActionStatus::Held, 'failure_code' => 'author_restricted', 'attempts' => 1]);

    Livewire::test(StudioActionQueue::class)
        ->call('retry', $failed->id)->assertDispatched('rg-admin-toast', message: 'Action #'.$failed->id.' queued to run again', tone: 'success')
        ->call('resume', $held->id)->assertDispatched('rg-admin-toast', message: 'Action #'.$held->id.' resumed', tone: 'success')
        ->call('retry', $failed->id)->assertDispatched('rg-admin-toast', message: 'Action #'.$failed->id.' is pending now, so nothing changed. The list shows it as it is.', tone: 'error');

    expect($failed->fresh())->status->toBe(StudioActionStatus::Pending)->attempts->toBe(0)
        ->and($held->fresh()->status)->toBe(StudioActionStatus::Pending);
});

it('asks before cancelling an action, then cancels it with the actions waiting for it', function () {
    $post = StudioAction::factory()->create();
    $comment = StudioAction::factory()->childOf($post)->create();

    Livewire::test(StudioActionQueue::class)
        ->call('askToCancel', $post->id)
        ->assertSee('Cancel this action?')
        ->assertSee('1 action directly depending on it')
        ->call('cancel')
        ->assertSet('confirming', null)
        ->assertDispatched('rg-admin-toast', message: 'Action #'.$post->id.' cancelled with 1 action depending on it', tone: 'success');

    expect($post->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('cancelled_by_administrator')
        ->and($comment->fresh()->failure_code)->toBe('parent_cancelled')
        ->and(Post::query()->count())->toBe(0);
});

it('switches the executor on and off, each asked first, and reads itself again only while it is on and something waits', function () {
    switchStudioExecution(false);
    StudioAction::factory()->create();

    $page = Livewire::test(StudioActionQueue::class)
        ->assertDontSeeHtml('wire:poll')
        ->call('askToSwitch', 'on')
        ->assertSee('Switch the executor on?')
        ->assertSee('1 pending action')
        ->call('switchExecution')
        ->assertDispatched('rg-admin-toast', message: 'Executor switched on. What is due runs now.', tone: 'success')
        ->assertSee('Executor on.')
        ->assertSeeHtml('wire:poll.5s');

    expect(app(StudioExecutionSwitch::class)->isOn())->toBeTrue();

    $page->call('askToSwitch', 'off')->assertSee('Switch the executor off?')->call('switchExecution');
    expect(app(StudioExecutionSwitch::class)->isOn())->toBeFalse();
});
