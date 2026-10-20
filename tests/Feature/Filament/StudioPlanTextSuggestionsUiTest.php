<?php

use App\Enums\StudioPlanStatus;
use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Jobs\Studio\GenerateStudioPostTextsJob;
use App\Livewire\Admin\StudioPlanTextSuggestionsCard;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Database\Factories\StudioPlanTextGenerationFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * AI text suggestions on the editor's Post step: what the cards say in each
 * state of the photo, its analysis and its batches, the tone and Generate,
 * the newest batch alone in view, the polling only while something is on its
 * way — and choosing a title or a description, which puts it in the draft as
 * typing would, saving nothing, never from a batch written for another photo
 * or analysis.
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
function suggestionsCard(StudioPlan $plan, ?int $shownImageId = null): Testable
{
    return Livewire::test(StudioPlanTextSuggestionsCard::class, ['planId' => $plan->id, 'imageAssetId' => $shownImageId ?? $plan->image_asset_id]);
}

it('draws the card on the Post step, about the photo the editor shows, in place of the old notice', function () {
    $plan = studioPlanWithFinishedAnalysis();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->call('goToStep', 'post')
        ->assertSeeLivewire(StudioPlanTextSuggestionsCard::class)
        ->assertSee('Final texts')
        ->assertDontSee('AI title and description suggestions will be available in the next phase.');
});

it('asks for a photo first on a new publication or a draft without one', function () {
    Livewire::test(StudioPlanTextSuggestionsCard::class)
        ->assertSee('Add a photo on the Image step first')
        ->assertDontSee('Generate suggestions');

    suggestionsCard(StudioPlan::factory()->create())->assertSee('Add a photo on the Image step first');
});

it('waits for the photo\'s analysis, reading itself again only while the analysis is on its way', function (Closure $arrange, bool $polls) {
    $plan = StudioPlan::factory()->withImage()->create();
    $arrange($plan);

    $card = suggestionsCard($plan)
        ->assertSee('Text suggestions will be available when photo analysis is complete.')
        ->assertDontSee('Generate suggestions');

    $polls ? $card->assertSeeHtml('wire:poll.3s.visible') : $card->assertDontSeeHtml('wire:poll');
})->with([
    'no analysis' => [fn () => null, false],
    'an analysis running' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->forPlan($plan)->running()->create(), true],
    'an analysis that failed' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->forPlan($plan)->failed()->create(), false],
]);

it('says texts are not configured, and offers nothing to send', function () {
    configureStudioImageAnalysis(['api_key' => null]);

    suggestionsCard(studioPlanWithFinishedAnalysis())
        ->assertSee('Text suggestions are not configured.')
        ->assertDontSee('Generate suggestions');
});

it('offers the five tones, Cheeky first chosen, and Generate suggestions before any batch', function () {
    $card = suggestionsCard(studioPlanWithFinishedAnalysis())
        ->assertSet('tone', 'cheeky')
        ->assertSeeInOrder(['Tone of new variants', 'Playful', 'Humour', 'Cheeky', 'Irony', 'Neutral'])
        ->assertSee('Generate suggestions')
        ->assertDontSee('Generate 10 more')
        ->assertDontSeeHtml('wire:poll');

    expect($card->html())->toMatch('/aria-pressed="true"[^>]*>Cheeky</')
        // Nothing on its way: Generate suggestions can be pressed.
        ->not->toMatch('/<button type="button" disabled [^>]*wire:click="generate"/');
});

it('asks for a batch in each tone chosen, polling while it waits and runs, the draft untouched', function (StudioPostTextTone $tone) {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'Saved title']);

    $card = suggestionsCard($plan)
        ->set('tone', $tone->value)
        ->call('generate')
        ->assertNotDispatched('rg-admin-toast')
        ->assertSee('Waiting for text suggestions…')
        ->assertSeeHtml('wire:poll.3s.visible');

    // Generate cannot be pressed again while the batch is on its way.
    expect($card->html())->toMatch('/<button type="button" disabled [^>]*wire:click="generate"/')
        ->and(StudioPlanTextGeneration::query()->sole()->tone)->toBe($tone);
    Queue::assertPushed(GenerateStudioPostTextsJob::class, 1);
    Http::assertNothingSent();

    StudioPlanTextGeneration::query()->update(['status' => StudioPostTextGenerationStatus::Running->value, 'started_at' => now()]);
    $card->call('$refresh')->assertSee('Generating suggestions…');

    expect($plan->fresh()->only(['title', 'lock_version']))->toBe(['title' => 'Saved title', 'lock_version' => 1]);
})->with(StudioPostTextTone::cases());

it('reads a tone it does not offer as the default one', function () {
    $plan = studioPlanWithFinishedAnalysis();

    suggestionsCard($plan)->set('tone', 'shouty')->call('generate');

    expect(StudioPlanTextGeneration::query()->sole()->tone)->toBe(StudioPostTextTone::Cheeky);
});

