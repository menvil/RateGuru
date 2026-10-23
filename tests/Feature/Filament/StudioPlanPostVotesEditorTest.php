<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Livewire\Admin\StudioPlanPostVotesEditor;
use App\Models\CommentVote;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use App\Support\Studio\BuildStudioPostVotesData;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Post step's Votes tab: how many vote the post up and down, how many
 * answer each rating question and with what, by which Personas and when —
 * each vote's moment set by hand if need be — saved as each setting moves,
 * the discussion's votes kept, and every write sharing the plan's one
 * version with the other editors on the page.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->author = User::factory()->persona()->create(['display_name' => 'Author Ada']);
    $this->plan = StudioPlan::factory()->authoredBy($this->author)->create(['created_by_user_id' => $this->admin->id]);
    [$this->anna, $this->boris, $this->carl, $this->dana] = collect(['Anna', 'Boris', 'Carl', 'Dana'])
        ->map(fn (string $name): User => User::factory()->persona()->create(['display_name' => $name]))
        ->all();
    [$this->group, $answers] = votableRatingQuestion(2);
    $this->answers = collect($answers);
    // The discussion's own: a comment by Anna, with Boris's vote on it.
    $this->comment = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->anna->id, 'offset_seconds' => 300]);
    $this->commentVote = StudioPlanVote::factory()->onComment($this->comment)->create(['persona_user_id' => $this->boris->id]);
});

/** The plan's post and rating votes as saved: who, what, which way or answer, when, in order. */
function savedPostVotes(StudioPlan $plan): array
{
    return StudioPlanVote::query()->where('studio_plan_id', $plan->id)->where('type', '!=', StudioActionType::VoteComment)
        ->orderBy('offset_seconds')->orderBy('sort_order')->orderBy('id')->get()
        ->map(fn (StudioPlanVote $vote): array => [$vote->persona_user_id, $vote->type->value, $vote->vote_type?->value, $vote->rating_option_id, $vote->offset_seconds, $vote->sort_order])
        ->all();
}

/** Three Personas drawn at random voting the post up twice and down once, saved as each setting moves. */
function votePostUpTwiceDownOnce(StudioPlan $plan): Testable
{
    return postVotesEditor($plan)->set('voterCount', 3)->set('postOn', true)->set('up', 2)->set('down', 1)->assertHasNoErrors();
}

/** When each vote on the plan's post comes, by Persona. */
function postVoteMoments(StudioPlan $plan): array
{
    return StudioPlanVote::query()->where('studio_plan_id', $plan->id)->where('type', StudioActionType::VotePost)->orderBy('persona_user_id')
        ->pluck('offset_seconds', 'persona_user_id')->map(fn (mixed $offset): int => (int) $offset)->all();
}

