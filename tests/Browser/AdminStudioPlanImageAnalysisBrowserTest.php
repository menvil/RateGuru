<?php

use App\Jobs\Studio\AnalyzeStudioPlanImageJob;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * Photo analysis on the publication editor's Image step, in a real browser:
 * Analyze photo queues an attempt, the card alone reads itself again until
 * the worker's result is there and then stops, the draft is never touched —
 * and while a new photo waits to be saved, the card says to save it first.
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

/** The card's state, as it draws it. */
function analysisCardState(mixed $page): string
{
    return (string) $page->script("document.querySelector('[data-analysis]')?.dataset.analysis ?? ''");
}

/** Whether a notice saying this is on screen. */
function analysisNoticeShown(string $text): string
{
    return "[...document.querySelectorAll('.rg-admin-notice')].some((notice) => notice.textContent.includes(".json_encode($text).') && notice.offsetParent !== null)';
}

it('analyses the saved photo on request, polling until the worker\'s description is there and then stopping', function () {
    $plan = studioPlanWithStoredPhoto(['title' => 'A harbour']);
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit");

    waitForScript($page, "document.querySelector('[data-analysis]')?.dataset.analysis", 'current');
    expect($page->script("document.querySelector('[data-analysis]').hasAttribute('wire:poll.3s.visible')"))->toBeFalse();

    $page->script("[...document.querySelectorAll('.rg-admin-studio__analysis .rg-admin-button')].find((button) => button.textContent.trim() === 'Analyze photo').click()");
    waitForScript($page, "document.querySelector('[data-analysis]')?.dataset.analysis", 'queued');
    waitForScript($page, analysisNoticeShown('Waiting for analysis…'));
    expect($page->script("document.querySelector('[data-analysis]').hasAttribute('wire:poll.3s.visible')"))->toBeTrue();

    // A worker takes the job meanwhile.
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody(['summary' => 'Boats rest in a quiet harbour at dawn.']))]);
    $analysis = StudioPlanImageAnalysis::query()->sole();
    app()->call([new AnalyzeStudioPlanImageJob($analysis->id, $analysis->attempt_id), 'handle']);

    // The card reads itself again and finds it; nothing else on the page was asked.
    waitForScript($page, "document.querySelector('[data-analysis]')?.dataset.analysis", 'completed', 10.0);

    expect($page->script("document.querySelector('.rg-admin-studio__analysis-sections').textContent"))->toContain('Boats rest in a quiet harbour at dawn.')
        ->and($page->script("document.querySelector('[data-analysis]').hasAttribute('wire:poll.3s.visible')"))->toBeFalse()
        ->and($page->script("[...document.querySelectorAll('.rg-admin-studio__analysis .rg-admin-button')].map((button) => button.textContent.trim())"))->toBe(['Re-run analysis'])
        // Analyze photo is not Save: nothing is unsaved, and the plan is as it was.
        ->and(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false])
        ->and(StudioPlan::query()->findOrFail($plan->id)->only(['title', 'lock_version']))->toBe(['title' => 'A harbour', 'lock_version' => 1]);
});

it('asks for the draft to be saved before a new photo is analysed, and shows the analysis again once the link is dropped', function () {
    $plan = studioPlanWithStoredPhoto();
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit");

    waitForScript($page, "document.querySelector('[data-analysis]')?.offsetParent !== null");
    expect($page->script(analysisNoticeShown('Save the draft first to analyse the new photo.')))->toBeFalse();

    $page->script("document.querySelector('.rg-admin-studio__segmented [role=\"radio\"]:last-child').click()");
    waitForScript($page, "!! document.getElementById('rg-admin-studio-image-url')");
    $page->script("(() => { const field = document.getElementById('rg-admin-studio-image-url'); field.value = 'https://example.com/photo.jpg'; field.dispatchEvent(new Event('input', { bubbles: true })) })()");

    // Read from the draft as typed: no request is needed to say so.
    waitForScript($page, analysisNoticeShown('Save the draft first to analyse the new photo.'));
    waitForScript($page, "document.querySelector('[data-analysis]').offsetParent === null");

    $page->script("document.querySelector('.rg-admin-studio__segmented [role=\"radio\"]:first-child').click()");
    waitForScript($page, "document.querySelector('[data-analysis]')?.offsetParent !== null");

    expect($page->script(analysisNoticeShown('Save the draft first to analyse the new photo.')))->toBeFalse()
        ->and(analysisCardState($page))->toBe('current');
    Queue::assertNothingPushed();
});
