<?php

use App\Enums\StudioActionType;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The Post step's Votes tab in a real browser: reached with Continue from the
 * post's texts, drawn whole at every width, its votes prepared from the
 * settings, shown as a preview and saved exactly as shown.
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
        'cards' => ['Post voters', 'Post votes', 'Saved votes'],
        'tabs' => ['Votes & answers', 'Voters 0', 'Timing'],
        'author' => ['Night Owl · author', 'Can’t vote on own post'],
        'overflow' => false,
    ]);
})->with([1440, 1024, 390]);

it('prepares the post\'s up votes, shows them as a preview and saves exactly those', function () {
    $page = visitPostVotes($this->plan);

    $page->click('button[aria-label="Plan up and down votes"]');
    waitForScript($page, "!! document.getElementById('rg-admin-votes-up')");

    foreach (['1', '2'] as $count) {
        $page->click('button[aria-label="Increase up votes"]');
        waitForScript($page, "document.querySelector('.rg-admin-studio__votes-stat-value').textContent.trim()", "+{$count} / −0");
    }

    $page->script("[...document.querySelectorAll('.rg-admin-studio__votes-actions button')].find((button) => button.textContent.trim() === 'Build preview').click()");
    waitForScript($page, "document.querySelector('.rg-admin-studio__votes-step .rg-admin-studio__generate-summary').textContent.trim()", '2 votes in the preview, not saved yet. Save keeps exactly these.');

    $previewed = $page->script("[...document.querySelectorAll('.rg-admin-studio__votes-main .rg-admin-card')].find((card) => card.querySelector('.rg-admin-card__title')?.textContent.trim() === 'Preview').querySelectorAll('tbody .rg-admin-studio__persona-name').length");

    expect($previewed)->toBe(2)
        ->and(StudioPlanVote::query()->exists())->toBeFalse();

    $page->script("[...document.querySelectorAll('.rg-admin-studio__votes-actions button')].find((button) => button.textContent.trim() === 'Save votes').click()");
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Votes saved');
    waitForScript($page, "document.querySelector('.rg-admin-studio__votes-step .rg-admin-studio__generate-summary').textContent.trim()", '2 votes are saved on the post and its questions.');

    $saved = StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->with('persona')->get();

    expect($saved)->toHaveCount(2)
        ->and($saved->pluck('type')->unique()->all())->toBe([StudioActionType::VotePost])
        ->and($saved->pluck('persona_user_id'))->not->toContain($this->plan->author_user_id)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});
