<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\SavedStudioDiscussion;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Livewire\Admin\StudioPlanDiscussionVotesEditor;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The Discussion step, by hand, as the prototype's works: the discussion tree
 * as saved, each comment by its time and labelled C1, C2, …; a comment
 * written, answered or edited in a dialog and saved to the plan at once
 * through the one action that writes discussions, ids kept; Delete asked
 * first and undone from its toast; Timing changed in place — and nothing
 * written over when somebody else changed the plan.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->plan = StudioPlan::factory()->create(['created_by_user_id' => $this->admin->id, 'title' => 'A quiet harbour']);
    [$this->anna, $this->boris] = [User::factory()->persona()->create(['display_name' => 'Anna']), User::factory()->persona()->create(['display_name' => 'Boris'])];
});

/** A discussion saved for the plan: comment a by Anna (C1) with replies a1 (C2) and a2 (C3), then comment b by Boris (C4). */
function savedFour($test): SavedStudioDiscussion
{
    return savedStudioBranchFixture($test->admin, $test->plan, $test->anna, $test->boris);
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
    $this->admin->forceFill(['role' => 'moderator'])->save();

    $editor->call('openNew')->assertForbidden();
});

it('keeps the plan, its version and what it acts on where the browser cannot move them', function (string $property, mixed $value) {
    discussionEditor($this->plan)->set($property, $value);
})->throws(CannotUpdateLockedPropertyException::class)->with([
    'the plan' => ['planId', 99],
    'the version' => ['lockVersion', 9],
    'the dialog' => ['dialog', ['mode' => 'edit', 'id' => 1, 'parentId' => null]],
    'who writes in it' => ['dialogPersona', 1],
    'the personas checked' => ['manual', [1]],
    'the generation awaited' => ['awaiting', 1],
    'what Undo puts back' => ['undo', ['token' => 'x', 'rows' => []]],
    'the discussion it holds' => ['treeFingerprint', 'x'],
]);

it('draws the tree as saved: each comment on the post by its time, its replies under it, labelled in order', function () {
    savedFour($this);
    $this->boris->forceFill(['status' => UserStatus::Banned])->save();

    discussionEditor($this->plan)
        ->assertSee('Discussion tree')
        ->assertSee('4 comments · 2 top-level')
        ->assertSeeInOrder(['Lovely light.', 'Agreed.', 'Thank you.', 'Where is this?'])
        ->assertSeeHtml('data-node="C1"')->assertSeeHtml('data-node="C4"')
        ->assertSee('↳ C1 · Anna')
        ->assertSee('+1 min')->assertSee('+3 min')
        ->assertSee('Automated')
        ->assertSee('Banned')
        ->assertSet('lockVersion', 2);
});

it('shows on each comment, beside its moment, the up and down votes saved on it, drawn again when the comment votes editor saves', function () {
    savedFour($this);
    [$carl, $dana] = User::factory()->persona()->count(2)->create()->all();
    $nodes = StudioDiscussionNode::query()->where('studio_plan_id', $this->plan->id)->pluck('id', 'body');
    $votes = fn (string $html, string $label): array => preg_match('/title="Up votes prepared on '.$label.'">.*?<\/svg>\s*<span x-text="[^"]*">(\d+)<\/span>.*?title="Down votes prepared on '.$label.'">.*?<\/svg>\s*<span x-text="[^"]*">(\d+)<\/span>/s', $html, $match) === 1 ? [(int) $match[1], (int) $match[2]] : [];
    $editor = discussionEditor($this->plan);

    expect($votes($editor->html(), 'C1'))->toBe([0, 0])->and($votes($editor->html(), 'C4'))->toBe([0, 0]);

    saveStudioVotes($this->admin, $this->plan, [
        studioVote(['type' => 'vote_comment', 'personaUserId' => $carl->id, 'voteType' => 'up', 'discussionNodeId' => $nodes['Lovely light.'], 'offsetSeconds' => 600]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $dana->id, 'voteType' => 'up', 'discussionNodeId' => $nodes['Lovely light.'], 'offsetSeconds' => 900]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $carl->id, 'voteType' => 'down', 'discussionNodeId' => $nodes['Where is this?'], 'offsetSeconds' => 600]),
    ]);
    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 2, current: 3, source: StudioPlanDiscussionVotesEditor::SOURCE)
        ->assertSet('lockVersion', 3)->assertSet('conflict', false);

    expect($votes($editor->html(), 'C1'))->toBe([2, 0])
        ->and($votes($editor->html(), 'C2'))->toBe([0, 0])
        ->and($votes($editor->html(), 'C4'))->toBe([0, 1]);
    $editor->assertSeeHtmlInOrder(['data-node="C1"', '+1 min', 'aria-label="Up votes"', 'aria-label="Down votes"', 'Lovely light.'])
        ->assertSeeHtmlInOrder(['Generate from the settings above', '>3 comment votes (+2 / −1)</span>']);
});

