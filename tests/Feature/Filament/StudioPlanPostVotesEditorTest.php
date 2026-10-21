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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Post step's Votes tab: how many vote the post up and down, how many
 * answer each rating question and with what, by which Personas and when —
 * made into a preview drawn once and saved exactly as shown, the
 * discussion's votes kept, and every write sharing the plan's one version
 * with the other editors on the page.
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

/** The editor's preview, as savedPostVotes() lists votes. */
function previewedPostVotes(Testable $editor): array
{
    return array_map(fn (array $vote): array => [$vote['personaUserId'], $vote['type'], $vote['voteType'], $vote['ratingOptionId'], $vote['offsetSeconds'], $vote['sortOrder']], $editor->get('preview') ?? []);
}

/** Three Personas drawn at random voting the post up twice and down once. */
function previewPostVotes(StudioPlan $plan): Testable
{
    return postVotesEditor($plan)->set('postOn', true)->set('up', 2)->set('down', 1)->set('voterCount', 3)->call('build', false)->assertHasNoErrors();
}

it('is for administrators who may manage plans only — drawn, or clicked', function () {
    Livewire::actingAs(User::factory()->moderator()->create())->test(StudioPlanPostVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test(StudioPlanPostVotesEditor::class, ['planId' => $this->plan->id])->assertForbidden();

    $this->actingAs($this->admin);
    $editor = postVotesEditor($this->plan);
    $this->admin->forceFill(['role' => 'moderator'])->save();

    $editor->call('build', false)->assertForbidden();
});

it('starts a plan with no votes from a random draw, its author out of it, and the active rating questions with their answers', function () {
    postVotesEditor($this->plan)
        ->assertSet('voterMode', 'random')->assertSet('voterCount', 4)->assertSet('postOn', false)
        ->assertSee('No votes prepared · the post gets only real users’ votes.')
        ->assertSee('Author Ada')->assertSee('Can’t vote on own post')
        ->assertSee('of 4 personas who can vote')
        ->assertSee($this->group->translatedLabel())
        ->assertSee('No votes are saved on the post or its questions.')
        // A question's answers show once it is to get some.
        ->call('toggleRating', $this->group->id)
        ->assertSee($this->answers[0]->translatedLabel())->assertSee($this->answers[1]->translatedLabel());
});

it('shows a preview, then saves exactly it — the same Personas, ways and moments — keeping the discussion\'s votes', function () {
    $editor = previewPostVotes($this->plan);
    $preview = previewedPostVotes($editor);

    expect($preview)->toHaveCount(3)
        ->and(array_column($preview, 0))->not->toContain($this->author->id)
        ->and(array_unique(array_column($preview, 0)))->toHaveCount(3)
        ->and(array_count_values(array_column($preview, 2)))->toEqualCanonicalizing(['up' => 2, 'down' => 1]);

    $editor->assertSee('3 votes in the preview, not saved yet.')->assertSee('Not saved yet · 3 votes as Save votes keeps them');
    expect(savedPostVotes($this->plan))->toBe([]);

    $editor->call('save', false)->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Votes saved', tone: 'success')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: StudioPlanPostVotesEditor::SOURCE)
        ->assertSet('preview', null)->assertSet('lockVersion', 2)
        ->assertSee('3 votes are saved on the post and its questions.');

    expect(savedPostVotes($this->plan))->toBe($preview)
        ->and(StudioPlanVote::query()->whereKey($this->commentVote->id)->exists())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('draws again only when asked: Rebuild keeps the draw, Pick others makes another', function () {
    $editor = previewPostVotes($this->plan);
    $first = previewedPostVotes($editor);

    $editor->call('build', false);
    expect(previewedPostVotes($editor))->toBe($first);

    $draws = [];

    foreach (range(1, 8) as $ignored) {
        $draws[] = json_encode(previewedPostVotes($editor->call('redraw', false)));
    }

    expect(array_unique($draws))->not->toHaveCount(1);
});

it('draws the votes again from what is saved when opened again: counts, Personas, answers and times', function () {
    $editor = postVotesEditor($this->plan)->set('postOn', true)->set('up', 2)->set('down', 1)->set('voterCount', 4)
        ->call('toggleRating', $this->group->id)->call('setRatingTotal', $this->group->id, 3)
        ->call('setTiming', 'post', 'first', 1800)->call('setTiming', 'post', 'spread', 21600)
        ->call('build', false)->call('save', false)->assertHasNoErrors();
    $saved = savedPostVotes($this->plan);
    $voters = array_values(array_unique(array_column($saved, 0)));
    $ratingCounts = StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->pluck('rating_option_id')->countBy()->all();

    postVotesEditor($this->plan)
        ->assertSet('postOn', true)->assertSet('up', 2)->assertSet('down', 1)
        ->assertSet('postFirst', 1800)->assertSet('postSpread', 21600)
        ->assertSet('voterMode', 'manual')->assertSet('voterIds', $voters)
        ->assertSet('ratings.'.$this->group->id.'.on', true)
        ->assertSet('ratings.'.$this->group->id.'.counts', [$this->answers[0]->id => $ratingCounts[$this->answers[0]->id] ?? 0, $this->answers[1]->id => $ratingCounts[$this->answers[1]->id] ?? 0])
        ->assertSet('preview', null)
        ->assertSee('6 votes are saved on the post and its questions.');

    expect(array_sum($ratingCounts))->toBe(3);
});

it('saves only a preview made from the settings as they are now', function () {
    $editor = previewPostVotes($this->plan)->set('up', 3);

    $editor->assertSee('The settings changed since this preview. Rebuild it to save.')
        ->call('save', false)->assertHasErrors(['preview']);

    expect(savedPostVotes($this->plan))->toBe([])->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('says exactly how many Personas are short, and builds nothing — cutting nothing to fit', function () {
    postVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->boris->id)
        ->set('postOn', true)->set('up', 2)->set('down', 1)
        ->assertSee('These votes need 3 personas; 2 are chosen — 1 short.')
        ->call('build', false)
        ->assertHasErrors(['post' => 'Up and down votes need 3 personas who can vote; 2 are chosen — 1 short. Choose more personas, or ask for fewer.'])
        ->assertSet('preview', null)->assertSet('up', 2)->assertSet('down', 1);
});

it('never chooses the post\'s author, and says why it is listed', function () {
    postVotesEditor($this->plan)->set('voterMode', 'manual')
        ->call('toggleVoter', $this->author->id)->assertSet('voterIds', [])
        ->assertSee('Author of this post · can’t vote on it')
        ->call('selectShown')->assertSet('voterIds', fn (array $ids): bool => ! in_array($this->author->id, $ids, true) && count($ids) === 4);
});

it('splits the answers evenly, the rest to the first options, and moves one at a time keeping the total', function () {
    [$group, $answers] = votableRatingQuestion(3);
    // The options in the order the rating question lists them.
    collect($answers)->each(fn ($option, int $place) => $option->update(['sort_order' => $place]));
    $ids = array_map(fn ($option): int => $option->id, $answers);
    $editor = postVotesEditor($this->plan)->call('toggleRating', $group->id)->call('setRatingTotal', $group->id, 5)->call('evenSplit', $group->id);

    $editor->assertSet("ratings.{$group->id}.counts", [$ids[0] => 2, $ids[1] => 2, $ids[2] => 1]);

    $editor->call('moveAnswer', $group->id, $ids[2], 'up')->assertSet("ratings.{$group->id}.counts", [$ids[0] => 1, $ids[1] => 2, $ids[2] => 2])
        ->call('moveAnswer', $group->id, $ids[0], 'down')->assertSet("ratings.{$group->id}.counts", [$ids[0] => 0, $ids[1] => 3, $ids[2] => 2])
        ->call('setRatingTotal', $group->id, 10)->assertSet("ratings.{$group->id}.counts", fn (array $counts): bool => array_sum($counts) === 10 && $counts[$ids[0]] === 0);
});

it('keeps each vote\'s id when a save changes only its moment', function () {
    previewPostVotes($this->plan)->call('save', false);
    $ids = StudioPlanVote::query()->where('type', StudioActionType::VotePost)->orderBy('id')->pluck('id')->all();

    postVotesEditor($this->plan)->call('setTiming', 'post', 'first', 3600)->call('build', false)->call('save', false)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->min('offset_seconds'))->toBe(3600);
});

it('saves an empty set, taking the post\'s votes away and keeping the discussion\'s', function () {
    previewPostVotes($this->plan)->call('save', false);

    postVotesEditor($this->plan)->set('postOn', false)->call('build', false)->assertSet('preview', [])
        ->call('save', false)->assertHasNoErrors()->assertSet('lockVersion', 3);

    expect(savedPostVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->commentVote->id]);
});

it('removes the post votes once asked, the discussion\'s votes staying', function () {
    previewPostVotes($this->plan)->call('save', false);

    postVotesEditor($this->plan)->call('askRemove')->assertSet('confirmingRemove', true)->assertSee('Remove the post votes?')
        ->call('confirmRemove')->assertDispatched('rg-admin-toast', message: 'Post votes removed', tone: 'success');

    expect(savedPostVotes($this->plan))->toBe([])
        ->and(StudioPlanVote::query()->pluck('id')->all())->toBe([$this->commentVote->id]);
});

it('saves once for Save pressed twice', function () {
    $editor = previewPostVotes($this->plan)->call('save', false);
    $editor->call('save', false)->assertHasErrors(['preview']);

    expect(StudioPlanVote::query()->where('type', StudioActionType::VotePost)->count())->toBe(3)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('builds and saves nothing while the post\'s author has unsaved changes', function () {
    $editor = postVotesEditor($this->plan)->set('postOn', true)->set('up', 1)
        ->call('build', true)->assertHasErrors(['author'])->assertSet('preview', null);

    $editor->call('build', false)->assertHasNoErrors()->call('save', true)->assertHasErrors(['author']);

    expect(savedPostVotes($this->plan))->toBe([]);
});

it('keeps a Persona restricted or deleted since in the saved votes, saying so and what keeps the vote from being cast', function () {
    postVotesEditor($this->plan)->set('voterMode', 'manual')->call('toggleVoter', $this->anna->id)->call('toggleVoter', $this->carl->id)
        ->set('postOn', true)->set('up', 2)->call('build', false)->call('save', false);
    $this->anna->forceFill(['status' => UserStatus::Banned])->save();
    $this->carl->forceFill(['status' => UserStatus::Deleted])->save();

    postVotesEditor($this->plan)
        ->assertSet('voterIds', fn (array $ids): bool => in_array($this->anna->id, $ids, true) && in_array($this->carl->id, $ids, true))
        ->assertSee('Banned')->assertSee('Deleted')
        ->assertSee('This persona is limited, banned or shadowbanned and cannot vote. Choose another one.')
        ->assertSee('A deleted or restricted persona, or the post’s author, gets no new votes.')
        ->assertSee('2 votes are saved on the post and its questions.');
});

it('shows an answer no longer open as such, keeps it as it was, and replaces it only when asked', function () {
    postVotesEditor($this->plan)->call('toggleRating', $this->group->id)->call('setRatingTotal', $this->group->id, 2)
        ->call('moveAnswer', $this->group->id, $this->answers[1]->id, 'down')
        ->call('build', false)->call('save', false)->assertHasNoErrors();
    $this->answers[0]->update(['is_active' => false, 'archived_at' => now()]);

    $editor = postVotesEditor($this->plan)->assertSee('Not open')->assertSee('An option no longer open keeps the answers it had');
    $editor->call('build', false)->assertHasNoErrors()->call('save', false)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->pluck('rating_option_id')->unique()->all())->toBe([$this->answers[0]->id]);

    postVotesEditor($this->plan)->call('evenSplit', $this->group->id)->call('build', false)->call('save', false)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteRating)->pluck('rating_option_id')->unique()->values()->all())->toBe([$this->answers[1]->id]);
});

it('takes the version another editor on the page moved, its preview kept, and no version written elsewhere', function (string $source) {
    $editor = previewPostVotes($this->plan);
    $preview = $editor->get('preview');
    $this->plan->forceFill(['lock_version' => 2, 'title' => 'Renamed'])->save();

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: $source)
        ->assertSet('lockVersion', 2)->assertSet('conflict', false)->assertSet('preview', $preview);

    $editor->call('save', false)->assertHasNoErrors()->assertSet('lockVersion', 3);
})->with(['the post\'s editor' => ['editor'], 'the discussion\'s' => ['discussion']]);

