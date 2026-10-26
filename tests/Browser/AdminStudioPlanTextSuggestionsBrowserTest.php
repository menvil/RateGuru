<?php

use App\Jobs\Studio\GenerateStudioPostTextsJob;
use App\Models\StudioPlan;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use App\Support\StudioAi\StudioPostTextContext;
use Database\Factories\StudioPlanTextGenerationFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * AI text suggestions on the publication editor's Post step, in a real
 * browser: a tone is picked, Generate suggestions asks for one batch, the
 * suggestions alone read themselves again until the worker's texts are there
 * and then stop — and choosing a title or a description puts it in the final
 * field as typing would: unsaved, counted as unsaved changes, editable, and
 * kept only by Save draft.
 *
 * The job runs in the test, as a worker would, with OpenAI faked.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    actingAs(User::factory()->admin()->create());
});

/** The texts of the suggestions in this list, in order, without what a screen reader hears before each. */
function suggestionTexts(string $kind): string
{
    return "[...document.querySelectorAll('[data-suggestion-list=\"{$kind}\"] .rg-admin-studio__suggestion-text')].map((text) => text.lastChild.textContent.trim())";
}

/** Chooses the suggestion at this place in this list. */
function useSuggestion(mixed $page, string $kind, int $index): void
{
    $page->script("document.querySelectorAll('[data-suggestion-list=\"{$kind}\"] .rg-admin-studio__suggestion')[{$index}].click()");
}

/** The Context card's Generate button. */
function suggestionsGenerateButton(): string
{
    return "[...document.querySelectorAll('.rg-admin-studio__context .rg-admin-button')].find((button) => /Generate|Try again/.test(button.textContent))";
}

it('asks for a batch in the chosen tone, polls until it is written, and puts the texts used in the draft, unsaved', function () {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'A harbour', 'description' => null]);
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit");

    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 2 of 4 · Post · Titles & texts');
    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'ready');

    expect($page->script("[...document.querySelectorAll('.rg-admin-studio__tone')].map((tone) => tone.textContent.trim())"))->toBe(['Playful', 'Humour', 'Cheeky', 'Spicy / Adult humour', 'Irony', 'Neutral'])
        ->and($page->script("document.querySelector('.rg-admin-studio__tone[aria-pressed=\"true\"]').textContent.trim()"))->toBe('Cheeky')
        ->and($page->script("document.querySelector('[data-suggestions]').hasAttribute('wire:poll.3s.visible')"))->toBeFalse();

    // A tone picked, then Generate suggestions.
    $page->script("[...document.querySelectorAll('.rg-admin-studio__tone')].find((tone) => tone.textContent.trim() === 'Playful').click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__tone[aria-pressed=\"true\"]').textContent.trim()", 'Playful');
    $page->script(suggestionsGenerateButton().'.click()');

    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'queued');

    expect($page->script("document.querySelector('[data-suggestions]').hasAttribute('wire:poll.3s.visible')"))->toBeTrue()
        ->and($page->script(suggestionsGenerateButton().'.disabled'))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-suggestion-list] .rg-admin-studio__suggestion-waiting').length"))->toBe(2)
        ->and(StudioPlanTextGeneration::query()->sole()->tone->value)->toBe('playful')
        ->and(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);

    // A worker takes the job meanwhile.
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);
    runStudioPostTextsJob(StudioPlanTextGeneration::query()->sole());

    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'ready', 10.0);

    expect($page->script(suggestionTexts('titles')))->toBe(StudioPlanTextGenerationFactory::titles())
        ->and($page->script(suggestionTexts('descriptions')))->toBe(StudioPlanTextGenerationFactory::descriptions())
        ->and($page->script("document.querySelector('[data-suggestions]').hasAttribute('wire:poll.3s.visible')"))->toBeFalse()
        ->and($page->script(suggestionsGenerateButton().'.textContent.trim()'))->toBe('Generate 10 more');

    // A title chosen: in the field, marked in use, one unsaved change, nothing saved.
    useSuggestion($page, 'titles', 2);
    waitForScript($page, "document.getElementById('rg-admin-studio-title').value", 'Nobody told him it was cloudy');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');

    expect($page->script("document.querySelectorAll('[data-suggestion-list=\"titles\"] .rg-admin-studio__suggestion--used').length"))->toBe(1)
        ->and($plan->fresh()->title)->toBe('A harbour');

    // A description chosen, then edited by hand like any field.
    useSuggestion($page, 'descriptions', 5);
    waitForScript($page, "document.getElementById('rg-admin-studio-description').value", 'Low tide, high energy, zero hesitation.');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '2 unsaved changes');
    $page->script("(() => { const field = document.getElementById('rg-admin-studio-description'); field.value += ' Edited.'; field.dispatchEvent(new Event('input', { bubbles: true })) })()");

    expect($plan->fresh()->description)->toBeNull();

    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Draft saved');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    expect($plan->fresh()->only(['title', 'description']))->toBe(['title' => 'Nobody told him it was cloudy', 'description' => 'Low tide, high energy, zero hesitation. Edited.'])
        ->and(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);
    // Save draft asked for no new batch.
    Queue::assertPushed(GenerateStudioPostTextsJob::class, 1);
});