it('shows ten titles and ten descriptions once written, and Generate 10 more, polling no more', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $card = suggestionsCard($plan)->call('generate');
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);
    runStudioPostTextsJob(StudioPlanTextGeneration::query()->sole());

    $card->call('$refresh')
        ->assertSee('Context')
        ->assertSee('A brown dog runs across a sandy beach towards the sea.')
        ->assertSee('10 titles')
        ->assertSee('10 descriptions')
        ->assertSee('Set 1 · written in Cheeky')
        ->assertSeeInOrder(StudioPlanTextGenerationFactory::titles())
        ->assertSeeInOrder(StudioPlanTextGenerationFactory::descriptions())
        ->assertSee('Generate 10 more')
        ->assertDontSeeHtml('wire:poll');

    expect(substr_count($card->html(), 'Use this title'))->toBe(10)
        ->and(substr_count($card->html(), 'Use this description'))->toBe(10);
});

it('puts the batch Generate 10 more writes in place of the one before, which stays stored', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create(['tone' => StudioPostTextTone::Playful]);

    suggestionsCard($plan)->assertSee('Generate 10 in Cheeky')->set('tone', 'irony')->call('generate')
        // While it is on its way, the lists wait for it.
        ->assertSee('Waiting for text suggestions…')
        ->assertDontSee('Beach zoomies, level expert');
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody(StudioPlanTextGenerationFactory::titles('Again: '), StudioPlanTextGenerationFactory::descriptions('Again: ')))]);
    runStudioPostTextsJob(StudioPlanTextGeneration::query()->where('status', 'queued')->sole());

    suggestionsCard($plan)->set('tone', 'irony')
        ->assertSee('Set 2 · written in Irony')
        ->assertSeeHtml('>Again: Beach zoomies, level expert</span>')
        ->assertDontSeeHtml('>Beach zoomies, level expert</span>')
        ->assertDontSee('Set 1 ·')
        ->assertSee('Generate 10 more');

    expect(StudioPlanTextGeneration::query()->where('status', 'completed')->count())->toBe(2);
});

it('says when the lists were written in another tone than the one chosen, and offers the chosen one', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create(['tone' => StudioPostTextTone::Playful]);

    suggestionsCard($plan)->set('tone', 'neutral')
        ->assertSee('The lists were written in Playful.')
        ->assertSee('Generate 10 in Neutral')
        ->set('tone', 'playful')
        ->assertDontSee('The lists were written in')
        ->assertSee('Generate 10 more');
});

it('says why a batch failed in place of the lists, and offers Try again', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create(['requested_at' => now()->subMinute()]);
    StudioPlanTextGeneration::factory()->forPlan($plan)->failed(StudioPostTextGenerationError::RateLimited)->create();

    $card = suggestionsCard($plan)
        ->assertSee('Generation failed')
        ->assertSee('OpenAI is limiting requests right now. Try again in a few minutes.')
        ->assertSee('The final title and description are unchanged.')
        ->assertSee('Try again')
        ->assertDontSee('Beach zoomies, level expert')
        ->assertDontSeeHtml('wire:poll');

    $card->call('generate')->assertSee('Waiting for text suggestions…');
    Queue::assertPushed(GenerateStudioPostTextsJob::class, 1);
});

it('says a batch that never finished did not, and lets another be asked for', function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->running()->create();

    Carbon::setTestNow('2026-10-20 12:10:00');

    suggestionsCard($plan)
        ->assertSee('The suggestions did not arrive. Try again.')
        ->assertSee('Try again')
        ->assertDontSeeHtml('wire:poll');
});

it('puts a used title in the draft, unsaved, and leaves the description as it is', function () {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'Saved title', 'description' => 'Saved description']);
    $batch = StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();

    suggestionsCard($plan)
        ->call('useTitle', $batch->id, 2)
        ->assertDispatched('use-studio-suggestion', field: 'title', text: 'Nobody told him it was cloudy');

    $editor = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->call('goToStep', 'post')
        ->dispatch('use-studio-suggestion', field: 'title', text: 'Nobody told him it was cloudy')
        ->assertSet('postTitle', 'Nobody told him it was cloudy')
        ->assertSet('postDescription', 'Saved description');

    // Unsaved: what the browser compares it with is still the saved title.
    expect($editor->get('saved')['title'])->toBe('Saved title')
        ->and($plan->fresh()->only(['title', 'lock_version']))->toBe(['title' => 'Saved title', 'lock_version' => 1]);
    Queue::assertNothingPushed();
});