it('draws each comment\'s votes as the comment votes editor sets them, opens its breakdown from See breakdown, and keeps the places that editor draws in', function () {
    savedFour($this);
    $c1 = StudioDiscussionNode::query()->where('studio_plan_id', $this->plan->id)->where('body', 'Lovely light.')->value('id');

    discussionEditor($this->plan)
        ->assertSeeHtmlInOrder([
            'data-node="C1"',
            'x-bind:class="{ \'rg-admin-studio__node--selected\': $store.rgCommentVotes?.selected === '.$c1.' }"',
            'x-text="$store.rgCommentVotes?.nodes?.['.$c1.']?.up ?? 0"',
            'x-text="$store.rgCommentVotes?.nodes?.['.$c1.']?.down ?? 0"',
            'x-show="$store.rgCommentVotes?.nodes?.['.$c1.']?.changed" x-cloak>Not saved</span>',
            'aria-controls="rg-admin-studio-comment-breakdown"',
            'x-on:click="$dispatch(\'studio-comment-votes-select\', { node: '.$c1.' })"',
            'See breakdown',
        ])
        ->assertSeeHtml('<div id="rg-admin-studio-comment-breakdown" class="rg-admin-studio__breakdown-slot" wire:ignore></div>')
        ->assertSeeHtmlInOrder([
            'Generate from the settings above',
            'x-text="$store.rgCommentVotes?.status || \'no comment votes\'">no comment votes</span>',
            'x-show="$store.rgCommentVotes?.unsaved" x-cloak>Not saved</span>',
        ])
        ->assertSeeHtml('class="rg-admin-studio__tree-voters"  hidden >')->assertSeeHtml('class="rg-admin-studio__tree-timing"  hidden >')
        ->set('tab', 'voters')->assertSet('tab', 'voters')->assertSeeHtml('class="rg-admin-studio__tree-voters" >')->assertDontSee('Lovely light.')
        ->set('tab', 'timing')->assertSeeHtmlInOrder(['class="rg-admin-studio__tree-timing" >', 'id="rg-admin-studio-timing"'])
        ->set('tab', 'elsewhere')->assertSet('tab', 'tree');

    // Read-only, a comment still opens its breakdown.
    $this->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();
    discussionEditor($this->plan)->assertDontSeeHtml('wire:click="openEdit(')->assertSee('See breakdown');
});

it('says there is no discussion yet, or none planned', function () {
    discussionEditor($this->plan)->assertSee('No discussion yet')->assertSee('Set the settings above and generate the tree.')
        ->set('totalCount', 0)->assertSee('No discussion planned')->assertSee('The post publishes without automated comments.');
});

