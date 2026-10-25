<?php

use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Livewire\Admin\StudioPlanActionPlan;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Publication step of a publication's editor: a preview of exactly what
 * approving the saved plan writes, what of it is overdue, what stops it from
 * being approved, Review and confirm — asked first, refused with anything
 * unsaved or a version somebody else saved since — and, once approved, the
 * plan's own actions as they run, with Stop remaining actions.
 */
beforeEach(function () {
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The editor of a plan, on its Publication step. */
function publicationStep(StudioPlan $plan): Testable
{
    return Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->call('goToStep', 'publication');
}

it('previews every action a historical plan\'s approval would write — overdue, ready to run on approval — writing nothing', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    ['plan' => $plan] = approvableStudioPlanWithVotes(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-10-20 12:00:00')]);

    Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])
        ->assertSee('Action plan')
        ->assertSee('Publish the post')
        ->assertSee('Starts the plan · keeps its past date')
        ->assertSee('Comment C1')
        ->assertSee('Reply C2')
        ->assertSee('After C1')
        ->assertSee('Upvote the post')
        ->assertSee('Downvote the post')
        ->assertSee('Upvote C1')
        ->assertSee('Answer “')
        ->assertSee('20 Oct 2026, 12:00')
        ->assertSee('On approval')
        ->assertSee('Ready to run')
        ->assertSee('Overdue')
        ->assertSee('All · 13')
        ->assertSee('Preview of the plan')
        ->assertDontSeeHtml('wire:poll');

    expect(StudioAction::query()->count())->toBe(0)
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Draft);
});

it('narrows the preview to the post\'s part or the discussion\'s, and reads it 25 a page', function () {
    $plan = approvableStudioPlan();
    $commenter = User::factory()->persona()->create();
    saveStudioDiscussion(User::factory()->admin()->create(), $plan, array_map(
        fn (int $i): mixed => discussionNode(['key' => 'c'.$i, 'personaUserId' => $commenter->id, 'body' => 'Comment number '.$i, 'sortOrder' => $i, 'offsetSeconds' => 60 * $i]),
        range(1, 30),
    ));

    Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])
        ->assertSee('1–25 of 31 actions')
        ->call('goToPage', 2)->assertSee('26–31 of 31 actions')->assertSee('Comment number 30')
        ->set('part', 'post')->assertSet('page', 1)->assertSee('Publish the post')->assertDontSee('Comment number 1')
        // The timeline keeps every action; the table only the part shown.
        ->set('part', 'discussion')->assertDontSeeHtml('rg-admin-studio__plan-action-label--strong')->assertSee('Comment number 1')
        ->set('part', 'elsewhere')->assertSet('part', 'all');
});

it('draws the preview afresh when another editor on the page saves', function () {
    $plan = approvableStudioPlan();
    $component = Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])->assertSee('All · 1');

    saveStudioDiscussion(User::factory()->admin()->create(), $plan, [discussionNode(['key' => 'c1', 'personaUserId' => User::factory()->persona()->create()->id, 'body' => 'First!', 'offsetSeconds' => 60])]);

    $component->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $plan->id, previous: 1, current: 2, source: 'discussion')->assertSee('All · 2')->assertSee('First!');
});

it('says what of the plan is overdue, and that it runs as soon as the plan is approved under its planned dates', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan] = approvableStudioPlanWithDiscussion(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-10-20 12:00:00')]);

    publicationStep($plan)
        ->assertSee('Overdue actions')
        ->assertSee('The publication date is in the past.')
        ->assertSee('All 7 planned actions are already overdue.')
        ->assertSee('They run as soon as the plan is approved')
        ->assertSee('the post is dated 20 Oct 2026, 12:00')
        ->assertDontSee('Spread from now')
        ->assertDontSee('Skip overdue');
});

it('says each thing that stops a plan from being approved, with the step that holds it, and holds Review and confirm back', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Untold']);

    publicationStep($plan)
        ->assertSee('Ready to approve?')
        ->assertSee('Choose a persona as the author.')
        ->assertSee('Add a photo.')
        ->assertSeeHtml('wire:click="editPart(\'image\')"')
        ->assertSee('Fix what stops the plan before approving it.')
        ->call('askToApprove')
        ->assertSet('confirmingApproval', false)
        ->assertDispatched('rg-admin-toast', tone: 'error')
        ->call('editPart', 'image')->assertSet('step', 'image');

    expect($plan->fresh()->status)->toBe(StudioPlanStatus::Draft);
});

