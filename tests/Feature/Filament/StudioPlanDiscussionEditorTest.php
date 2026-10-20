<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\SavedStudioDiscussion;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The Discussion step, by hand: the comments and replies the post is to get
 * as a draft of their own — added, written, given a persona and a time,
 * moved, deleted — kept with Save discussion through the one action that
 * writes discussions, ids kept, every message put by the comment it is about,
 * and nothing lost or written over when somebody else changed the plan.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->plan = StudioPlan::factory()->create(['created_by_user_id' => $this->admin->id, 'title' => 'A quiet harbour']);
    [$this->anna, $this->boris] = [User::factory()->persona()->create(['display_name' => 'Anna']), User::factory()->persona()->create(['display_name' => 'Boris'])];
});

/** A discussion saved for the plan: comment a by Anna with replies a1 and a2, then comment b by Boris. */
function savedFour($test): SavedStudioDiscussion
{
    return savedStudioBranchFixture($test->admin, $test->plan, $test->anna, $test->boris);
}

/** Every comment of the plan as stored, by id: parent, persona, text, place and time. */
function storedNodes(StudioPlan $plan): array
{
    return DB::table('studio_plan_discussion_nodes')->where('studio_plan_id', $plan->id)->orderBy('id')->get()
        ->mapWithKeys(fn ($row): array => [(int) $row->id => [$row->parent_id === null ? null : (int) $row->parent_id, (int) $row->persona_user_id, $row->body, (int) $row->sort_order, (int) $row->offset_seconds]])
        ->all();
}

it('is for administrators who may manage plans only — drawn, or clicked', function () {
    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(StudioPlanDiscussionEditor::class, ['planId' => $this->plan->id])
        ->assertForbidden();
    Livewire::actingAs(User::factory()->create())
        ->test(StudioPlanDiscussionEditor::class, ['planId' => $this->plan->id])
        ->assertForbidden();

    $this->actingAs($this->admin);
    $editor = discussionEditor($this->plan);
    $this->admin->forceFill(['role' => 'user'])->save();

    $editor->call('addComment')->assertForbidden();
});

it('keeps the plan and its version where the browser cannot move them', function () {
    expect(fn () => discussionEditor($this->plan)->set('planId', 99))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => discussionEditor($this->plan)->set('lockVersion', 9))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => discussionEditor($this->plan)->set('savedNodes', []))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('draws the saved discussion: each comment on the post, then its replies, with its persona and time', function () {
    $saved = savedFour($this);
    $this->boris->forceFill(['status' => UserStatus::Banned])->save();

    $editor = discussionEditor($this->plan);

    expect(discussionEditorKeys($editor))->toBe(['n'.$saved->ids['a'], 'n'.$saved->ids['a1'], 'n'.$saved->ids['a2'], 'n'.$saved->ids['b']])
        ->and($editor->get('nodes')['n'.$saved->ids['a1']])->toBe(['id' => $saved->ids['a1'], 'parent' => 'n'.$saved->ids['a'], 'personaUserId' => $this->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 120]);
    $editor->assertSeeInOrder(['Lovely light.', 'Agreed.', 'Thank you.', 'Where is this?'])
        ->assertSee('Anna')
        ->assertSee('+1 min')
        ->assertSee('+2 min · 1 min after its comment')
        // A sanctioned persona stays on its comment, said so.
        ->assertSee('Banned')
        ->assertSet('lockVersion', 2);
});

it('saves an emptied discussion', function () {
    savedFour($this);

    $editor = discussionEditor($this->plan);

    foreach (discussionEditorKeys($editor) as $key) {
        if (array_key_exists($key, $editor->get('nodes')) && $editor->get('nodes')[$key]['parent'] === null) {
            $editor->call('delete', $key);
            $editor->get('deleting') !== null && $editor->call('confirmDelete');
        }
    }

    $editor->call('save')->assertHasNoErrors()->assertSet('nodes', [])->assertSet('lockVersion', 3)->assertDispatched('rg-admin-toast', message: 'Discussion saved');

    expect(StudioDiscussionNode::query()->exists())->toBeFalse();
});