it('writes a new comment on the post from its dialog at once, twenty minutes after the last', function () {
    savedFour($this);

    $editor = discussionEditor($this->plan)->call('openNew')
        ->assertSee('New top-level comment')
        ->assertSet('dialogSeconds', 90 + 20 * 60);

    $editor->call('choosePersona', $this->boris->id)->set('dialogBody', '  Fresh view.  ')->call('saveDialog')
        ->assertHasNoErrors()
        ->assertSet('dialog', null)
        ->assertSet('lockVersion', 3)
        ->assertDispatched(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 2, current: 3, source: 'discussion');

    $added = StudioDiscussionNode::query()->where('body', 'Fresh view.')->sole();

    expect($added)->parent_id->toBeNull()->persona_user_id->toBe($this->boris->id)->offset_seconds->toBe(1290);
});

it('answers a comment on the post ten minutes after it, by somebody else, and offers no reply to a reply', function () {
    $saved = savedFour($this);

    $editor = discussionEditor($this->plan)->call('openReply', $saved->ids['a'])
        ->assertSee('Reply to C1')
        ->assertSet('dialogPersona', fn ($persona) => $persona !== $this->anna->id)
        ->assertSet('dialogSeconds', 60 + 600);

    $editor->call('choosePersona', $this->boris->id)->set('dialogBody', 'Seconded.')->call('saveDialog')->assertHasNoErrors();

    expect(StudioDiscussionNode::query()->where('body', 'Seconded.')->sole()->parent_id)->toBe($saved->ids['a']);

    $editor->call('openReply', $saved->ids['a1'])->assertSet('dialog', null)
        ->assertSeeHtml('wire:click="openReply('.$saved->ids['a'].')"')
        ->assertDontSeeHtml('wire:click="openReply('.$saved->ids['a1'].')"');
});

it('edits a comment in its dialog, keeping every id, its place among its siblings following its time', function () {
    $saved = savedFour($this);

    discussionEditor($this->plan)->call('openEdit', $saved->ids['a2'])
        ->assertSee('Edit C3')
        ->assertSet('dialogBody', 'Thank you.')
        ->call('choosePersona', $this->boris->id)
        ->set('dialogBody', 'Thanks a lot.')
        ->set('dialogSeconds', 100)
        ->call('saveDialog')
        ->assertHasNoErrors();

    $expected = [
        $saved->ids['a'] => [null, $this->anna->id, 'Lovely light.', 0, 60],
        $saved->ids['a1'] => [$saved->ids['a'], $this->boris->id, 'Agreed.', 1, 120],
        $saved->ids['a2'] => [$saved->ids['a'], $this->boris->id, 'Thanks a lot.', 0, 100],
        $saved->ids['b'] => [null, $this->boris->id, 'Where is this?', 1, 90],
    ];
    ksort($expected);

    expect(storedStudioDiscussionNodes($this->plan))->toBe($expected);
});

it('moves a reply to another comment, and keeps a comment with replies on the post', function () {
    $saved = savedFour($this);

    discussionEditor($this->plan)->call('openEdit', $saved->ids['a2'])
        ->call('chooseParent', $saved->ids['b'])
        ->assertSet('dialog.parentId', $saved->ids['b'])
        ->call('saveDialog')
        ->assertHasNoErrors();

    expect(StudioDiscussionNode::query()->findOrFail($saved->ids['a2'])->parent_id)->toBe($saved->ids['b']);

    discussionEditor($this->plan)->call('openEdit', $saved->ids['a'])
        ->assertSee('It has replies, so it stays a top-level comment.')
        ->call('chooseParent', $saved->ids['b'])
        ->assertSet('dialog.parentId', null)
        // Never a reply's reply.
        ->call('openEdit', $saved->ids['a1'])->call('chooseParent', $saved->ids['a2'])
        ->assertSet('dialog.parentId', $saved->ids['a']);
});