it('takes no version somebody else wrote: nothing is saved until Reload', function () {
    $editor = previewPostVotes($this->plan);
    // Another administrator's votes, saved meanwhile.
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'down', 'offsetSeconds' => 60])]);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion')
        ->assertSet('conflict', true)->assertSee('Someone changed this draft after you opened it')
        ->call('save', false);

    expect(savedPostVotes($this->plan))->toHaveCount(1);

    $editor->call('reload')->assertSet('conflict', false)->assertSet('lockVersion', 2)->assertSet('down', 1)
        ->assertDispatched(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 2, source: StudioPlanPostVotesEditor::SOURCE);
});

it('refuses a save drawn from a version somebody else replaced, writing nothing over theirs', function () {
    $editor = previewPostVotes($this->plan);
    saveStudioVotes(User::factory()->admin()->create(), $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->dana->id, 'voteType' => 'down', 'offsetSeconds' => 60])]);

    $editor->call('save', false)->assertSet('conflict', true)
        ->assertDispatched('rg-admin-toast', message: 'Someone changed this draft meanwhile, so nothing was saved. Reload to see their changes.', tone: 'error');

    expect(savedPostVotes($this->plan))->toBe([[$this->dana->id, 'vote_post', 'down', null, 60, 0]]);
});

it('never puts back the votes of a comment the discussion deleted meanwhile', function () {
    $editor = previewPostVotes($this->plan);

    saveStudioDiscussion($this->admin, $this->plan, []);
    expect(StudioPlanVote::query()->whereKey($this->commentVote->id)->exists())->toBeFalse();

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion')
        ->assertSet('lockVersion', 2)->call('save', false)->assertHasNoErrors();

    expect(StudioPlanVote::query()->where('type', StudioActionType::VoteComment)->exists())->toBeFalse()
        ->and(savedPostVotes($this->plan))->toHaveCount(3);
});