it('adds a comment on the post, and a reply to a saved one', function () {
    $saved = savedFour($this);
    $editor = discussionEditor($this->plan)
        ->call('addComment')
        ->assertSet('editing', 'k1');

    $editor->call('choosePersona', 'k1', $this->boris->id)
        ->set('nodes.k1.body', '  A fine morning.  ')
        ->call('addReply', 'n'.$saved->ids['b'])
        ->call('choosePersona', 'k2', $this->anna->id)
        ->set('nodes.k2.body', 'Brittany, I think.')
        ->call('save')
        ->assertHasNoErrors();

    $added = StudioDiscussionNode::query()->where('body', 'A fine morning.')->sole();
    $reply = StudioDiscussionNode::query()->where('body', 'Brittany, I think.')->sole();

    expect($added)->parent_id->toBeNull()->persona_user_id->toBe($this->boris->id)->sort_order->toBe(2)
        ->and($reply)->parent_id->toBe($saved->ids['b'])->persona_user_id->toBe($this->anna->id)
        ->and($reply->offset_seconds)->toBe(90 + 300)
        // The keys gave way to the ids the save gave them.
        ->and(discussionEditorKeys($editor))->toContain('n'.$added->id, 'n'.$reply->id)->not->toContain('k1', 'k2');
});

it('saves a new comment and a reply to it in one save, the reply naming its comment by its key', function () {
    $editor = discussionEditor($this->plan)
        ->call('addComment')
        ->call('choosePersona', 'k1', $this->anna->id)
        ->set('nodes.k1.body', 'Lovely light.')
        ->call('addReply', 'k1')
        ->call('choosePersona', 'k2', $this->boris->id)
        ->set('nodes.k2.body', 'Agreed.')
        ->call('save')
        ->assertHasNoErrors();

    $root = StudioDiscussionNode::query()->whereNull('parent_id')->sole();

    expect(StudioDiscussionNode::query()->whereNotNull('parent_id')->sole()->parent_id)->toBe($root->id)
        ->and($editor->get('savedNodes'))->toBe($editor->get('nodes'));
});

it('keeps every id through a change of text, persona, place and time', function () {
    $saved = savedFour($this);
    [$a, $a1, $a2, $b] = ['n'.$saved->ids['a'], 'n'.$saved->ids['a1'], 'n'.$saved->ids['a2'], 'n'.$saved->ids['b']];

    discussionEditor($this->plan)
        ->set("nodes.{$a}.body", 'Such light.')
        ->call('choosePersona', $a1, $this->anna->id)
        ->set("nodes.{$a2}.offsetSeconds", 3600)
        ->call('moveUp', $b)
        ->call('moveDown', $a1)
        ->call('save')
        ->assertHasNoErrors();

    $expected = [
        $saved->ids['a'] => [null, $this->anna->id, 'Such light.', 1, 60],
        $saved->ids['a1'] => [$saved->ids['a'], $this->anna->id, 'Agreed.', 1, 120],
        $saved->ids['a2'] => [$saved->ids['a'], $this->anna->id, 'Thank you.', 0, 3600],
        $saved->ids['b'] => [null, $this->boris->id, 'Where is this?', 0, 90],
    ];
    ksort($expected);

    expect(storedNodes($this->plan))->toBe($expected);
});

it('moves a comment only among its siblings, never to another comment', function () {
    $saved = savedFour($this);
    [$a, $a1, $a2, $b] = ['n'.$saved->ids['a'], 'n'.$saved->ids['a1'], 'n'.$saved->ids['a2'], 'n'.$saved->ids['b']];

    $editor = discussionEditor($this->plan)
        ->call('moveUp', $a1)      // already first among a's replies
        ->call('moveDown', $a2)    // already last
        ->call('moveDown', $a);

    expect(discussionEditorKeys($editor))->toBe([$b, $a, $a1, $a2])
        ->and($editor->get('nodes')[$a1]['parent'])->toBe($a);
});

