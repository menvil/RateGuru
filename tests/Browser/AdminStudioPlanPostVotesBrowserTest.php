<?php

use App\Enums\StudioActionType;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The Post step's Votes tab in a real browser: reached with Continue from the
 * post's texts, drawn whole at every width, its votes saved as each setting
 * moves, and a vote's moment set by hand on the Timing tab.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create(['username' => 'daria_k']));

    $author = User::factory()->persona()->create(['username' => 'night_owl', 'display_name' => 'Night Owl']);
    collect(['Anna Fox', 'Boris Lane', 'Carl Moss'])->each(fn (string $name) => User::factory()->persona()->create(['display_name' => $name]));
    $this->plan = StudioPlan::factory()->authoredBy($author)->create(['title' => 'A quiet harbour at dawn']);
});

/** Opens the plan's editor on the Post step's Votes tab, going there as an editor does: Continue, twice. */
function visitPostVotes(StudioPlan $plan, int $width = 1440): mixed
{
    $page = visitAdminEditor("/admin/studio-plans/{$plan->id}/edit", $width);

    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 2 of 4 · Post · Titles & texts');
    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 2 of 4 · Post · Votes');
    waitForScript($page, "document.querySelector('.rg-admin-studio__votes-step').hidden === false && document.querySelector('.rg-admin-studio__post-texts').hidden === true");

    return $page;
}

it('draws the Votes tab whole at every width, never scrolling the page sideways', function (int $width) {
    $page = visitPostVotes($this->plan, $width);

    expect($page->script(<<<'JS'
        (() => ({
            cards: [...document.querySelectorAll('.rg-admin-studio__votes-step .rg-admin-card__title')].map((title) => title.textContent.trim()),
            tabs: [...document.querySelectorAll('.rg-admin-studio__votes-tabs .rg-admin-tab')].map((tab) => tab.textContent.trim().replace(/\s+/g, ' ')),
            author: [...document.querySelectorAll('.rg-admin-studio__votes-author-name, .rg-admin-studio__votes-author-note')].map((part) => part.textContent.replace(/\s+/g, ' ').trim()),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe([
        'cards' => ['Post voters', 'Post votes'],
        'tabs' => ['Votes & answers', 'Voters 0', 'Timing'],
        'author' => ['Night Owl · author', 'Can’t vote on own post'],
        'overflow' => false,
    ]);
})->with([1440, 1024, 390]);

it('saves the post\'s up votes as they are set, and a vote\'s moment set by hand on the Timing tab', function () {
    $page = visitPostVotes($this->plan);
    $summary = "document.querySelector('.rg-admin-studio__votes-step .rg-admin-studio__generate-summary').textContent.replace(/\\s+/g, ' ').trim()";

    $page->click('button[aria-label="Plan up and down votes"]');
    waitForScript($page, "!! document.getElementById('rg-admin-votes-up')");

    foreach ([1 => '1 vote saved · +1 / −0 on the post · 0 answers', 2 => '2 votes saved · +2 / −0 on the post · 0 answers'] as $count => $saved) {
        $page->click('button[aria-label="Increase up votes"]');
        waitForScript($page, "document.querySelector('.rg-admin-studio__votes-stat-value').textContent.trim()", "+{$count} / −0");
        waitForScript($page, $summary, $saved);
    }

    $votes = StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->orderBy('offset_seconds')->get();

    expect($votes)->toHaveCount(2)
        ->and($votes->pluck('type')->unique()->all())->toBe([StudioActionType::VotePost])
        ->and($votes->pluck('persona_user_id'))->not->toContain($this->plan->author_user_id)
        ->and($votes->pluck('offset_seconds')->all())->toBe([300, 86_700])
        ->and($this->plan->fresh()->lock_version)->toBe(3);

    // The first vote, moved to two hours after the post: saved at once, the other kept where it was.
    $page->script("[...document.querySelectorAll('.rg-admin-studio__votes-tabs .rg-admin-tab')].find((tab) => tab.textContent.trim() === 'Timing').click()");
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__votes-table [data-vote=\"post:{$votes[0]->persona_user_id}\"] .rg-admin-studio__offset-input')");
    $page->script("(() => { const input = document.querySelector('.rg-admin-studio__votes-table [data-vote=\"post:{$votes[0]->persona_user_id}\"] .rg-admin-studio__offset-input'); input.value = '120'; input.dispatchEvent(new Event('change', { bubbles: true })); })()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__votes-table [data-vote=\"post:{$votes[0]->persona_user_id}\"] .rg-admin-studio__offset-input')?.value ?? ''", '2');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-studio__votes-blocks .rg-admin-badge')].some((badge) => badge.textContent.trim() === 'Set by hand')");

    $moments = [$votes[0]->persona_user_id => 7200, $votes[1]->persona_user_id => 86_700];
    ksort($moments);

    expect(StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->orderBy('persona_user_id')->pluck('offset_seconds', 'persona_user_id')->all())->toBe($moments)
        ->and($this->plan->fresh()->lock_version)->toBe(4);
});