it('changes nothing of a plan past its Draft, or deleted', function () {
    $this->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

    postVotesEditor($this->plan)->assertSee('This plan is no longer a draft, so its votes can’t be changed here.')
        ->set('postOn', true)->set('up', 1)->call('build', false)->assertSet('preview', null);

    $draft = StudioPlan::factory()->authoredBy($this->author)->create(['created_by_user_id' => $this->admin->id]);
    $editor = postVotesEditor($draft);
    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $draft, 1);

    $editor->set('postOn', true)->set('up', 1)->call('build', false)->assertSet('gone', true)
        ->assertDispatched('rg-admin-toast', message: 'This draft was deleted, so its votes can’t be changed any more.', tone: 'error');
});

it('casts nothing: no vote, action, job or notification', function () {
    Bus::fake();
    Notification::fake();

    previewPostVotes($this->plan)->call('toggleRating', $this->group->id)->call('setRatingTotal', $this->group->id, 2)->call('build', false)->call('save', false)->assertHasNoErrors();

    expect(PostVote::query()->exists())->toBeFalse()
        ->and(CommentVote::query()->exists())->toBeFalse()
        ->and(RatingVote::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});

it('lists every voter the preview drew on the Voters tab, those given no vote as not used', function () {
    $editor = postVotesEditor($this->plan)->set('postOn', true)->set('up', 2)->set('voterCount', 4)->call('build', false)
        ->set('tab', 'voters');

    $editor->assertSee('4 post voters · 2 vote or answer')->assertSee('The preview, not saved yet.');

    $idle = $editor->set('votersFilter', 'idle')->viewData('voterTable')['rows'];
    $voting = $editor->set('votersFilter', 'voting')->viewData('voterTable')['rows'];

    expect($idle)->toHaveCount(2)->each(fn ($row) => $row->voting->toBeFalse())
        ->and($voting)->toHaveCount(2)->each(fn ($row) => $row->post->toBe('up'))
        ->and($editor->set('votersFilter', 'everything')->get('votersFilter'))->toBe('all');
});

it('takes the whole save back when a write of it is not saved, keeping the votes and the version as they were', function () {
    previewPostVotes($this->plan)->call('save', false);
    $before = savedPostVotes($this->plan);
    $editor = postVotesEditor($this->plan)->set('voterMode', 'random')->set('voterCount', 4)->set('down', 2)->call('build', false)->assertHasNoErrors();
    StudioPlanVote::creating(fn (): bool => false);

    expect(fn () => $editor->call('save', false))->toThrow(RuntimeException::class);

    expect(savedPostVotes($this->plan))->toBe($before)
        ->and(StudioPlanVote::query()->whereKey($this->commentVote->id)->exists())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});
