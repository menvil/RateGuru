<?php

use App\Enums\PostStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Reports in a real browser: the chain docked beside the condensed list where
 * the window leaves room and over the full list where it does not, J and K
 * stepping through reports, closing one and the next opening, Undo, and
 * hiding the target from the chain while the report stays open.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create());

    $this->kittens = Post::factory()->published()->create(['title' => 'Free kittens, DM for price']);
    $this->rex = Post::factory()->published()->create(['title' => 'Rex at the vet']);
    $this->first = reportOn($this->kittens, ['reason' => ReportReason::Spam, 'created_at' => now()->subHours(3)]);
    $this->second = reportOn($this->rex, ['reason' => ReportReason::Offensive, 'created_at' => now()->subHours(2)]);
});

/** How the screen is laid out now: whether the chain is docked, which report it shows, the list's columns. */
function reportsLayout(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => ({
            docked: document.querySelector('.rg-admin-screen').classList.contains('rg-admin-reports--docked'),
            panel: document.getElementById('rg-admin-report-panel-title')?.textContent.trim() ?? null,
            columns: [...document.querySelectorAll('[role="columnheader"]')].filter((cell) => cell.offsetParent !== null).map((cell) => cell.innerText.trim()),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS);
}

it('docks the first report\'s chain beside a condensed list where the window leaves room', function () {
    $page = visitAdminEditor('/admin/reports', 1440);
    waitForScript($page, "document.getElementById('rg-admin-report-panel-title')?.textContent.trim()", "Report #{$this->first->id}");

    expect(reportsLayout($page))->toBe([
        'docked' => true,
        'panel' => "Report #{$this->first->id}",
        // Read as drawn: the headers are in capitals.
        'columns' => ['TARGET', 'REASON · REPORTER', 'STATUS', 'CREATED'],
        'overflow' => false,
    ]);
});

it('keeps every column where the window is narrower, and opens the chain over the list', function (int $width) {
    // Opened afresh at this width: the browser starts wider, where the first report opens on its own.
    $page = visitAdminEditor('/admin/reports', $width);
    $page->script('window.__rgBeforeNavigation = true');
    navigatePageTo($page, '/admin/reports');
    waitForScript($page, "window.__rgBeforeNavigation !== true && document.readyState === 'complete' && !! window.Livewire && window.Livewire.all().length > 0");

    expect(reportsLayout($page))->toMatchArray([
        'docked' => false,
        'panel' => null,
        'columns' => ['TYPE', 'TARGET', 'REASON', 'REPORTER', 'STATUS', 'CREATED', 'ACTIONS'],
        'overflow' => false,
    ]);

    $page->click("#rg-admin-report-{$this->second->id} .rg-admin-reports__target");
    waitForScript($page, "document.getElementById('rg-admin-report-panel-title')?.textContent.trim()", "Report #{$this->second->id}");

    expect(reportsLayout($page))->toMatchArray(['docked' => false, 'overflow' => false])
        ->and($page->script("getComputedStyle(document.getElementById('rg-admin-report-panel')).position"))->toBe('fixed');
})->with([1024, 768, 390]);

it('steps through reports with J and K', function () {
    $page = openReportChain($this->first->id);

    // From a control that is not a field: the keys are the page's there.
    $page->keys('.rg-admin-reports__panel button[aria-label="Next report"]', 'j');
    waitForScript($page, "document.getElementById('rg-admin-report-panel-title')?.textContent.trim()", "Report #{$this->second->id}");
    $page->keys('.rg-admin-reports__panel button[aria-label="Next report"]', 'k');
    waitForScript($page, "document.getElementById('rg-admin-report-panel-title')?.textContent.trim()", "Report #{$this->first->id}");
});

it('resolves the open report with a note and opens the next, and Undo opens it again', function () {
    $page = openReportChain($this->first->id);

    $page->type('#rg-admin-report-note', 'Hidden as spam.');
    $page->click('.rg-admin-reports__close .rg-admin-button--primary');
    waitForScript($page, "document.getElementById('rg-admin-report-panel-title')?.textContent.trim()", "Report #{$this->second->id}");

    expect($this->first->fresh())->status->toBe(ReportStatus::Resolved)->resolution_note->toBe('Hidden as spam.');

    $page->click('.rg-admin-toast .rg-admin-toast__action');
    waitForScript($page, "document.getElementById('rg-admin-report-panel-title')?.textContent.trim()", "Report #{$this->first->id}");

    expect($this->first->fresh()->status)->toBe(ReportStatus::Open);
});

it('hides the reported post from the chain, the report\'s reason filled in, and the report stays open', function () {
    $page = openReportChain($this->first->id);

    $page->click('.rg-admin-reports__panel button[wire\\:click="askToHideTarget"]');
    waitForScript($page, "document.getElementById('rg-admin-moderation-reason')?.value", 'Spam');
    confirmAdminDialog($page);
    waitForScript($page, "document.querySelector('.rg-admin-reports__panel .rg-admin-notice')?.textContent.includes('stays open until you resolve or ignore it') ?? false");

    expect($this->kittens->fresh()->status)->toBe(PostStatus::Hidden)
        ->and($this->first->fresh()->status)->toBe(ReportStatus::Open);
});
