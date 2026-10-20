<?php

use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * The publication editor's Image step in a real browser: a link pasted is
 * imported on its own once typing pauses — no Save — the photo is shown
 * with Replace and Remove from draft in place of the sources, its analysis
 * is asked for at once, and the card alone reads itself again until the
 * worker's result is there and then stops; nothing typed is saved for it. On
 * a new publication the first photo makes the Draft, and the editor goes on
 * with it.
 *
 * Files from the computer are left out: the browser test server hands the
 * application no uploaded files (see AdminStudioPlansBrowserTest). The link
 * goes through the import pipeline with only the network under it scripted,
 * and the job runs in the test, as a worker would, with OpenAI faked.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    bindFakeHostResolver(['images.rateguru.test' => ['93.184.216.34']]);
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(640, 480)),
    ]));
    actingAs(User::factory()->admin()->create());
});

/** Whether a notice saying this is on screen. */
function analysisNoticeShown(string $text): string
{
    return "[...document.querySelectorAll('.rg-admin-notice')].some((notice) => notice.textContent.includes(".json_encode($text).') && notice.offsetParent !== null)';
}

/** Pastes a link into the Image step's From link field, as a person would. */
function pasteImageLink(mixed $page, string $url): void
{
    $page->script("[...document.querySelectorAll('.rg-admin-studio__segmented [role=\"radio\"]')].find((option) => option.textContent.trim() === 'From link').click()");
    waitForScript($page, "!! document.getElementById('rg-admin-studio-image-url')");
    $page->script('(() => { const field = document.getElementById("rg-admin-studio-image-url"); field.value = '.json_encode($url).'; field.dispatchEvent(new Event("input", { bubbles: true })) })()');
}

it('imports a pasted link without Save, analyses the photo, and polls until the worker\'s description is there', function () {
    $plan = StudioPlan::factory()->create(['title' => 'A harbour']);
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit");

    waitForScript($page, "document.querySelector('[data-analysis]')?.dataset.analysis", 'no_image');
    expect($page->script(analysisNoticeShown('Photo analysis is not configured.')))->toBeFalse()
        ->and($page->script("document.querySelector('.rg-admin-studio__analysis-idle')?.textContent.trim()"))->toBe('The analysis starts as soon as a photo is added.');

    pasteImageLink($page, 'https://images.rateguru.test/harbour.jpg');

    // Imported once typing paused: the photo is the plan's, and its analysis waits for a worker.
    waitForScript($page, "document.querySelector('[data-analysis]')?.dataset.analysis", 'queued', 10.0);
    waitForScript($page, analysisNoticeShown('Waiting for analysis…'));

    $plan->refresh();

    expect($plan->image_asset_id)->not->toBeNull()
        ->and($page->script("document.querySelector('.rg-admin-studio__photo-name')?.textContent.trim()"))->toBe('harbour.jpg')
        ->and($page->script("[...document.querySelectorAll('.rg-admin-studio__photo-meta button')].map((button) => button.getAttribute('aria-label') ?? button.textContent.trim())"))->toBe(['Replace', 'Remove from draft'])
        // The sources give way to the photo, until Replace.
        ->and($page->script("document.querySelector('.rg-admin-studio__chooser').offsetParent === null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-analysis]').hasAttribute('wire:poll.3s.visible')"))->toBeTrue()
        // The photo is not Save: nothing is unsaved, and the texts are as they were.
        ->and(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false])
        ->and($plan->only(['title', 'lock_version']))->toBe(['title' => 'A harbour', 'lock_version' => 2]);

    // A worker takes the job meanwhile.
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody(['summary' => 'Boats rest in a quiet harbour at dawn.']))]);
    runStudioImageAnalysisJob(StudioPlanImageAnalysis::query()->sole());

    waitForScript($page, "document.querySelector('[data-analysis]')?.dataset.analysis", 'completed', 10.0);

    expect($page->script("document.querySelector('.rg-admin-studio__analysis-sections').textContent"))->toContain('Boats rest in a quiet harbour at dawn.')
        ->and($page->script("document.querySelector('[data-analysis]').hasAttribute('wire:poll.3s.visible')"))->toBeFalse()
        ->and($page->script("[...document.querySelectorAll('.rg-admin-studio__analysis .rg-admin-button')].map((button) => button.textContent.trim())"))->toBe(['Re-run analysis'])
        ->and($page->script("document.querySelector('.rg-admin-studio__analysis .rg-admin-badge')?.textContent.trim()"))->toBe('Done');

    // Replace opens the sources again, and Cancel puts them away.
    $page->script("[...document.querySelectorAll('.rg-admin-studio__photo-meta button')].find((button) => button.textContent.trim() === 'Replace').click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__chooser').offsetParent !== null");
    $page->script("[...document.querySelectorAll('.rg-admin-studio__chooser-bar button')].find((button) => button.textContent.trim() === 'Cancel').click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__chooser').offsetParent === null");
});

it('makes the draft for the first photo on a new publication, and goes on with it with what was typed', function () {
    $page = visitAdminEditor('/admin/studio-plans/create');

    // A title typed first, on the Post step.
    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 2 of 4 · Post');
    $page->script("(() => { const field = document.getElementById('rg-admin-studio-title'); field.value = 'Harbour at dawn'; field.dispatchEvent(new Event('input', { bubbles: true })) })()");
    $page->click('.rg-admin-studio__footer .rg-admin-button--secondary');
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 1 of 4 · Image');

    pasteImageLink($page, 'https://images.rateguru.test/harbour.jpg');
    waitForScript($page, "document.querySelector('[data-analysis]')?.dataset.analysis", 'queued', 10.0);

    $plan = StudioPlan::query()->sole();

    // One empty Draft holds the photo; the title is still only typed.
    expect($plan->title)->toBeNull()
        ->and($plan->image_asset_id)->not->toBeNull()
        ->and($page->script("document.querySelector('.rg-admin-topbar__editor .rg-admin-button--primary').textContent.trim()"))->toBe('Save draft')
        ->and($page->script("document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()"))->toBe('1 unsaved change');

    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');

    waitForScript($page, 'location.pathname', "/admin/studio-plans/{$plan->id}/edit");
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Harbour at dawn saved');

    expect(StudioPlan::query()->sole())->id->toBe($plan->id)->title->toBe('Harbour at dawn')->image_asset_id->toBe($plan->image_asset_id);
});
