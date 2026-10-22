<?php

use App\Enums\StudioActionType;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The Discussion step's comment votes in a real browser: the Comment voters
 * and the Comment votes beside the Discussion settings, drawn whole at every
 * width, the discussion's own tabs and dialog working as before, voters
 * chosen, the votes distributed over the comments and counted on them at
 * once as not saved, a comment's breakdown opened beside the tree, the votes
 * saved exactly as shown and drawn again after a reload — and the step not
 * left with them unsaved without asking.
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
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__comment-votes') && !! document.querySelector('.rg-admin-studio__discussion-cards .rg-admin-studio__comment-voters') && !! document.querySelector('.rg-admin-studio__discussion-cards .rg-admin-studio__comment-vote-settings') && document.querySelector('.rg-admin-studio__discussion-step').hidden === false");

    return $page;
}

/** The comment votes' summary line, as the strip under the tree says it. */
function commentVotesSummary(): string
{
    return "document.querySelector('.rg-admin-studio__comment-votes .rg-admin-studio__generate-summary')?.textContent.trim() ?? ''";
}

/** Every up vote counted on the tree's comments. */
function treeUpVotes(): string
{
    return "[...document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node-vote[title^=\"Up votes\"]')].reduce((sum, count) => sum + Number(count.textContent.trim().match(/\\d+$/)[0]), 0)";
}