it('keeps the dialog open with each refusal by its field, writing nothing', function (Closure $arrange, string $field, string $message) {
    savedFour($this);
    $editor = discussionEditor($this->plan)->call('openNew')->call('choosePersona', $this->anna->id)->set('dialogBody', 'Lovely light.');
    $arrange($editor, $this);

    $editor->call('saveDialog')->assertHasErrors([$field])->assertSee($message)->assertSet('dialog.mode', 'new');

    expect(StudioDiscussionNode::query()->count())->toBe(4)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
})->with([
    'no text' => [fn ($editor) => $editor->set('dialogBody', '   '), 'dialogBody', 'Write the comment first.'],
    'a text too long' => [fn ($editor) => $editor->set('dialogBody', str_repeat('a', 1001)), 'dialogBody', 'Keep the comment to 1,000 characters or fewer.'],
    'a time past thirty days' => [fn ($editor) => $editor->set('dialogSeconds', 2_592_001), 'dialogSeconds', 'Set when it comes: from the post’s own moment to 30 days after it.'],
    'a persona deleted meanwhile' => [fn ($editor, $test) => $test->anna->forceFill(['status' => UserStatus::Deleted])->save(), 'dialogPersona', 'Choose a persona who is not deleted.'],
]);

it('refuses a reply before the comment it answers, in its dialog', function () {
    $saved = savedFour($this);

    discussionEditor($this->plan)->call('openReply', $saved->ids['b'])->set('dialogBody', 'Too early.')->set('dialogSeconds', 30)
        ->call('saveDialog')
        ->assertHasErrors(['dialogSeconds'])
        ->assertSee('A reply cannot come before the comment it answers.');

    // A comment moved past its replies is refused for them, said in the dialog.
    discussionEditor($this->plan)->call('openEdit', $saved->ids['a'])->set('dialogSeconds', 3600)
        ->call('saveDialog')
        ->assertHasErrors(['dialog'])
        ->assertSee('A reply cannot come before the comment it answers.');

    expect(StudioDiscussionNode::query()->count())->toBe(4)
        ->and(StudioDiscussionNode::query()->findOrFail($saved->ids['a'])->offset_seconds)->toBe(60);
});

it('chooses only an active persona in the dialog, keeping the author of a comment edited whatever its status', function (UserStatus $status) {
    $saved = savedFour($this);
    $this->anna->forceFill(['status' => $status])->save();

    discussionEditor($this->plan)->call('openNew')
        ->assertDontSeeHtml('wire:click="choosePersona('.$this->anna->id.')"')
        ->call('choosePersona', $this->anna->id)
        ->assertHasErrors(['dialogPersona'])->assertSee('Choose an active persona.')
        ->assertSet('dialogPersona', fn ($persona) => $persona !== $this->anna->id);

    if ($status === UserStatus::Deleted) {
        return;
    }

    // Her own comment still shows her, and keeps her when saved.
    discussionEditor($this->plan)->call('openEdit', $saved->ids['a'])
        ->assertSet('dialogPersona', $this->anna->id)->assertSee('Anna')
        ->call('choosePersona', $this->boris->id)->call('choosePersona', $this->anna->id)
        ->assertHasNoErrors()->assertSet('dialogPersona', $this->anna->id)
        ->set('dialogBody', 'Lovely light, still.')->call('saveDialog')->assertHasNoErrors();

    expect(StudioDiscussionNode::query()->findOrFail($saved->ids['a']))->body->toBe('Lovely light, still.')->persona_user_id->toBe($this->anna->id);
})->with([
    'limited' => [UserStatus::Limited],
    'shadowbanned' => [UserStatus::Shadowbanned],
    'banned' => [UserStatus::Banned],
    'deleted' => [UserStatus::Deleted],
]);

