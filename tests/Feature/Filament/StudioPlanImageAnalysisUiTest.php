<?php

use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Enums\StudioPlanStatus;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\Studio\AnalyzeStudioPlanImageJob;
use App\Livewire\Admin\StudioPlanImageAnalysisCard;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Photo analysis on the editor's Image step: what the card says in each
 * state of the saved photo's analysis, the one action each state offers, the
 * polling only while an attempt is on its way — and Analyze photo as a
 * request of its own, never part of Save draft and never touching the draft.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The card as the editor of this plan draws it. */
function analysisCard(StudioPlan $plan, ?int $shownImageId = null): Testable
{
    return Livewire::test(StudioPlanImageAnalysisCard::class, ['planId' => $plan->id, 'imageAssetId' => $shownImageId ?? $plan->image_asset_id]);
}

it('draws the card on the Image step of a saved draft, about the photo the editor shows', function () {
    $plan = StudioPlan::factory()->withImage()->create();

    $html = $this->get(StudioPlanResource::getUrl('edit', ['record' => $plan]))->assertOk()->getContent();

    expect($html)
        ->toContain('Photo analysis')
        ->toContain('Technical context for the text generators. Not shown to visitors.')
        ->toContain('Analyze photo')
        ->toContain('The selected photo will be sent to OpenAI for analysis.')
        ->not->toContain('Photo analysis will be available in the next phase.')
        // Said while a new photo waits to be saved, read from the draft as typed.
        ->toContain('Save the draft first to analyse the new photo.')
        ->toContain('The photo is taken off this draft when you save, so there is nothing to analyse.');

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->assertSeeLivewire(StudioPlanImageAnalysisCard::class);
});

it('asks for a saved photo first on a new publication, and has nothing to analyse', function () {
    Livewire::test(CreateStudioPlan::class)
        ->assertSee('Save a photo to this draft before analysing it.')
        ->assertSee('Save the draft first to analyse the new photo.')
        ->assertDontSeeLivewire(StudioPlanImageAnalysisCard::class);
});

it('asks for a saved photo first, with Analyze photo held back', function () {
    analysisCard(StudioPlan::factory()->create())
        ->assertSee('Save a photo to this draft before analysing it.')
        ->assertSeeHtml('disabled')
        ->assertDontSeeHtml('wire:click="analyze"')
        ->assertDontSeeHtml('wire:poll');
});

it('says analysis is not configured, and offers nothing to send', function () {
    configureStudioImageAnalysis(['api_key' => null]);

    analysisCard(StudioPlan::factory()->withImage()->create())
        ->assertSee('Photo analysis is not configured.')
        ->assertDontSee('Analyze photo')
        ->assertDontSee('Re-run analysis');
});

it('offers Analyze photo for a photo not analysed yet, saying where it goes', function (Closure $existing) {
    $plan = StudioPlan::factory()->withImage()->create();
    $existing($plan);

    analysisCard($plan)
        ->assertSee('This photo has not been analysed yet.')
        ->assertSee('Analyze photo')
        ->assertSee('The selected photo will be sent to OpenAI for analysis.')
        ->assertSeeHtml('data-analysis="current"')
        ->assertDontSeeHtml('wire:poll');
})->with([
    'no analysis' => [fn () => null],
    // An analysis of the photo it had before is none of this one.
    'an analysis of a previous photo' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->completed()->create([
        'studio_plan_id' => $plan->id,
        'source_image_asset_id' => MediaAsset::factory()->postImage()->create()->id,
    ])],
]);

it('queues the analysis on Analyze photo, then polls while it waits and runs', function () {
    $plan = StudioPlan::factory()->withImage()->create(['title' => 'Saved title']);

    $card = analysisCard($plan)->call('analyze')
        ->assertNotDispatched('rg-admin-toast')
        ->assertSee('Waiting for analysis…')
        ->assertSeeHtml('wire:poll.'.StudioPlanImageAnalysisCard::POLL_SECONDS.'s.visible')
        ->assertDontSee('Analyze photo');

    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
    Http::assertNothingSent();

    StudioPlanImageAnalysis::query()->update(['status' => StudioImageAnalysisStatus::Running->value, 'started_at' => now()]);

    $card->call('$refresh')
        ->assertSee('Analysing photo…')
        ->assertSeeHtml('wire:poll');

    // Analyze photo is not Save: the plan is as it was.
    expect(StudioPlan::query()->findOrFail($plan->id)->only(['title', 'lock_version']))->toBe(['title' => 'Saved title', 'lock_version' => 1]);
});

it('shows the description once it is there, and stops polling', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    Carbon::setTestNow('2026-10-19 09:30:00');
    StudioPlanImageAnalysis::factory()->forPlan($plan)->completed(StudioPlanImageAnalysisFactory::description(['visible_text' => []]))->create();

    analysisCard($plan)
        ->assertSeeInOrder(['Scene summary', 'Subjects', 'Actions', 'Setting', 'Composition', 'Notable details', 'Visible text', 'Uncertainties'])
        ->assertSee('A brown dog runs across a sandy beach towards the sea.')
        ->assertSee('red collar')
        ->assertSee('None noted')
        ->assertSee('Analysed 19 Oct 2026, 09:30 with gpt-4.1-mini-2025-04-14.')
        ->assertSee('Re-run analysis')
        ->assertSee('Re-running sends the photo to OpenAI again — a new, paid request.')
        ->assertDontSee('Analyze photo')
        ->assertDontSeeHtml('wire:poll');
});