it('is for administrators who may manage plans only — drawn, or clicked', function () {
    Livewire::actingAs(User::factory()->moderator()->create())->test(StudioPlanPostVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test(StudioPlanPostVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();

    $this->actingAs($this->admin);
    $editor = postVotesEditor($this->plan);
    $this->admin->forceFill(['role' => 'moderator'])->save();

    $editor->call('redraw')->assertForbidden();
});

it('starts a plan with no votes from a random draw, its author out of it, and every active rating question there to answer', function () {
    postVotesEditor($this->plan)
        ->assertSet('voterMode', 'random')->assertSet('voterCount', 4)->assertSet('postOn', false)
        ->assertSee('No votes prepared · the post gets only real users’ votes.')
        ->assertSee('Author Ada')->assertSee('Can’t vote on own post')
        ->assertSee('of 4 personas who can vote')
        // Active on the site, so under every post: no switch to turn it on, its answers there to set.
        ->assertSee($this->group->translatedLabel())->assertSee('No answers')
        ->assertSee($this->answers[0]->translatedLabel())->assertSee($this->answers[1]->translatedLabel())
        ->assertDontSeeHtml('Plan answers for');
});

it('saves each change at once — the Personas, ways and moments drawn — keeping the discussion\'s votes', function () {
    $editor = postVotesEditor($this->plan)->set('voterCount', 3)->set('postOn', true)->assertNotDispatched(StudioPlanDiscussionEditor::VERSION_EVENT);

    expect($this->plan->fresh()->lock_version)->toBe(1);

    $editor->set('up', 2)->assertHasNoErrors()->assertSet('lockVersion', 2)
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: StudioPlanPostVotesEditor::SOURCE);
    $ups = array_column(savedPostVotes($this->plan), 0);

    $editor->set('down', 1)->assertHasNoErrors()->assertSet('lockVersion', 3)
        ->assertSee('3 votes saved · +2 / −1 on the post · 0 answers');
    $saved = savedPostVotes($this->plan);

    expect($saved)->toHaveCount(3)
        ->and(array_column($saved, 0))->not->toContain($this->author->id)
        ->and(array_unique(array_column($saved, 0)))->toHaveCount(3)
        ->and(array_count_values(array_column($saved, 2)))->toEqualCanonicalizing(['up' => 2, 'down' => 1])
        // The votes saved before stay with their Personas: the change adds one.
        ->and(array_column(array_filter($saved, fn (array $vote): bool => $vote[2] === 'up'), 0))->toEqualCanonicalizing($ups)
        ->and(StudioPlanVote::query()->whereKey($this->commentVote->id)->exists())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(3);
});

it('writes nothing for a change that leaves the votes as they are saved', function () {
    $editor = votePostUpTwiceDownOnce($this->plan);
    $saved = savedPostVotes($this->plan);

    $editor->set('voterCount', 4)->assertHasNoErrors()->set('tab', 'timing')->set('votersSearch', 'an')
        ->assertDontSee('Not saved')->assertNotDispatched(StudioPlanDiscussionEditor::VERSION_EVENT);

    expect(savedPostVotes($this->plan))->toBe($saved)->and($this->plan->fresh()->lock_version)->toBe(3);
});

it('draws again only when asked: Pick others makes another draw, saved at once', function () {
    $editor = votePostUpTwiceDownOnce($this->plan);
    $draws = [];

    foreach (range(1, 8) as $ignored) {
        $editor->call('redraw')->assertHasNoErrors();
        $draws[] = json_encode(array_column(savedPostVotes($this->plan), 0));
    }

    expect(array_unique($draws))->not->toHaveCount(1);
});

it('draws the votes again from what is saved when opened again: counts, Personas, answers and times', function () {
    postVotesEditor($this->plan)->set('voterCount', 4)->set('postOn', true)->set('up', 2)->set('down', 1)
        ->call('setRatingTotal', $this->group->id, 3)
        ->call('setTiming', 'first', 1800)->call('setTiming', 'spread', 21600)->assertHasNoErrors();
    $saved = savedPostVotes($this->plan);
    $voters = array_values(array_unique(array_column($saved, 0)));
    $ratingCounts = StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->pluck('rating_option_id')->countBy()->all();

    postVotesEditor($this->plan)
        ->assertSet('postOn', true)->assertSet('up', 2)->assertSet('down', 1)
        ->assertSet('firstAfter', 1800)->assertSet('spreadOver', 21600)->assertSet('postMoments', [])
        ->assertSet('voterMode', 'manual')->assertSet('voterIds', $voters)
        ->assertSet('ratings.'.$this->group->id.'.counts', [$this->answers[0]->id => $ratingCounts[$this->answers[0]->id] ?? 0, $this->answers[1]->id => $ratingCounts[$this->answers[1]->id] ?? 0])
        ->assertSee('6 votes saved · +2 / −1 on the post · 3 answers');

    expect(array_sum($ratingCounts))->toBe(3);
});

it('says exactly how many Personas are short, saves nothing of it — cutting nothing to fit — and Discard goes back to the votes saved', function () {
    $editor = postVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->boris->id)
        ->set('postOn', true)->set('up', 2)->assertHasNoErrors();

    $editor->set('down', 1)
        ->assertHasErrors(['post' => 'Up and down votes need 3 personas who can vote; 2 are chosen — 1 short. Choose more personas, or ask for fewer.'])
        ->assertSee('These votes need 3 personas; 2 are chosen — 1 short.')
        ->assertSee('Not saved · fix what is marked, or Discard to go back to the votes saved.')
        ->assertSet('up', 2)->assertSet('down', 1);

    expect(savedPostVotes($this->plan))->toHaveCount(2)->and($this->plan->fresh()->lock_version)->toBe(2);

    $editor->call('discard')->assertHasNoErrors()->assertSet('up', 2)->assertSet('down', 0)->assertDontSee('Not saved');
});

it('never chooses the post\'s author, and says why it is listed', function () {
    postVotesEditor($this->plan)->set('voterMode', 'manual')
        ->call('toggleVoter', $this->author->id)->assertSet('voterIds', [])
        ->assertSee('Author of this post · can’t vote on it')
        ->call('selectShown')->assertSet('voterIds', fn (array $ids): bool => ! in_array($this->author->id, $ids, true) && count($ids) === 4);
});