it('asks before deleting, a comment with its replies as a branch, and undoes it from the toast', function () {
    $saved = savedFour($this);
    $editor = discussionEditor($this->plan)->call('askDelete', $saved->ids['a'])
        ->assertSee('Delete C1 and its 2 replies?')
        ->assertSee('Replies can’t stay without their parent, so the whole branch leaves the plan.')
        ->assertSee('3 comments removed: C1, C2, C3')
        ->assertSee('Delete branch');

    expect(StudioDiscussionNode::query()->count())->toBe(4);

    $editor->call('confirmDelete')->assertDispatched('rg-admin-toast', message: 'Branch C1 deleted', tone: 'success');

    expect(StudioDiscussionNode::query()->pluck('body')->all())->toBe(['Where is this?']);

    $token = $editor->get('undo')['token'];
    $editor->dispatch('undo-studio-discussion', token: 'not-it');
    expect(StudioDiscussionNode::query()->count())->toBe(1);

    $editor->dispatch('undo-studio-discussion', token: $token)->assertSet('undo', null);

    $lovely = StudioDiscussionNode::query()->where('body', 'Lovely light.')->sole();

    expect(StudioDiscussionNode::query()->orderBy('offset_seconds')->pluck('body')->all())->toBe(['Lovely light.', 'Where is this?', 'Agreed.', 'Thank you.'])
        ->and(StudioDiscussionNode::query()->where('body', 'Agreed.')->sole()->parent_id)->toBe($lovely->id)
        ->and(StudioDiscussionNode::query()->where('body', 'Where is this?')->sole()->id)->toBe($saved->ids['b']);
});

it('undoes a deletion only while nothing was written or read since, never over a later change', function (Closure $since) {
    $saved = savedFour($this);
    $editor = discussionEditor($this->plan)->call('askDelete', $saved->ids['a1'])->call('confirmDelete');
    $token = $editor->get('undo')['token'];

    $since($editor, $this, $saved);
    $editor->assertSet('undo', null)->dispatch('undo-studio-discussion', token: $token);
    $after = storedStudioDiscussionNodes($this->plan);

    $editor->dispatch('undo-studio-discussion', token: $token);

    expect(StudioDiscussionNode::query()->whereKey($saved->ids['a1'])->exists())->toBeFalse()
        ->and(StudioDiscussionNode::query()->where('body', 'Agreed.')->exists())->toBeFalse()
        ->and(storedStudioDiscussionNodes($this->plan))->toBe($after);
})->with([
    'a comment added' => [function ($editor, $test) {
        commentFromDialog($editor, $test->boris, 'Added since.')->assertHasNoErrors();
        expect(StudioDiscussionNode::query()->where('body', 'Added since.')->exists())->toBeTrue();
    }],
    'a time changed' => [function ($editor, $test, $saved) {
        $editor->call('setOffset', $saved->ids['b'], 7200)->assertHasNoErrors();
        expect(StudioDiscussionNode::query()->findOrFail($saved->ids['b'])->offset_seconds)->toBe(7200);
    }],
    'a Reload' => [fn ($editor) => $editor->call('reload')],
]);

it('puts back only what was deleted, and writes nothing over another administrator\'s change made since', function () {
    $saved = savedFour($this);
    $editor = discussionEditor($this->plan)->call('askDelete', $saved->ids['a'])->call('confirmDelete');
    rewordStudioComment(User::factory()->admin()->create(), $this->plan, $saved->ids['b'], 'Theirs.');
    $theirs = storedStudioDiscussionNodes($this->plan);

    $editor->dispatch('undo-studio-discussion', token: $editor->get('undo')['token'])
        ->assertSet('conflict', true)
        ->assertDispatched('rg-admin-toast', message: 'Someone changed this draft after you opened it, so nothing was saved. Reload to see their version.');

    expect(storedStudioDiscussionNodes($this->plan))->toBe($theirs)
        ->and(StudioDiscussionNode::query()->pluck('body')->all())->toBe(['Theirs.']);
});

it('asks before deleting a reply alone, and cancels', function () {
    $saved = savedFour($this);

    discussionEditor($this->plan)->call('askDelete', $saved->ids['a1'])
        ->assertSee('Delete C2?')->assertSee('Delete comment')->assertDontSee('Delete branch')
        ->call('closeConfirmation')->assertSet('deleting', null);

    expect(StudioDiscussionNode::query()->count())->toBe(4);

    discussionEditor($this->plan)->call('askDelete', $saved->ids['a1'])->call('confirmDelete')
        ->assertDispatched('rg-admin-toast', message: 'Comment C2 deleted', tone: 'success');

    expect(StudioDiscussionNode::query()->whereKey($saved->ids['a1'])->exists())->toBeFalse()
        ->and(StudioDiscussionNode::query()->count())->toBe(3);
});

