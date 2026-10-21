<?php

use App\Enums\StudioActionType;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The Discussion step's comment votes in a real browser: drawn whole under the
 * discussion at every width, the discussion's own tabs and dialog working as
 * before, voters chosen, the votes distributed over the comments, shown as a
 * preview, saved exactly as shown and drawn again after a reload.
 */
beforeEach(function () {
    $admin = User::factory()->admin()->create(['username' => 'daria_k']);
    actingAs($admin);

    [$anna, $boris, $carl] = collect(['Anna Fox', 'Boris Lane', 'Carl Moss'])
        ->map(fn (string $name): User => User::factory()->persona()->create(['display_name' => $name]))
        ->all();
    $this->plan = StudioPlan::factory()->authoredBy(User::factory()->persona()->create(['display_name' => 'Night Owl']))
        ->create(['title' => 'A quiet harbour at dawn', 'created_by_user_id' => $admin->id]);
    saveStudioDiscussion($admin, $this->plan, [
        discussionNode(['key' => 'c1', 'personaUserId' => $anna->id, 'body' => 'Lovely light on the water.', 'offsetSeconds' => 300]),
        discussionNode(['key' => 'r1', 'parentKey' => 'c1', 'personaUserId' => $boris->id, 'body' => 'Agreed, the mist helps.', 'offsetSeconds' => 600]),
        discussionNode(['key' => 'c2', 'personaUserId' => $carl->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 900]),
    ]);
});

/** Opens the plan's editor on its Discussion step. */
function visitDiscussionVotes(StudioPlan $plan, int $width = 1440): mixed
{
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit", $width);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__step')][2].click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 3 of 4 · Discussion');
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__comment-votes') && document.querySelector('.rg-admin-studio__discussion-step').hidden === false");

    return $page;
}

/** The comment votes' summary line, as the strip under the card says it. */
function commentVotesSummary(): string
{
    return "document.querySelector('.rg-admin-studio__comment-votes .rg-admin-studio__generate-summary')?.textContent.trim() ?? ''";
}

it('draws the comment votes whole under the discussion at every width, never scrolling the page sideways', function (int $width) {
    $page = visitDiscussionVotes($this->plan, $width);

    expect($page->script(<<<'JS'
        (() => ({
            cards: [...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-card__title')].map((title) => title.textContent.trim()),
            tabs: [...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-studio__votes-tabs .rg-admin-tab')].map((tab) => tab.textContent.trim().replace(/\s+/g, ' ')),
            comments: [...document.querySelectorAll('.rg-admin-studio__comment-votes-table tbody tr')].map((row) => row.dataset.node),
            tree: !! document.querySelector('.rg-admin-studio__tree-card'),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe([
        'cards' => ['Comment voters', 'Comment votes', 'Saved votes'],
        'tabs' => ['Comment votes', 'Voters 0', 'Timing'],
        'comments' => ['C1', 'C2', 'C3'],
        'tree' => true,
        'overflow' => false,
    ]);
})->with([1440, 1024, 390]);

it('keeps the discussion\'s own tabs and comment dialog working beside the votes', function () {
    $page = visitDiscussionVotes($this->plan);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Timing')).click()");
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__tree-card .rg-admin-studio__timing')");
    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Comments')).click()");
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node').length", 3);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-card .rg-admin-button')].find((button) => button.textContent.trim() === 'Comment').click()");
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__dialog, [role=\"dialog\"]')");
});

it('chooses voters, distributes the votes over the comments, and saves exactly the preview, drawn again after a reload', function () {
    $page = visitDiscussionVotes($this->plan);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-votes [role=\"radio\"]')].find((option) => option.textContent.trim() === 'Choose').click()");
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-studio__persona-option').length > 0");

    foreach ([1, 2, 3] as $chosen) {
        $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-studio__persona-option')].find((option) => option.getAttribute('aria-checked') === 'false').click()");
        waitForScript($page, "document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-studio__persona-option[aria-checked=\"true\"]').length", $chosen);
    }

    foreach (['1', '2', '3'] as $count) {
        $page->click('button[aria-label="Increase total up"]');
        waitForScript($page, "document.getElementById('rg-admin-comment-votes-total-up').value", $count);
    }

    $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-button')].find((button) => button.textContent.trim() === 'Distribute automatically').click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__comment-votes .rg-admin-studio__votes-stat-value').textContent.trim()", '+3 / −0');

    $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-studio__votes-actions button')].find((button) => button.textContent.trim() === 'Build preview').click()");
    waitForScript($page, commentVotesSummary(), '3 votes in the preview, not saved yet. Save keeps exactly these.');

    expect(StudioPlanVote::query()->exists())->toBeFalse();

    $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-studio__votes-actions button')].find((button) => button.textContent.trim() === 'Save votes').click()");
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Comment votes saved');
    waitForScript($page, commentVotesSummary(), '3 votes are saved on the comments.');

    $saved = StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->with('discussionNode')->get();

    expect($saved)->toHaveCount(3)
        ->and($saved->pluck('type')->unique()->all())->toBe([StudioActionType::VoteComment])
        ->and($saved->every(fn (StudioPlanVote $vote): bool => $vote->persona_user_id !== $vote->discussionNode->persona_user_id))->toBeTrue()
        ->and($saved->pluck('discussion_node_id')->unique())->toHaveCount(3);

    $again = visitDiscussionVotes($this->plan);

    waitForScript($again, commentVotesSummary(), '3 votes are saved on the comments.');
    expect($again->script("[...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-card')].find((card) => card.querySelector('.rg-admin-card__title')?.textContent.trim() === 'Saved votes').querySelectorAll('tbody tr').length"))->toBe(3);
});