it('starts choosing by hand from the voters drawn, so switching changes no vote', function () {
    $editor = votePostUpTwiceDownOnce($this->plan);
    $drawn = $editor->get('drawn');

    $editor->set('voterMode', 'manual')->assertSet('voterIds', $drawn)->assertHasNoErrors();

    expect($drawn)->toHaveCount(3)->and($this->plan->fresh()->lock_version)->toBe(3);
});

it('splits the answers evenly, the rest to the first options, and moves one at a time keeping the total', function () {
    [$group, $answers] = votableRatingQuestion(3);
    // The options in the order the rating question lists them.
    collect($answers)->each(fn ($option, int $place) => $option->update(['sort_order' => $place]));
    $ids = array_map(fn ($option): int => $option->id, $answers);
    $editor = postVotesEditor($this->plan)->call('setRatingTotal', $group->id, 5)->call('evenSplit', $group->id);

    $editor->assertSet("ratings.{$group->id}.counts", [$ids[0] => 2, $ids[1] => 2, $ids[2] => 1]);

    $editor->call('moveAnswer', $group->id, $ids[2], 'up')->assertSet("ratings.{$group->id}.counts", [$ids[0] => 1, $ids[1] => 2, $ids[2] => 2])
        ->call('moveAnswer', $group->id, $ids[0], 'down')->assertSet("ratings.{$group->id}.counts", [$ids[0] => 0, $ids[1] => 3, $ids[2] => 2])
        ->call('setRatingTotal', $group->id, 10)->assertSet("ratings.{$group->id}.counts", fn (array $counts): bool => array_sum($counts) === 10 && $counts[$ids[0]] === 0);
});

it('keeps each vote\'s id when a change moves only its moment', function () {
    votePostUpTwiceDownOnce($this->plan);
    $ids = StudioPlanVote::query()->where('type', StudioActionType::VotePost)->orderBy('id')->pluck('id')->all();

    postVotesEditor($this->plan)->call('setTiming', 'first', 3600)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->min('offset_seconds'))->toBe(3600);
});

it('sets a vote\'s moment by hand, saved at once, the other votes keeping theirs until they are spread again', function () {
    $editor = postVotesEditor($this->plan)->set('voterCount', 4)->set('postOn', true)->set('up', 3)->assertHasNoErrors();
    $before = postVoteMoments($this->plan);
    $middle = array_search(43_500, $before, true);
    $set = $before;
    $set[$middle] = 7200;

    expect(collect($before)->sort()->values()->all())->toBe([300, 43_500, 86_700]);

    $editor->call('setMoment', 'post', $middle, 7200)->assertHasNoErrors()->assertSet('postMoments', $set)
        ->set('tab', 'timing')->assertSee('Set by hand');

    expect(postVoteMoments($this->plan))->toBe($set);

    // A vote added comes last in the spread; the others keep their moments.
    $editor->set('down', 1)->assertHasNoErrors();
    $now = postVoteMoments($this->plan);

    expect(array_intersect_key($now, $before))->toBe($set)
        ->and(array_values(array_diff_key($now, $before)))->toBe([86_700]);

    // Never before the post, never past thirty days, and only a vote there is: nothing is written.
    $editor->call('setMoment', 'post', $middle, -60)->assertDispatched('rg-admin-toast', message: 'Nothing can arrive before the post.', tone: 'error');
    $editor->call('setMoment', 'post', $middle, 31 * 86_400)->assertDispatched('rg-admin-toast', message: 'A vote comes at most 30 days after the post.', tone: 'error');
    $editor->call('setMoment', 'post', $this->author->id, 600)->call('setMoment', $this->group->id, $middle, 600);

    expect(postVoteMoments($this->plan))->toBe($now)->and($this->plan->fresh()->lock_version)->toBe(4);

    // Opened again, every vote keeps its moment; Spread over spaces them evenly again.
    postVotesEditor($this->plan)->assertSet('postMoments', $now)
        ->call('setTiming', 'spread', 21_600)->assertHasNoErrors()->assertSet('postMoments', []);

    expect(collect(postVoteMoments($this->plan))->sort()->values()->all())->toBe([300, 7500, 14_700, 21_900]);
});