it('analyses the photo end to end when the queue runs at once', function () {
    // Only the photo's variants are held back; the analysis job runs as it is pushed.
    Queue::fake([GenerateMediaVariantsJob::class]);
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody(['summary' => 'A red tram crosses a bridge at night.']))]);
    $plan = studioPlanWithStoredPhoto();

    analysisCard($plan)->call('analyze')
        ->assertSee('A red tram crosses a bridge at night.')
        ->assertSee('Re-run analysis');

    Http::assertSentCount(1);
});

it('re-runs the result on screen, keeping it in view while the new one is made', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $completed = StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create();

    analysisCard($plan)->call('analyze', $completed->attempt_id)
        ->assertSee('Waiting for analysis…')
        ->assertSee('A brown dog runs across a sandy beach towards the sea.')
        ->assertDontSee('Re-run analysis');

    expect(StudioPlanImageAnalysis::query()->sole()->attempt_id)->not->toBe($completed->attempt_id);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('says why an attempt failed, in words of its own, and offers Try again', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $failed = StudioPlanImageAnalysis::factory()->forPlan($plan)->failed(StudioImageAnalysisError::RateLimited)->create();

    $card = analysisCard($plan)
        ->assertSee('OpenAI is limiting requests right now. Try again in a few minutes.')
        ->assertSee('Try again')
        ->assertDontSeeHtml('wire:poll');

    $card->call('analyze', $failed->attempt_id)->assertSee('Waiting for analysis…');
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('keeps the last result in view under a failed re-run', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create()
        ->forceFill(['status' => StudioImageAnalysisStatus::Failed, 'error_code' => StudioImageAnalysisError::ProviderTimeout])->save();

    analysisCard($plan)
        ->assertSee('OpenAI did not answer in time. Try again.')
        ->assertSee('A brown dog runs across a sandy beach towards the sea.')
        ->assertSee('Try again')
        ->assertDontSee('Re-run analysis');
});

it('says an attempt that never finished did not, and lets it be tried again', function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
    $plan = StudioPlan::factory()->withImage()->create();
    StudioPlanImageAnalysis::factory()->forPlan($plan)->running()->create();

    Carbon::setTestNow('2026-10-19 12:10:00');

    analysisCard($plan)
        ->assertSee('The analysis did not finish. Try again.')
        ->assertSee('Try again')
        ->assertDontSeeHtml('wire:poll');
});

it('says a photo changed elsewhere was, sending nothing for a photo the editor does not show', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $shown = $plan->image_asset_id;
    $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save();

    analysisCard($plan, $shown)
        ->assertSee('The photo was changed after this draft was opened. Reload the draft to analyse the photo it has now.')
        ->assertSeeHtml('reload-studio-plan')
        ->assertDontSee('Analyze photo')
        ->call('analyze')
        ->assertDispatched('rg-admin-toast', message: 'The photo was changed meanwhile, so it wasn’t sent. Reload the draft to analyse the photo it has now.', tone: 'error');

    Queue::assertNothingPushed();
});

it('says so, and sends nothing, when analysis was switched off after the card was drawn', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $card = analysisCard($plan);

    configureStudioImageAnalysis(['enabled' => false]);

    $card->call('analyze')->assertDispatched('rg-admin-toast', message: 'Photo analysis is not configured.', tone: 'error');
    Queue::assertNothingPushed();
});

it('sends nothing for a draft deleted or closed after the card was drawn', function (Closure $change, string $message) {
    $plan = StudioPlan::factory()->withImage()->create();
    $card = analysisCard($plan);
    $change($plan);

    $card->call('analyze')->assertDispatched('rg-admin-toast', message: $message, tone: 'error');
    Queue::assertNothingPushed();
})->with([
    'deleted' => [fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->delete(), 'This draft was deleted, so its photo wasn’t sent.'],
    'closed' => [fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), 'This plan is no longer a draft, so its photo wasn’t sent.'],
]);

it('shows a closed plan\'s analysis without anything to send', function () {
    $plan = StudioPlan::factory()->withImage()->status(StudioPlanStatus::Approved)->create();
    StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create();

    analysisCard($plan)
        ->assertSee('Scene summary')
        ->assertDontSee('Re-run analysis')
        ->assertDontSee('Analyze photo');
});

it('draws the description as text, never as markup', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    StudioPlanImageAnalysis::factory()->forPlan($plan)->completed(StudioPlanImageAnalysisFactory::description(['visible_text' => ['<script>alert(1)</script>']]))->create();

    analysisCard($plan)
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('shows nothing of a stored result that is not a valid description', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    StudioPlanImageAnalysis::factory()->forPlan($plan)->completed(['summary' => 'Only a summary'])->create();

    analysisCard($plan)
        ->assertDontSee('Scene summary')
        ->assertDontSee('Only a summary');
});

it('is for administrators who may manage plans only — drawn, polled or clicked', function () {
    $plan = StudioPlan::factory()->withImage()->create();

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(StudioPlanImageAnalysisCard::class, ['planId' => $plan->id, 'imageAssetId' => $plan->image_asset_id])
        ->assertForbidden();

    $this->actingAs($this->admin);
    $card = analysisCard($plan);
    $this->admin->forceFill(['role' => 'user'])->save();

    $card->call('analyze')->assertForbidden();
    Queue::assertNothingPushed();
});

it('keeps which plan and which photo it is about out of the browser\'s reach', function () {
    $plan = StudioPlan::factory()->withImage()->create();

    analysisCard($plan)->set('planId', 999);
})->throws(CannotUpdateLockedPropertyException::class);