it('puts a used description in the draft, unsaved, editable, saved only with Save draft', function () {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'A title of mine']);
    $batch = StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();

    suggestionsCard($plan)
        ->call('useDescription', $batch->id, 5)
        ->assertDispatched('use-studio-suggestion', field: 'description', text: 'Low tide, high energy, zero hesitation.');

    $editor = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->dispatch('use-studio-suggestion', field: 'description', text: 'Low tide, high energy, zero hesitation.')
        // Moving between steps loses nothing.
        ->call('goToStep', 'publication')
        ->call('goToStep', 'post')
        ->assertSet('postDescription', 'Low tide, high energy, zero hesitation.')
        ->assertSet('postTitle', 'A title of mine')
        // Edited by hand afterwards, like any field.
        ->set('postDescription', 'Low tide, high energy, zero hesitation. Edited.');

    expect($plan->fresh()->description)->toBeNull();

    $editor->call('save')->assertHasNoErrors();

    expect($plan->fresh()->description)->toBe('Low tide, high energy, zero hesitation. Edited.');
    // Save draft writes the texts and asks for no new batch.
    Queue::assertNothingPushed();
    expect(StudioPlanTextGeneration::query()->count())->toBe(1);
});

it('uses nothing from a batch written for another photo or another analysis, and shows it no more', function (Closure $change) {
    $plan = studioPlanWithFinishedAnalysis();
    $batch = StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();
    $card = suggestionsCard($plan);
    $change($plan);

    $card->call('useTitle', $batch->id, 0)
        ->assertNotDispatched('use-studio-suggestion')
        ->assertDispatched('rg-admin-toast', message: 'These suggestions were written for an earlier photo or analysis, so nothing was used.', tone: 'error');

    suggestionsCard($plan->fresh())->assertDontSee('Beach zoomies, level expert');
})->with([
    'a new photo' => [function (StudioPlan $plan) {
        $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save();
        // The same reading of it, but of the new photo.
        StudioPlanImageAnalysis::query()->update(['source_image_asset_id' => $plan->image_asset_id]);
    }],
    'the analysis written again, differently' => [fn () => StudioPlanImageAnalysis::query()->update(['result' => json_encode(StudioPlanImageAnalysisFactory::description(['summary' => 'Another reading of it.']))])],
]);

it('keeps a new photo\'s final texts, and asks for no texts by itself', function () {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'Chosen by a person', 'description' => 'Also chosen']);
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();

    $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save();

    expect($plan->fresh()->only(['title', 'description']))->toBe(['title' => 'Chosen by a person', 'description' => 'Also chosen']);
    Queue::assertNotPushed(GenerateStudioPostTextsJob::class);
});

it('shows the batches kept for the photo again when the draft is opened again, asking nothing of OpenAI', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();
    Http::fake();

    $html = $this->get(StudioPlanResource::getUrl('edit', ['record' => $plan]))->assertOk()->getContent();
    suggestionsCard($plan)->assertSee('Beach zoomies, level expert')->assertSee('Generate 10 more');

    expect($html)->toContain('rg-admin-studio__step');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('draws every text escaped', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed([...array_slice(StudioPlanTextGenerationFactory::titles(), 0, 9), '<script>alert(1)</script>'])->create();

    suggestionsCard($plan)
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('offers nothing to send for a plan past its Draft, showing its batches', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();
    $plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

    suggestionsCard($plan)
        ->assertSee('Beach zoomies, level expert')
        ->assertDontSee('Use this title')
        ->assertDontSee('Generate 10 more');
});

it('sends nothing for a draft deleted or a photo changed after the card was drawn', function (Closure $change, string $message) {
    $plan = studioPlanWithFinishedAnalysis();
    $card = suggestionsCard($plan);
    $change($plan);

    $card->call('generate')->assertDispatched('rg-admin-toast', message: $message, tone: 'error');
    Queue::assertNothingPushed();
})->with([
    'deleted' => [fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->delete(), 'This draft was deleted, so nothing was sent.'],
    'closed' => [fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), 'This plan is no longer a draft, so nothing was sent.'],
    'a new photo' => [fn (StudioPlan $plan) => $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save(), 'The photo was changed meanwhile, so nothing was sent. Reload the draft to write texts for the photo it has now.'],
]);

it('draws the card on a new publication too, with nothing to write from yet', function () {
    Livewire::test(CreateStudioPlan::class)
        ->call('goToStep', 'post')
        ->assertSeeLivewire(StudioPlanTextSuggestionsCard::class)
        ->assertSee('Final texts');
});

it('is for administrators who may manage plans only — drawn, polled or clicked', function () {
    $plan = studioPlanWithFinishedAnalysis();

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(StudioPlanTextSuggestionsCard::class, ['planId' => $plan->id, 'imageAssetId' => $plan->image_asset_id])
        ->assertForbidden();

    $this->actingAs($this->admin);
    $card = suggestionsCard($plan);
    $this->admin->forceFill(['role' => 'user'])->save();

    $card->call('generate')->assertForbidden();
    Queue::assertNothingPushed();
});

it('keeps which plan and which photo it is about out of the browser\'s reach', function () {
    suggestionsCard(studioPlanWithFinishedAnalysis())->set('imageAssetId', 999);
})->throws(CannotUpdateLockedPropertyException::class);
