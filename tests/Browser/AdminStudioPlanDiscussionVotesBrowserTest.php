<?php

use App\Enums\StudioActionType;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The Discussion step's comment votes in a real browser: the Comment voters
 * and the Comment votes beside the Discussion settings, drawn whole at every
 * width, the discussion's own tabs and dialog working as before, voters
 * chosen, a total shared out over the comments — each change saved at once
 * and counted on them and in the Generate strip — a comment's breakdown
 * opened beside the tree, the votes drawn again after a reload and in one
 * list with the comments on the Timing tab — a comment moved there taking its
 * vote along, a vote given a moment of its own — and the step left at once
 * with every change saved, but asked about with one that can't be.
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

/** The comment votes as the Generate strip says them after the discussion's numbers, and whether it marks them not saved. */
function commentVotesSummary(): string
{
    return <<<'JS'
        (() => {
            const strip = document.querySelector('.rg-admin-studio__discussion .rg-admin-studio__generate-summary');
            const unsaved = strip?.querySelector('.rg-admin-studio__node-unsaved');

            return (strip?.querySelector('.rg-admin-studio__generate-votes')?.textContent.trim() ?? '') + (unsaved && getComputedStyle(unsaved).display !== 'none' ? ' · Not saved' : '');
        })()
    JS;
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
            strips: [...document.querySelectorAll('.rg-admin-studio__discussion-step .rg-admin-studio__generate-label')].map((label) => label.textContent.trim()),
            votes: document.querySelector('.rg-admin-studio__generate-votes')?.textContent.trim(),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe([
        'settings' => ['Discussion settings', 'Comment voters', 'Comment votes'],
        'beside' => $beside,
        'tabs' => ['Comments 3', 'Voters', 'Timing'],
        'breakdowns' => ['See breakdown', 'See breakdown', 'See breakdown'],
        'cards' => 0,
        'strips' => ['Generate from the settings above'],
        'votes' => '· no comment votes',
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
    waitForScript($page, "document.querySelector('.rg-admin-studio__tree-voters').hidden === true && [...document.querySelectorAll('.rg-admin-studio__tree-timing thead th')].map((th) => th.textContent.trim()).join(' | ')", '# | Action | Executor | Parent | Offset from post | After its parent | Dependency | Votes');
    waitForScript($page, "document.querySelector('.rg-admin-studio__tree-timing').hidden === false && [...document.querySelectorAll('.rg-admin-studio__tree-timing tbody tr')].map((row) => row.cells[1].textContent.trim().replace(/\\s+/g, ' ')).join(' | ')", 'Comment C1 | Reply C2 | Comment C3');
    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Comments')).click()");
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node').length", 3);

    $page->script("[...document.querySelectorAll('.rg-admin-studio__tree-card .rg-admin-button')].find((button) => button.textContent.trim() === 'Comment').click()");
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__dialog, [role=\"dialog\"]')");
});

it('chooses voters and shares a total out over the comments, each change saved at once — counted on them and in the Generate strip — and draws them again after a reload, in time on the Timing tab', function () {
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

    waitForScript($page, commentVotesSummary(), '· 3 comment votes (+3 / −0)');
    waitForScript($page, treeUpVotes(), 3);
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-studio__tree .rg-admin-studio__node-unsaved')].filter((note) => getComputedStyle(note).display !== 'none').length", 0);

    $page->script("document.querySelector('.rg-admin-studio__node[data-node=\"C1\"] .rg-admin-studio__node-breakdown').click()");
    waitForScript($page, "document.querySelectorAll('.rg-admin-studio__breakdown .rg-admin-studio__breakdown-vote').length", 1);
    waitForScript($page, "document.querySelector('.rg-admin-studio__breakdown-footer').textContent.trim()", 'Every change is saved at once.');

    $saved = StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->with('discussionNode')->get();

    expect($saved)->toHaveCount(3)
        ->and($saved->pluck('type')->unique()->all())->toBe([StudioActionType::VoteComment])
        ->and($saved->every(fn (StudioPlanVote $vote): bool => $vote->persona_user_id !== $vote->discussionNode->persona_user_id))->toBeTrue()
        ->and($saved->pluck('discussion_node_id')->unique())->toHaveCount(3);

    $again = visitDiscussionVotes($this->plan);

    waitForScript($again, commentVotesSummary(), '· 3 comment votes (+3 / −0)');
    waitForScript($again, treeUpVotes(), 3);
    $again->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Timing')).click()");
    // The comments and the votes on them in one list.
    waitForScript($again, "document.querySelectorAll('.rg-admin-studio__tree-timing tbody tr.rg-admin-studio__timing-vote').length", 3);
    waitForScript($again, "document.querySelectorAll('.rg-admin-studio__tree-timing tbody tr:not(.rg-admin-studio__timing-vote)').length", 3);

    // C3 moved to 30 min is saved at once, and its vote, 5 min after it, moves with it.
    $c3 = StudioDiscussionNode::query()->where('studio_plan_id', $this->plan->id)->where('body', 'Where is this?')->value('id');
    $offset = fn (string $row): string => "document.querySelector('.rg-admin-studio__tree-timing [data-timing^=\"{$row}\"] .rg-admin-studio__offset-input')?.value ?? ''";
    $after = fn (string $row): string => "document.querySelector('.rg-admin-studio__tree-timing [data-timing^=\"{$row}\"]')?.cells[5].textContent.trim() ?? ''";
    $type = fn (string $row, string $value): string => "(() => { const input = document.querySelector('.rg-admin-studio__tree-timing [data-timing^=\"{$row}\"] .rg-admin-studio__offset-input'); input.value = '{$value}'; input.dispatchEvent(new Event('change', { bubbles: true })) })()";

    $again->script($type("node-{$c3}", '30'));
    waitForScript($again, $offset("vote-{$c3}-"), '35');

    expect(StudioDiscussionNode::query()->findOrFail($c3)->offset_seconds)->toBe(1800)
        ->and(StudioPlanVote::query()->where('discussion_node_id', $c3)->value('offset_seconds'))->toBe(2100);

    // Its vote given a moment of its own: saved at once, nothing left to save.
    $again->script($type("vote-{$c3}-", '50'));
    waitForScript($again, $after("vote-{$c3}-"), '+20 min');
    waitForScript($again, "!! document.querySelector('.rg-admin-studio__timing-footer')", false);

    expect(StudioPlanVote::query()->where('discussion_node_id', $c3)->value('offset_seconds'))->toBe(3000);

    $again->script("[...document.querySelectorAll('.rg-admin-studio__tree-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim().startsWith('Voters')).click()");
    // Each voter once, however the draw shared the three votes among them.
    waitForScript($again, "document.querySelectorAll('.rg-admin-studio__tree-voters tbody tr:not(.rg-admin-studio__votes-idle)').length", $saved->pluck('persona_user_id')->unique()->count());
});

it('goes on from the step at once with every comment vote saved, and asks first with a change that can\'t be saved', function () {
    $page = visitDiscussionVotes($this->plan);
    $more = 'button[aria-label="More up votes on C1"]';
    $count = "document.querySelector('.rg-admin-studio__breakdown input[aria-label=\"Up votes on C1\"]')?.value ?? ''";
    $continue = "[...document.querySelectorAll('.rg-admin-studio__footer button')].find((button) => button.textContent.trim() === 'Continue').click()";
    $leave = fn (string $label): string => "[...document.querySelectorAll('#rg-admin-comment-votes-leave button')].find((button) => button.textContent.trim() === '{$label}').click()";

    $page->script("document.querySelector('.rg-admin-studio__node[data-node=\"C1\"] .rg-admin-studio__node-breakdown').click()");

    foreach (['1', '2', '3'] as $up) {
        $page->click($more);
        waitForScript($page, $count, $up);
    }

    waitForScript($page, commentVotesSummary(), '· 3 comment votes (+3 / −0)');

    // A fourth on C1: three Personas but its own can vote on it.
    $page->click($more);
    waitForScript($page, commentVotesSummary(), '· comment votes: fix what is marked on C1 · Not saved');

    $page->script($continue);
    waitForScript($page, "document.querySelector('#rg-admin-comment-votes-leave .rg-admin-dialog-facts')?.textContent.trim().replace(/\\s+/g, ' ') ?? ''", 'C1 · Its 4 votes need 4 personas who can vote on it; 3 can — 1 short. Choose more personas, or ask for fewer.');
    $page->script($leave('Keep editing'));
    waitForScript($page, "! document.querySelector('#rg-admin-comment-votes-leave')");

    expect($page->script("document.querySelector('.rg-admin-studio__position').textContent.trim()"))->toBe('Step 3 of 4 · Discussion');

    // Back to three: saved at once, and Continue goes on.
    $page->click('button[aria-label="Fewer up votes on C1"]');
    waitForScript($page, commentVotesSummary(), '· 3 comment votes (+3 / −0)');
    $page->script($continue);
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 4 of 4 · Publication');

    expect(StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->where('type', StudioActionType::VoteComment)->count())->toBe(3);
});

it('picks the discussion’s tone among its settings, saying it in the Generate strip, whole at every width', function (int $width) {
    $page = visitDiscussionVotes($this->plan, $width);
    $tones = "[...document.querySelectorAll('.rg-admin-studio__discussion .rg-admin-studio__tone')]";
    $chosen = "document.querySelector('.rg-admin-studio__discussion .rg-admin-studio__tone[aria-pressed=\"true\"]').textContent.trim()";

    expect($page->script("{$tones}.map((tone) => tone.textContent.trim())"))->toBe(['Playful', 'Humour', 'Cheeky', 'Spicy / Adult humour', 'Irony', 'Neutral'])
        ->and($page->script($chosen))->toBe('Cheeky')
        ->and($page->script("{$tones}.every((tone) => tone.getBoundingClientRect().right <= document.documentElement.clientWidth)"))->toBeTrue()
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    $page->script("{$tones}.find((tone) => tone.textContent.trim() === 'Spicy / Adult humour').click()");
    waitForScript($page, $chosen, 'Spicy / Adult humour');
    waitForScript($page, "document.querySelector('.rg-admin-studio__discussion .rg-admin-studio__generate-summary').textContent.includes('· Spicy / Adult humour')");
})->with([1440, 390]);