it('deletes a reply alone, and a comment with its replies only once confirmed — in the draft until saved', function () {
    $saved = savedFour($this);
    [$a, $a1, $a2, $b] = ['n'.$saved->ids['a'], 'n'.$saved->ids['a1'], 'n'.$saved->ids['a2'], 'n'.$saved->ids['b']];

    $editor = discussionEditor($this->plan)->call('delete', $a1);

    expect(discussionEditorKeys($editor))->toBe([$a, $a2, $b]);

    $editor->call('delete', $a)
        ->assertSet('deleting', $a)
        ->assertSee('Delete this comment and its 1 reply?')
        ->assertSee('Replies can’t stay without their parent, so the whole branch leaves the plan.');

    expect(discussionEditorKeys($editor))->toBe([$a, $a2, $b]);

    $editor->call('confirmDelete')->assertSet('deleting', null);

    expect(discussionEditorKeys($editor))->toBe([$b])
        // Nothing left the database yet.
        ->and(StudioDiscussionNode::query()->count())->toBe(4);

    $editor->call('save');

    expect(StudioDiscussionNode::query()->pluck('id')->all())->toBe([$saved->ids['b']]);
});

it('offers no reply to a reply, and refuses a third level the browser makes up', function () {
    $saved = savedFour($this);
    $a1 = 'n'.$saved->ids['a1'];

    $editor = discussionEditor($this->plan)->call('addReply', $a1);

    expect(discussionEditorKeys($editor))->not->toContain('k1');

    // A reply's parent forced to another reply in the browser: the action's rules refuse it.
    $editor->call('addComment')
        ->call('choosePersona', 'k1', $this->anna->id)
        ->set('nodes.k1.body', 'Me too.')
        ->set('nodes.k1.parent', $a1)
        ->call('save')
        ->assertHasErrors(['nodes.k1.parentId'])
        ->assertSet('editing', 'k1');

    expect(StudioDiscussionNode::query()->count())->toBe(4);
});

it('holds forty comments and replies at most, and says so', function () {
    $editor = discussionEditor($this->plan);

    foreach (range(1, 40) as $index) {
        $editor->call('addComment');
    }

    $editor->call('addComment')
        ->assertDispatched('rg-admin-toast', message: 'A discussion holds up to 40 comments and replies. Delete one to add another.')
        ->assertSee('A discussion holds up to 40 comments and replies.');

    expect(count($editor->get('nodes')))->toBe(40);
});

it('puts each refused field by its comment and keeps the draft as typed', function (Closure $arrange, string $field, string $message) {
    $editor = discussionEditor($this->plan)
        ->call('addComment')
        ->call('choosePersona', 'k1', $this->anna->id)
        ->set('nodes.k1.body', 'Lovely light.');
    $arrange($editor, $this);

    $before = $editor->get('nodes');

    $editor->call('save')
        ->assertHasErrors(["nodes.k1.{$field}"])
        ->assertSet('editing', 'k1')
        ->assertSee($message)
        ->assertDispatched('rg-admin-toast', message: 'The discussion wasn’t saved. Fix what is marked and save again.');

    expect($editor->get('nodes'))->toBe($before)
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
})->with([
    'no text' => [fn ($editor) => $editor->set('nodes.k1.body', '   '), 'body', 'Write the comment.'],
    'a text too long' => [fn ($editor) => $editor->set('nodes.k1.body', str_repeat('a', 1001)), 'body', 'Keep the comment to 1,000 characters or fewer.'],
    'no persona' => [fn ($editor) => $editor->set('nodes.k1.personaUserId', null), 'personaUserId', 'Choose a persona who is not deleted.'],
    'a time past thirty days' => [fn ($editor) => $editor->set('nodes.k1.offsetSeconds', 2_592_001), 'offsetSeconds', 'Set when it comes: from the post’s own moment to 30 days after it.'],
    'a time before the post' => [fn ($editor) => $editor->set('nodes.k1.offsetSeconds', -5), 'offsetSeconds', 'Set when it comes'],
]);

it('refuses a reply before its comment, by the reply', function () {
    discussionEditor($this->plan)
        ->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'Lovely.')->set('nodes.k1.offsetSeconds', 600)
        ->call('addReply', 'k1')->call('choosePersona', 'k2', $this->boris->id)->set('nodes.k2.body', 'Agreed.')->set('nodes.k2.offsetSeconds', 300)
        ->call('save')
        ->assertHasErrors(['nodes.k2.offsetSeconds'])
        ->assertSee('A reply cannot come before the comment it answers.');

    expect(StudioDiscussionNode::query()->exists())->toBeFalse();
});