it('brings each voter\'s vote and answers in one visit, read back as laid out when opened again', function () {
    postVotesEditor($this->plan)->set('voterCount', 3)->set('postOn', true)->set('up', 2)->call('setRatingTotal', $this->group->id, 2)->assertHasNoErrors();
    $visits = StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->where('type', '!=', StudioActionType::VoteComment)->get()->groupBy('persona_user_id');

    expect($visits)->toHaveCount(2);

    foreach ($visits as $persona => $visit) {
        $post = $visit->firstWhere('type', StudioActionType::VotePost);
        $answer = $visit->firstWhere('type', StudioActionType::VoteRating);

        expect($answer->offset_seconds - $post->offset_seconds)->toBe(BuildStudioPostVotesData::visitSteps($persona, 2)[1]);
    }

    $editor = postVotesEditor($this->plan)->assertSet('postMoments', [])->assertSet("ratings.{$this->group->id}.moments", [])
        ->set('tab', 'timing')->assertSee('Voters come')->assertSee('2 voters · first 5 min after')->assertDontSee('Set by hand');

    // An answer moved by hand: every vote keeps the moment it has from then on.
    $editor->call('setMoment', $this->group->id, $visits->keys()->first(), 7200)->assertHasNoErrors()
        ->assertSet('postMoments', fn (array $moments): bool => count($moments) === 2)->assertSee('Set by hand');
});

it('takes the post\'s votes away when they are turned off, the discussion\'s staying', function () {
    votePostUpTwiceDownOnce($this->plan)->set('postOn', false)->assertHasNoErrors()->assertSet('lockVersion', 4);

    expect(savedPostVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->commentVote->id]);
});

it('removes the post votes once asked, the discussion\'s votes staying', function () {
    votePostUpTwiceDownOnce($this->plan);

    postVotesEditor($this->plan)->call('askRemove')->assertSet('confirmingRemove', true)->assertSee('Remove the post votes?')
        ->call('confirmRemove')->assertDispatched('rg-admin-toast', message: 'Post votes removed', tone: 'success')
        ->assertSet('postOn', false)->assertSee('No votes prepared · the post gets only real users’ votes.')->assertDontSee('Not saved');

    expect(savedPostVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->commentVote->id]);
});

it('saves nothing while the post\'s author has unsaved changes, as the page says', function () {
    $editor = postVotesEditor($this->plan)
        ->assertSeeHtml('x-effect="$wire.authorChanged = ($store.adminEditor?.authorChanged ?? false)"')
        ->set('authorChanged', true)->set('postOn', true)->set('up', 1)->assertHasErrors(['author'])->assertSee('Not saved');

    expect(savedPostVotes($this->plan))->toBe([]);

    $editor->set('authorChanged', false)->set('up', 2)->assertHasNoErrors();

    expect(savedPostVotes($this->plan))->toHaveCount(2);
});

it('keeps a Persona restricted or deleted since in the saved votes, saying so and what keeps the vote from being cast', function () {
    postVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->carl->id)
        ->set('postOn', true)->set('up', 2)->assertHasNoErrors();
    $this->anna->forceFill(['status' => UserStatus::Banned])->save();
    $this->carl->forceFill(['status' => UserStatus::Deleted])->save();

    postVotesEditor($this->plan)
        ->assertSet('voterIds', fn (array $ids): bool => in_array($this->anna->id, $ids, true) && in_array($this->carl->id, $ids, true))
        ->assertSee('Banned')->assertSee('Deleted')
        ->assertSee('A deleted or restricted persona, or the post’s author, gets no new votes.')
        ->assertSee('2 votes saved · +2 / −0 on the post · 0 answers · 2 can’t be cast as they are — see the Timing tab')
        ->set('tab', 'timing')
        ->assertSee('This persona is limited, banned or shadowbanned and cannot vote. Choose another one.');
});

it('shows an answer no longer open as such, keeps it as it was, and replaces it only when asked', function () {
    postVotesEditor($this->plan)->call('setRatingTotal', $this->group->id, 2)
        ->call('moveAnswer', $this->group->id, $this->answers[1]->id, 'down')->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->pluck('rating_option_id')->all())->toBe([$this->answers[0]->id, $this->answers[0]->id]);

    $this->answers[0]->update(['is_active' => false, 'archived_at' => now()]);

    $editor = postVotesEditor($this->plan)->assertSee('Not open')->assertSee('An option no longer open keeps the answers it had');
    $editor->call('setTiming', 'first', 1800)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->pluck('rating_option_id')->unique()->all())->toBe([$this->answers[0]->id])
        ->and(StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->min('offset_seconds'))->toBe(1800);

    postVotesEditor($this->plan)->call('evenSplit', $this->group->id)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->pluck('rating_option_id')->unique()->values()->all())->toBe([$this->answers[1]->id]);
});

it('takes the version another editor on the page moved, and saves on from it', function (string $source) {
    $editor = postVotesEditor($this->plan)->set('voterCount', 3);
    $this->plan->forceFill(['lock_version' => 2, 'title' => 'Renamed'])->save();

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: $source)
        ->assertSet('lockVersion', 2)->assertSet('conflict', false);

    $editor->set('postOn', true)->set('up', 2)->assertHasNoErrors()->assertSet('lockVersion', 3);
})->with(['the post\'s editor' => ['editor'], 'the discussion\'s' => ['discussion']]);

