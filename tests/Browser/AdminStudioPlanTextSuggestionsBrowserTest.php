<?php

use App\Jobs\Studio\GenerateStudioPostTextsJob;
use App\Models\StudioPlan;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use Database\Factories\StudioPlanTextGenerationFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * AI text suggestions on the publication editor's Post step, in a real
 * browser: a tone is picked, Generate suggestions asks for one batch, the
 * card alone reads itself again until the worker's texts are there and then
 * stops — and Use this title and Use this description put the chosen texts
 * in the final fields as typing would: unsaved, counted as unsaved changes,
 * editable, and kept only by Save draft.
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

/** The texts of the suggestions in this column, in order. */
function suggestionTexts(string $kind): string
{
    return "[...document.querySelectorAll('#rg-admin-studio-suggestions-{$kind} ~ .rg-admin-studio__suggestion-set .rg-admin-studio__suggestion-text')].map((text) => text.textContent.trim())";
}

/** Clicks this column's Use button on the suggestion at this place. */
function useSuggestion(mixed $page, string $kind, int $index): void
{
    $page->script("document.querySelectorAll('#rg-admin-studio-suggestions-{$kind} ~ .rg-admin-studio__suggestion-set .rg-admin-studio__suggestion-use')[{$index}].click()");
}

it('asks for a batch in the chosen tone, polls until it is written, and puts the texts used in the draft, unsaved', function () {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'A harbour', 'description' => null]);
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit");

    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 2 of 4 · Post');
    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'ready');

    expect($page->script("[...document.querySelectorAll('.rg-admin-studio__tone')].map((tone) => tone.textContent.trim())"))->toBe(['Playful', 'Humour', 'Cheeky', 'Irony', 'Neutral'])
        ->and($page->script("document.querySelector('.rg-admin-studio__tone[aria-checked=\"true\"]').textContent.trim()"))->toBe('Cheeky')
        ->and($page->script("document.querySelector('[data-suggestions]').hasAttribute('wire:poll.3s.visible')"))->toBeFalse();

    // A tone picked, then Generate suggestions.
    $page->script("[...document.querySelectorAll('.rg-admin-studio__tone')].find((tone) => tone.textContent.trim() === 'Playful').click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__tone[aria-checked=\"true\"]').textContent.trim()", 'Playful');
    $page->script("[...document.querySelectorAll('.rg-admin-studio__suggestions-action button')].find((button) => button.textContent.trim() === 'Generate suggestions').click()");

    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'queued');

    expect($page->script("document.querySelector('[data-suggestions]').hasAttribute('wire:poll.3s.visible')"))->toBeTrue()
        ->and($page->script("document.querySelector('.rg-admin-studio__suggestions-action button').disabled"))->toBeTrue()
        ->and(StudioPlanTextGeneration::query()->sole()->tone->value)->toBe('playful')
        ->and(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);

    // A worker takes the job meanwhile.
    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);
    runStudioPostTextsJob(StudioPlanTextGeneration::query()->sole());

    waitForScript($page, "document.querySelector('[data-suggestions]')?.dataset.suggestions", 'ready', 10.0);

    expect($page->script(suggestionTexts('titles')))->toBe(StudioPlanTextGenerationFactory::titles())
        ->and($page->script(suggestionTexts('descriptions')))->toBe(StudioPlanTextGenerationFactory::descriptions())
        ->and($page->script("document.querySelector('[data-suggestions]').hasAttribute('wire:poll.3s.visible')"))->toBeFalse()
        ->and($page->script("document.querySelector('.rg-admin-studio__suggestions-action button').textContent.trim()"))->toBe('Generate 10 more');

    // Use this title: in the field, marked in use, one unsaved change, nothing saved.
    useSuggestion($page, 'titles', 2);
    waitForScript($page, "document.getElementById('rg-admin-studio-title').value", 'Nobody told him it was cloudy');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');

    expect($page->script("document.querySelectorAll('#rg-admin-studio-suggestions-titles ~ .rg-admin-studio__suggestion-set .rg-admin-studio__suggestion--used').length"))->toBe(1)
        ->and($plan->fresh()->title)->toBe('A harbour');

    // Use this description, then edited by hand like any field.
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
        ->and($page->script("document.querySelectorAll('.rg-admin-studio__suggestions-action').length"))->toBe(0);
});