it('waits for the photo\'s analysis on a draft without one, offering nothing to send', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit");

    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'analysis_pending');

    expect($page->script("document.querySelector('[data-suggestions]').textContent"))->toContain('Text suggestions will be available when photo analysis is complete.')
        ->and($page->script("document.querySelectorAll('.rg-admin-studio__tones').length"))->toBe(0)
        ->and($page->script(suggestionsGenerateButton()))->toBeNull();
});

it('writes in the voice of the author on screen: another author chosen is saved first, and the batch asked for once it is', function () {
    $anna = studioPersonaWithProfile(['short_description' => 'Deadpan to the bone.'], ['display_name' => 'Anna Deadpan']);
    $boris = studioPersonaWithProfile(['short_description' => 'Flirts with the furniture.'], ['display_name' => 'Boris Swoon']);
    $plan = studioPlanWithFinishedAnalysis(['title' => 'A harbour', 'author_user_id' => $anna->id]);
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit");
    $voice = "document.querySelector('[data-testid=\"studio-suggestions-voice\"] .rg-admin-studio__context-analysis').textContent.trim()";
    $warning = "getComputedStyle(document.querySelector('[data-testid=\"studio-suggestions-voice\"] .rg-admin-studio__context-warning')).display";

    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'ready');

    expect($page->script($voice))->toBe('Anna Deadpan — Deadpan to the bone.')
        ->and($page->script($warning))->toBe('none');

    // Another author chosen in the editor, not saved yet: the card says what Generate will do.
    $page->script("document.querySelector('.rg-admin-studio__author-trigger').click()");
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-studio__choice')].some((choice) => choice.textContent.includes('Boris Swoon'))");
    $page->script("[...document.querySelectorAll('.rg-admin-studio__choice')].find((choice) => choice.textContent.includes('Boris Swoon')).click()");
    waitForScript($page, $warning." !== 'none'");

    expect($plan->fresh()->author_user_id)->toBe($anna->id);

    // Generate saves the draft with the new author, then asks for the batch in that author's voice.
    $page->script(suggestionsGenerateButton().'.click()');
    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'queued');
    waitForScript($page, $voice, 'Boris Swoon — Flirts with the furniture.');

    $plan->refresh();

    expect($plan->author_user_id)->toBe($boris->id)
        ->and(StudioPlanTextGeneration::query()->sole()->source_analysis_hash)->toBe(StudioPostTextContext::ofPlan($plan, $plan->imageAnalysis)->sourceHash())
        ->and($page->script($warning))->toBe('none')
        ->and(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);
    Queue::assertPushed(GenerateStudioPostTextsJob::class, 1);
});
