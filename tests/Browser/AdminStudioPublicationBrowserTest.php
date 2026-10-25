<?php

use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * A publication from its Publication step to its last comment, and the
 * Action queue, in a real browser: the preview of what approval writes,
 * Review and confirm asked first, the plan run to its end — every job of it
 * handled the moment it is pushed, as the test server runs them — and
 * followed; the executor switched on from the queue, and an action cancelled
 * there with its branch. Neither page ever scrolls sideways.
 *
 * One kind of page per test: the test server reads the top bar's actions
 * from the first page it drew.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'daria_k']);
    actingAs($this->admin);
});

it('approves a historical plan from its Publication step once confirmed, runs it to its end, and follows it there', function (int $width) {
    switchStudioExecution(true);
    [$plan] = approvableStudioPlanWithDiscussion(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::now()->subDays(5)->startOfMinute()]);
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit", $width);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__step')][3].click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__position')?.textContent.trim()", 'Step 4 of 4 · Publication');
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__plan [role=\"table\"] [role=\"row\"]:not(.rg-admin-table__row--head)').length", 7);

    expect($page->script(<<<'JS'
        (() => ({
            overdue: [...document.querySelectorAll('.rg-admin-card__title')].some((title) => title.textContent.trim() === 'Overdue actions'),
            ready: document.body.textContent.includes('Everything the approval checks is in order.'),
            states: [...new Set([...document.querySelectorAll('.rg-admin-studio__plan .rg-admin-badge')].map((badge) => badge.textContent.trim()))],
            review: document.querySelector('.rg-admin-studio__footer .rg-admin-button--primary')?.disabled,
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe(['overdue' => true, 'ready' => true, 'states' => ['Ready to run'], 'review' => false, 'overflow' => false]);

    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.getElementById('rg-admin-studio-plan-approve-title')?.textContent.trim()", 'Confirm this publication?');
    waitForScript($page, "document.getElementById('rg-admin-studio-plan-approve').textContent.includes('7 overdue: they run right after approval')");

    $page->click('#rg-admin-studio-plan-approve .rg-admin-button--primary');

    // Opened again, closed, the plan run to its end.
    waitForScript($page, "document.querySelector('.rg-admin-studio__header .rg-admin-badge')?.textContent.trim()", 'Completed', 10.0);
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Publication approved. Its actions run as they become due.');

    expect($page->script(<<<'JS'
        (() => ({
            step: document.querySelector('.rg-admin-studio__position')?.textContent.trim(),
            status: document.body.textContent.includes('Publication status'),
            openPost: [...document.querySelectorAll('.rg-admin-studio__plan a')].some((link) => link.textContent.trim() === 'Open post'),
            states: [...new Set([...document.querySelectorAll('.rg-admin-studio__plan [role="table"] .rg-admin-badge')].map((badge) => badge.textContent.trim()))],
            review: !! [...document.querySelectorAll('.rg-admin-studio__footer button')].find((button) => button.textContent.includes('Review and confirm')),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe(['step' => 'Step 4 of 4 · Publication', 'status' => true, 'openPost' => true, 'states' => ['Done'], 'review' => false, 'overflow' => false]);

    expect($plan->fresh()->status)->toBe(StudioPlanStatus::Completed)
        ->and(Post::query()->sole()->created_at->toDateTimeString())->toBe($plan->publish_at->toDateTimeString());
})->with([1440, 390]);

it('runs what waited the moment the executor is switched on in the Action queue, and cancels an action there with its branch once confirmed', function (int $width) {
    switchStudioExecution(false);
    [$plan, $saved] = approvableStudioPlanWithDiscussion();
    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);
    $page = visitAdminEditor('/admin/studio-actions', $width);

    waitForScript($page, "document.querySelector('.rg-admin-studio-queue__executor-text strong')?.textContent.trim()", 'Executor off.');
    expect($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    // Switched on, asked first: the post, due now, runs at once.
    $page->click('.rg-admin-studio-queue__executor .rg-admin-button');
    waitForScript($page, "document.getElementById('rg-admin-studio-execution-switch-title')?.textContent.trim()", 'Switch the executor on?');
    $page->click('#rg-admin-studio-execution-switch .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-studio-queue__executor-text strong')?.textContent.trim()", 'Executor on.');

    expect($actions['post']->fresh()->status)->toBe(StudioActionStatus::Done);

    // c1, still to come, cancelled with its two replies.
    $page->click("[wire\\:click=\"askToCancel({$actions['c1']->id})\"]");
    waitForScript($page, "document.getElementById('rg-admin-studio-action-cancel-title')?.textContent.trim()", 'Cancel this action?');
    $page->click('#rg-admin-studio-action-cancel .rg-admin-button--primary');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Action #'.$actions['c1']->id.' cancelled with 2 actions depending on it');

    expect(StudioAction::query()->whereKey([$actions['c1']->id, $actions['c2']->id, $actions['c3']->id])->pluck('status')->unique()->values()->all())->toBe([StudioActionStatus::Cancelled])
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();
})->with([1440, 390]);