it('approves nothing with anything unsaved on the page, or after somebody else saved the plan', function () {
    $plan = approvableStudioPlan();

    publicationStep($plan)
        ->set('postTitle', 'Typed, not saved')
        ->call('askToApprove')
        ->assertSet('confirmingApproval', false)
        ->assertDispatched('rg-admin-toast', message: 'Save your changes first: approval publishes the draft as it is saved.', tone: 'error');

    $page = publicationStep($plan);
    app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan->fresh(), StudioPlanData::of($plan->fresh()), $plan->fresh()->lock_version);

    $page->call('askToApprove')
        ->assertSet('confirmingApproval', false)
        ->assertSet('stale', true)
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this draft after you opened it, so nothing was approved.', tone: 'error');

    expect($plan->fresh()->status)->toBe(StudioPlanStatus::Draft)
        ->and(StudioAction::query()->count())->toBe(0);
});

it('asks first — what is written, the date, the comments, the votes, what is overdue and what waits — then approves the saved plan once, and opens it closed on Publication', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    switchStudioExecution(false);
    ['plan' => $plan] = approvableStudioPlanWithVotes(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-10-20 12:00:00')]);

    $page = publicationStep($plan)
        ->assertSee('Everything the approval checks is in order.')
        ->call('askToApprove')
        ->assertSet('confirmingApproval', true)
        ->assertSee('Confirm this publication?')
        ->assertSee('Post date: 20 Oct 2026, 12:00')
        ->assertSee('3 comments and 3 replies')
        ->assertSee('13 actions in the plan')
        ->assertSee('13 overdue: they run right after approval, keeping their planned dates')
        ->assertSee('The executor is switched off')
        ->call('approve')
        ->assertRedirect(EditStudioPlan::getUrl(['record' => $plan->id]));

    expect($plan->fresh())->status->toBe(StudioPlanStatus::Approved)->approved_by_user_id->toBe($this->admin->id)
        ->and(StudioAction::query()->where('studio_plan_id', $plan->id)->count())->toBe(13)
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Publication approved. The executor is switched off, so nothing runs until it is switched on in the Action queue.', 'tone' => 'success']);

    // Pressed again from the page as it was: nothing more written.
    $page->call('approve');
    expect(StudioAction::query()->where('studio_plan_id', $plan->id)->count())->toBe(13);

    // Opened again: closed, on Publication, with no Review and confirm.
    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->assertSet('closed', true)
        ->assertSet('step', 'publication')
        ->assertDontSee('Review and confirm')
        ->assertSee('This plan is no longer a draft');
});

it('follows an approved plan\'s actions as they stand — reading itself again only while the executor runs and something waits', function () {
    $plan = approvableStudioPlan();
    approveStudioPlan($this->admin, $plan);

    switchStudioExecution(false);
    Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])
        ->assertSee('Publication status')
        ->assertSee('Approved')
        ->assertSee('Not published yet · Pending')
        ->assertSee('The executor is switched off')
        ->assertSee('Open in Action queue')
        ->assertSee('Stop remaining actions')
        ->assertSee('#'.StudioAction::query()->sole()->id)
        ->assertDontSeeHtml('wire:poll');

    switchStudioExecution(true);
    Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])->assertSeeHtml('wire:poll.5s');

    // Done: nothing left to follow.
    StudioAction::query()->sole()->forceFill(['status' => StudioActionStatus::Cancelled, 'failure_code' => 'cancelled_by_administrator', 'finished_at' => now()])->save();
    $plan->forceFill(['status' => StudioPlanStatus::Cancelled])->save();

    Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])
        ->assertDontSeeHtml('wire:poll')
        ->assertDontSee('Stop remaining actions')
        ->assertSee('Cancelled by an administrator.');
});

it('tells the editor when the plan it follows moves on', function () {
    $plan = approvableStudioPlan();
    $component = Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])->assertNotDispatched(StudioPlanActionPlan::MOVED_EVENT);

    approveStudioPlan($this->admin, $plan);

    $component->call('goToPage', 1)->assertDispatched(StudioPlanActionPlan::MOVED_EVENT, planId: $plan->id);

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->assertSet('closed', true);
});

it('stops the remaining actions after asking, taking nothing published back', function () {
    $plan = approvableStudioPlan();
    approveStudioPlan($this->admin, $plan);

    Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])
        ->call('askToStop')
        ->assertSee('Stop the remaining actions?')
        ->assertSee('1 action not yet run')
        ->call('stop')
        ->assertDispatched('rg-admin-toast', message: '1 remaining action stopped', tone: 'success');

    expect(StudioAction::query()->sole())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('cancelled_by_administrator')
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Cancelled);
});

it('keeps the action plan to administrators', function () {
    $plan = approvableStudioPlan();
    $this->actingAs(User::factory()->moderator()->create());

    Livewire::test(StudioPlanActionPlan::class, ['planId' => $plan->id])->assertForbidden();
});