it('keeps the Timing tab for the votes editor to draw, and moves a comment the tab says to, written at once — a reply never before its comment', function () {
    $saved = savedFour($this);

    $editor = discussionEditor($this->plan)->set('tab', 'timing')
        ->assertSeeHtml('class="rg-admin-studio__tree-timing" >')->assertSeeHtml('<div id="rg-admin-studio-timing" wire:ignore></div>')
        ->assertDontSeeHtml('class="rg-admin-studio__timing"');

    $editor->dispatch(StudioPlanDiscussionEditor::OFFSET_EVENT, id: $saved->ids['b'], seconds: 7200)->assertHasNoErrors();
    expect(StudioDiscussionNode::query()->findOrFail($saved->ids['b'])->offset_seconds)->toBe(7200);

    $editor->dispatch(StudioPlanDiscussionEditor::OFFSET_EVENT, id: $saved->ids['a1'], seconds: 10)
        ->assertDispatched('rg-admin-toast', message: 'A reply cannot come before the comment it answers.');
    expect(StudioDiscussionNode::query()->findOrFail($saved->ids['a1'])->offset_seconds)->toBe(120);

    // While a generation is on its way, it says so to the tab, and moves nothing.
    StudioPlanDiscussionGeneration::factory()->create(['studio_plan_id' => $this->plan->id]);

    discussionEditor($this->plan)->assertSet('awaiting', fn (?int $awaiting): bool => $awaiting !== null)
        ->assertSeeHtml('x-effect="$store.rgCommentVotes && ($store.rgCommentVotes.generating = $wire.awaiting !== null)"')
        ->dispatch(StudioPlanDiscussionEditor::OFFSET_EVENT, id: $saved->ids['b'], seconds: 60)->assertNotDispatched(StudioPlanDiscussionEditor::VERSION_EVENT);
    expect(StudioDiscussionNode::query()->findOrFail($saved->ids['b'])->offset_seconds)->toBe(7200);
});

it('holds forty comments and replies at most', function () {
    saveStudioDiscussion($this->admin, $this->plan, array_map(fn (int $index) => discussionNode(['key' => "c{$index}", 'personaUserId' => $this->anna->id, 'body' => "Comment {$index}.", 'sortOrder' => $index, 'offsetSeconds' => $index]), range(0, 39)));

    commentFromDialog(discussionEditor($this->plan), $this->anna, 'One more.')->assertHasErrors(['dialog']);

    expect(StudioDiscussionNode::query()->count())->toBe(40);
});

it('keeps a dated post\'s discussion inside the dates a post may carry', function () {
    $this->plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2038-01-18 23:00:00')])->save();

    commentFromDialog(discussionEditor($this->plan), $this->anna, 'Just in time.', seconds: 3599)->assertHasNoErrors();
    commentFromDialog(discussionEditor($this->plan), $this->anna, 'Too late.', seconds: 3600)->assertHasErrors(['dialogSeconds']);

    expect(StudioDiscussionNode::query()->pluck('body')->all())->toBe(['Just in time.']);
});

it('writes nothing over somebody else\'s save, and Reload takes theirs', function () {
    savedFour($this);
    $editor = discussionEditor($this->plan);
    app(UpdateStudioPlanAction::class)->handle($this->admin, StudioPlan::query()->findOrFail($this->plan->id), new StudioPlanData(title: 'Theirs'), 2);

    commentFromDialog($editor, $this->anna, 'Mine.')
        ->assertSet('conflict', true)
        ->assertSet('dialog', null)
        ->assertSee('Someone changed this draft after you opened it')
        ->assertSee('Reload discussion');

    expect(StudioDiscussionNode::query()->where('body', 'Mine.')->exists())->toBeFalse();

    $editor->call('reload')->assertSet('conflict', false)->assertSet('lockVersion', 3);
    commentFromDialog($editor, $this->anna, 'Mine.')->assertHasNoErrors();

    expect(StudioDiscussionNode::query()->where('body', 'Mine.')->exists())->toBeTrue();
});