it('draws the comment voters and comment votes beside the discussion settings and a breakdown by each comment at every width, never scrolling the page sideways', function (int $width, bool $beside, bool $sideBySide) {
    $page = visitDiscussionVotes($this->plan, $width);

    expect($page->script(<<<'JS'
        (() => ({
            settings: [...document.querySelectorAll('.rg-admin-studio__discussion-cards .rg-admin-card__title')].map((title) => title.textContent.trim()),
            beside: (([settings, voters]) => settings.getBoundingClientRect().top === voters.getBoundingClientRect().top)([...document.querySelectorAll('.rg-admin-studio__discussion-cards .rg-admin-card')]),
            tabs: [...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].map((tab) => tab.textContent.trim().replace(/\s+/g, ' ')),
            breakdowns: [...document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node-breakdown')].map((button) => button.textContent.trim()),
            cards: document.querySelectorAll('.rg-admin-studio__comment-votes > .rg-admin-card').length,
            strip: document.querySelector('.rg-admin-studio__comment-votes .rg-admin-studio__generate-label').textContent.trim(),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe([
        'settings' => ['Discussion settings', 'Comment voters', 'Comment votes'],
        'beside' => $beside,
        'tabs' => ['Comments 3', 'Voters', 'Timing'],
        'breakdowns' => ['See breakdown', 'See breakdown', 'See breakdown'],
        'cards' => 0,
        'strip' => 'Prepared comment votes',
        'overflow' => false,
    ]);

    $page->script("document.querySelector('.rg-admin-studio__node[data-node=\"C2\"] .rg-admin-studio__node-breakdown').click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__breakdown .rg-admin-card__title')?.textContent.trim() ?? ''", 'Comment votes · C2');

    expect($page->script(<<<'JS'
        (() => {
            const tree = document.querySelector('.rg-admin-studio__tree-card');
            const breakdown = document.querySelector('.rg-admin-studio__breakdown');

            return {
                selected: [...document.querySelectorAll('.rg-admin-studio__node--selected')].map((node) => node.dataset.node),
                tree: getComputedStyle(tree).display !== 'none',
                sideBySide: getComputedStyle(tree).display !== 'none' && tree.getBoundingClientRect().right <= breakdown.getBoundingClientRect().left && Math.abs(tree.getBoundingClientRect().top - breakdown.getBoundingClientRect().top) < 2,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS))->toBe(['selected' => ['C2'], 'tree' => $sideBySide, 'sideBySide' => $sideBySide, 'overflow' => false]);

    $page->script("document.querySelector('.rg-admin-studio__breakdown button[aria-label=\"Close the breakdown\"]').click()");
    waitForScript($page, "! document.querySelector('.rg-admin-studio__breakdown') && getComputedStyle(document.querySelector('.rg-admin-studio__tree-card')).display !== 'none'");
})->with([[1440, true, true], [1024, true, true], [390, false, false]]);

it('keeps the discussion\'s own tabs and comment dialog working beside the votes', function () {
    $page = visitDiscussionVotes($this->plan);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Voters')).click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__tree-voters').hidden === false && !! document.querySelector('.rg-admin-studio__tree-voters .rg-admin-studio__votes-toolbar')");
    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Timing')).click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__tree-voters').hidden === true && [...document.querySelectorAll('.rg-admin-studio__tree-card thead th')].some((th) => th.textContent.trim() === 'When the votes come')");
    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Comments')).click()");
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node').length", 3);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-card .rg-admin-button')].find((button) => button.textContent.trim() === 'Comment').click()");
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__dialog, [role=\"dialog\"]')");
});

it('chooses voters, distributes the votes over the comments — counted on them at once as not saved — opens a breakdown, and saves exactly what is shown, drawn again after a reload', function () {
    $page = visitDiscussionVotes($this->plan);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-voters [role=\"radio\"]')].find((option) => option.textContent.trim() === 'Choose').click()");
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__comment-voters .rg-admin-studio__persona-option').length > 0");

    foreach ([1, 2, 3] as $chosen) {
        $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-voters .rg-admin-studio__persona-option')].find((option) => option.getAttribute('aria-checked') === 'false').click()");
        waitForScript($page, "document.querySelectorAll('.rg-admin-studio__comment-voters .rg-admin-studio__persona-option[aria-checked=\"true\"]').length", $chosen);
    }

    foreach (['1', '2', '3'] as $count) {
        $page->click('button[aria-label="Increase total up"]');
        waitForScript($page, "document.getElementById('rg-admin-comment-votes-total-up').value", $count);
    }

    $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-vote-settings .rg-admin-button')].find((button) => button.textContent.trim() === 'Distribute automatically').click()");
    waitForScript($page, commentVotesSummary(), '3 votes on 3 comments · not saved yet.');
    waitForScript($page, treeUpVotes(), 3);
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node-unsaved')].filter((note) => getComputedStyle(note).display !== 'none').length", 3);

    expect(StudioPlanVote::query()->exists())->toBeFalse();

    $page->script("document.querySelector('.rg-admin-studio__node[data-node=\"C1\"] .rg-admin-studio__node-breakdown').click()");
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__breakdown .rg-admin-studio__breakdown-vote').length", 1);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__comment-votes .rg-admin-studio__votes-actions button')].find((button) => button.textContent.trim() === 'Save votes').click()");
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Comment votes saved');
    waitForScript($page, commentVotesSummary(), '3 votes are saved on the comments.');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node-unsaved')].filter((note) => getComputedStyle(note).display !== 'none').length", 0);
    waitForScript($page, treeUpVotes(), 3);

    $saved = StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->with('discussionNode')->get();

    expect($saved)->toHaveCount(3)
        ->and($saved->pluck('type')->unique()->all())->toBe([StudioActionType::VoteComment])
        ->and($saved->every(fn (StudioPlanVote $vote): bool => $vote->persona_user_id !== $vote->discussionNode->persona_user_id))->toBeTrue()
        ->and($saved->pluck('discussion_node_id')->unique())->toHaveCount(3);

    $again = visitDiscussionVotes($this->plan);

    waitForScript($again, commentVotesSummary(), '3 votes are saved on the comments.');
    waitForScript($again, treeUpVotes(), 3);
    $again->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Voters')).click()");
    waitForScript($again, "document.querySelectorAll('.rg-admin-studio__tree-voters tbody tr:not(.rg-admin-studio__votes-idle)').length", 3);
});

it('asks before the step is left with its comment votes not saved, and goes on once they are saved', function () {
    $page = visitDiscussionVotes($this->plan);

    $page->script("document.querySelector('.rg-admin-studio__node[data-node=\"C1\"] .rg-admin-studio__node-breakdown').click()");
    $page->click('button[aria-label="More up votes on C1"]');
    waitForScript($page, commentVotesSummary(), '1 vote on 1 comment · not saved yet.');

    $continue = "[...document.querySelectorAll('.rg-admin-studio__footer button')].find((button) => button.textContent.trim() === 'Continue').click()";
    $leave = fn (string $label): string => "[...document.querySelectorAll('#rg-admin-comment-votes-leave button')].find((button) => button.textContent.trim() === '{$label}').click()";

    $page->script($continue);
    waitForScript($page, "!! document.querySelector('#rg-admin-comment-votes-leave')");
    $page->script($leave('Keep editing'));
    waitForScript($page, "! document.querySelector('#rg-admin-comment-votes-leave')");

    expect($page->script("document.querySelector('.rg-admin-studio__position').textContent.trim()"))->toBe('Step 3 of 4 · Discussion');

    $page->script($continue);
    waitForScript($page, "!! document.querySelector('#rg-admin-comment-votes-leave')");
    $page->script($leave('Save and continue'));
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 4 of 4 · Publication');

    expect(StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->where('type', StudioActionType::VoteComment)->count())->toBe(1);
});