it('takes no version somebody else wrote: nothing is saved until Reload', function () {
    $editor = postVotesEditor($this->plan)->set('voterCount', 3);
    // Another administrator's votes, saved meanwhile.
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'down', 'offsetSeconds' => 60])]);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion')
        ->assertSet('conflict', true)->assertSee('Someone changed this draft after you opened it')
        ->set('postOn', true)->set('up', 2);

    expect(savedPostVotes($this->plan))->toHaveCount(1);

    $editor->call('reload')->assertSet('conflict', false)->assertSet('lockVersion', 2)->assertSet('down', 1)
        ->assertDispatched(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 2, source: StudioPlanPostVotesEditor::SOURCE);
});

it('refuses a save made from a version somebody else replaced, writing nothing over theirs', function () {
    $editor = postVotesEditor($this->plan)->set('voterCount', 3);
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'down', 'offsetSeconds' => 60])]);

    $editor->set('postOn', true)->assertSet('conflict', true)
        ->assertDispatched('rg-admin-toast', message: 'Someone changed this draft meanwhile, so nothing was saved. Reload to see their changes.', tone: 'error');

    expect(savedPostVotes($this->plan))->toBe([[$this->dana->id, 'vote_post', 'down', null, 60, 0]]);
});

it('never puts back the votes of a comment the discussion deleted meanwhile', function () {
    $editor = postVotesEditor($this->plan)->set('voterCount', 3);

    saveStudioDiscussion($this->admin, $this->plan, []);
    expect(StudioPlanVote::query()->whereKey($this->commentVote->id)->exists())->toBeFalse();

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion')
        ->assertSet('lockVersion', 2)->set('postOn', true)->set('up', 2)->set('down', 1)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->exists())->toBeFalse()
        ->and(savedPostVotes($this->plan))->toHaveCount(3);
});

it('changes nothing of a plan past its Draft, or deleted', function () {
    $this->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

    postVotesEditor($this->plan)->assertSee('This plan is no longer a draft, so its votes can’t be changed here.')
        ->set('postOn', true)->set('up', 1)->call('redraw');

    expect(savedPostVotes($this->plan))->toBe([]);

    $draft = StudioPlan::factory()->authoredBy($this->author)->create(['created_by_user_id' => $this->admin->id]);
    $editor = postVotesEditor($draft);
    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $draft, 1);

    $editor->set('postOn', true)->assertSet('gone', true)
        ->assertDispatched('rg-admin-toast', message: 'This draft was deleted, so its votes can’t be changed any more.', tone: 'error');
});

it('casts nothing: no vote, action, job or notification', function () {
    Bus::fake();
    Notification::fake();

    votePostUpTwiceDownOnce($this->plan)->call('setRatingTotal', $this->group->id, 2)->assertHasNoErrors();

    expect(PostVote::query()->exists())->toBeFalse()
        ->and(CommentVote::query()->exists())->toBeFalse()
        ->and(RatingVote::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});

it('lists every voter drawn on the Voters tab, those given no vote as not used', function () {
    $editor = postVotesEditor($this->plan)->set('voterCount', 4)->set('postOn', true)->set('up', 2)->set('tab', 'voters');

    $editor->assertSee('4 post voters · 2 vote or answer')->assertSee('The votes as saved.');

    $idle = $editor->set('votersFilter', 'idle')->viewData('voterTable')['rows'];
    $voting = $editor->set('votersFilter', 'voting')->viewData('voterTable')['rows'];

    expect($idle)->toHaveCount(2)->each(fn ($row) => $row->voting->toBeFalse())
        ->and($voting)->toHaveCount(2)->each(fn ($row) => $row->post->toBe('up'))
        ->and($editor->set('votersFilter', 'everything')->get('votersFilter'))->toBe('all');
});

it('takes the whole save back when a write of it is not saved, keeping the votes and the version as they were', function () {
    votePostUpTwiceDownOnce($this->plan);
    $before = savedPostVotes($this->plan);
    $editor = postVotesEditor($this->plan)->call('selectShown')->assertHasNoErrors();
    StudioPlanVote::creating(fn (): bool => false);

    expect(fn () => $editor->set('down', 2))->toThrow(RuntimeException::class);

    expect(savedPostVotes($this->plan))->toBe($before)
        ->and(StudioPlanVote::query()->whereKey($this->commentVote->id)->exists())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(3);
});