it('says when the plan was deleted or approved, and writes nothing', function (Closure $change, string $notice, string $flag) {
    $editor = discussionEditor($this->plan)->call('openNew');
    $change($this->plan);

    $editor->call('choosePersona', $this->anna->id)->set('dialogBody', 'Typed.')->call('saveDialog')
        ->assertSet($flag, true)->assertSee($notice);

    expect(StudioDiscussionNode::query()->exists())->toBeFalse();
})->with([
    'deleted' => [fn (StudioPlan $plan) => app(DeleteStudioPlanDraftAction::class)->handle(User::query()->findOrFail($plan->created_by_user_id), $plan, 1), 'This draft was deleted', 'gone'],
    'approved' => [fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), 'This plan is no longer a draft', 'closed'],
]);

it('offers no change to a plan past its draft', function () {
    savedFour($this);
    $this->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

    discussionEditor($this->plan)
        ->assertSee('This plan is no longer a draft')
        ->assertSee('Lovely light.')
        ->assertDontSeeHtml('wire:click="openEdit(')
        ->call('openNew')->assertSet('dialog', null);
});

it('writes nothing but the discussion: no post, comment, action or job', function () {
    commentFromDialog(discussionEditor($this->plan), $this->anna, 'Lovely.')->assertHasNoErrors();

    expect(Post::query()->exists())->toBeFalse()
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse()
        ->and(DB::table('post_votes')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('takes a version this page\'s other editor moved, and no other', function () {
    $editor = discussionEditor($this->plan);
    // The post editor's save, as it moves the plan.
    $this->plan->forceFill(['title' => 'A harbour at dawn', 'lock_version' => 2])->save();

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

it('takes no move the plan does not have: a version it is not at, or a discussion changed by somebody else', function (Closure $meanwhile) {
    $saved = savedFour($this);
    $editor = discussionEditor($this->plan)->assertSet('lockVersion', 2);
    $meanwhile($this);
    $before = storedStudioDiscussionNodes($this->plan);

    $editor->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->plan->id, previous: 2, current: 3, source: 'editor')
        ->assertSet('lockVersion', 2)->assertSet('conflict', true)
        ->assertSee('Reload discussion');

    $editor->call('askDelete', $saved->ids['a'])->call('confirmDelete');
    commentFromDialog($editor, $this->anna, 'Mine.');

    expect(storedStudioDiscussionNodes($this->plan))->toBe($before);
})->with([
    'nothing moved it' => [fn ($test) => null],
    'another administrator\'s discussion' => [fn ($test) => rewordStudioComment(User::factory()->admin()->create(), $test->plan, StudioDiscussionNode::query()->where('body', 'Lovely light.')->value('id'), 'Theirs.')],
    'moved past it' => [fn ($test) => $test->plan->forceFill(['lock_version' => 4])->save()],
]);

it('draws the discussion again when the editor around it reloads the plan, and says its own Reload to it', function () {
    savedFour($this);
    $editor = discussionEditor($this->plan);
    rewordStudioComment(User::factory()->admin()->create(), $this->plan, StudioDiscussionNode::query()->where('body', 'Lovely light.')->value('id'), 'Theirs.');
    commentFromDialog($editor, $this->anna, 'Mine.')->assertSet('conflict', true);

    // The editor around it at the version it holds already: nothing to draw.
    $editor->dispatch(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 3, source: 'discussion')->assertSet('conflict', true);
    $editor->dispatch(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 3, source: 'editor')
        ->assertSet('conflict', false)->assertSet('lockVersion', 3)->assertSee('Theirs.');

    $editor->call('reload')->assertDispatched(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->plan->id, current: 3, source: 'discussion');
});