it('keeps a dated post\'s discussion inside the dates a post may carry, showing when each comment comes', function () {
    $this->plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2038-01-18 23:00:00')])->save();

    $editor = discussionEditor($this->plan)
        ->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'Just in time.')->set('nodes.k1.offsetSeconds', 3599)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('18 Jan 2038, 23:59:59');

    $key = discussionEditorKeys($editor)[0];
    $editor->set("nodes.{$key}.offsetSeconds", 3600)->call('save')->assertHasErrors(["nodes.{$key}.offsetSeconds"]);
});

it('refuses a save drawn from a version somebody else replaced, keeping the draft and asking for Reload', function () {
    savedFour($this);
    $editor = discussionEditor($this->plan)->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'Mine, unsaved.');
    $theirs = StudioPlan::query()->findOrFail($this->plan->id);
    app(UpdateStudioPlanAction::class)->handle($this->admin, $theirs, new StudioPlanData(title: 'Theirs'), 2);
    $draft = $editor->get('nodes');

    $editor->call('save')
        ->assertSet('conflict', true)
        ->assertSee('Someone changed this draft after you opened it')
        ->assertSee('Reload discussion');

    expect($editor->get('nodes'))->toBe($draft)
        ->and(StudioDiscussionNode::query()->where('body', 'Mine, unsaved.')->exists())->toBeFalse();

    // Reload draws what is saved, the typed comment gone.
    $editor->call('reload')->assertSet('conflict', false)->assertSet('lockVersion', 3);

    expect(collect($editor->get('nodes'))->pluck('body')->all())->not->toContain('Mine, unsaved.');
});

it('refuses a persona deleted meanwhile, by its comment', function () {
    $editor = discussionEditor($this->plan)->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'Lovely.');
    $this->anna->forceFill(['status' => UserStatus::Deleted])->save();

    $editor->call('save')->assertHasErrors(['nodes.k1.personaUserId']);

    expect(StudioDiscussionNode::query()->exists())->toBeFalse();
    discussionEditor($this->plan)->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->assertHasErrors(['nodes.k1.personaUserId']);
});

it('says when the plan was deleted or approved, keeping what was typed', function (Closure $change, string $notice, string $flag) {
    $editor = discussionEditor($this->plan)->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'Typed.');
    $change($this->plan);

    $editor->call('save')->assertSet($flag, true)->assertSee($notice);

    expect($editor->get('nodes')['k1']['body'])->toBe('Typed.');
})->with([
    'deleted' => [fn (StudioPlan $plan) => app(DeleteStudioPlanDraftAction::class)->handle(User::query()->findOrFail($plan->created_by_user_id), $plan, 1), 'This draft was deleted', 'gone'],
    'approved' => [fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), 'This plan is no longer a draft', 'closed'],
]);

it('discards unsaved changes back to the saved discussion, reading nothing anew', function () {
    $saved = savedFour($this);
    $editor = discussionEditor($this->plan)->set('nodes.n'.$saved->ids['a'].'.body', 'Changed.')->call('addComment');

    $editor->call('discardChanges');

    expect($editor->get('nodes'))->toBe($editor->get('savedNodes'));
});

it('writes nothing but the discussion: no post, comment, action or job', function () {
    discussionEditor($this->plan)
        ->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'Lovely.')
        ->call('save')->assertHasNoErrors();

    expect(Post::query()->exists())->toBeFalse()
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse()
        ->and(DB::table('post_votes')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('says the plan\'s new version to the editor around it after a save', function () {
    discussionEditor($this->plan)
        ->call('addComment')->call('choosePersona', 'k1', $this->anna->id)->set('nodes.k1.body', 'Lovely.')
        ->call('save')
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'discussion');
});

it('takes a version this page\'s other editor moved, and no other', function () {
    $editor = discussionEditor($this->plan);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 1, current: 2, source: 'editor')
        ->assertSet('lockVersion', 2)->assertSet('conflict', false);
    // Its own word, or another plan's, changes nothing.
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 2, current: 3, source: 'discussion')
        ->assertSet('lockVersion', 2);
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id + 1, previous: 2, current: 3, source: 'editor')
        ->assertSet('lockVersion', 2);
    // A jump from a version it never held is somebody else's.
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 5, current: 6, source: 'editor')
        ->assertSet('lockVersion', 2)->assertSet('conflict', true);
});
