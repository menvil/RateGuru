<?php

use App\Actions\Studio\KeepStudioDiscussionAction;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use App\Support\StudioAi\StudioDiscussionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Keep as is: the whole tree taken in last keeps the post's texts it is kept
 * for, so the editor stops saying it was written for earlier ones — and
 * nothing of the plan changes, not its discussion, not its version.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioDiscussionGeneration();
    $this->admin = User::factory()->admin()->create();
    $this->plan = discussionReadyStudioPlan(['created_by_user_id' => $this->admin->id]);
    $this->persona = studioPersonaWithProfile();
});

/** A whole tree of one comment, asked for and taken in. */
function treeTakenIn(User $admin, StudioPlan $plan, User $persona): StudioPlanDiscussionGeneration
{
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['comments' => [answeredComment('node_1', null, 'persona_1', 60, 'Lovely light.')]]))]);
    $generation = requestStudioDiscussion($admin, $plan, ['personaUserIds' => [$persona->id], 'rootCount' => 1]);
    runStudioDiscussionJob($generation);
    applyStudioDiscussion($admin, $plan, $generation->fresh());

    return $generation->fresh();
}

it('keeps the tree taken in last for the texts the post has now, changing nothing of the plan', function () {
    $earlier = treeTakenIn($this->admin, $this->plan, $this->persona);
    $last = treeTakenIn($this->admin, $this->plan, $this->persona);
    $this->plan->forceFill(['title' => 'Mist on the harbour'])->save();
    $now = StudioDiscussionContext::textFingerprint('Mist on the harbour', $this->plan->description);
    $plan = DB::table('studio_plans')->where('id', $this->plan->id)->first();
    $nodes = storedStudioDiscussionNodes($this->plan);

    expect($last->writtenForOtherTexts($now))->toBeTrue();

    app(KeepStudioDiscussionAction::class)->handle($this->admin, $this->plan->fresh(), 3);

    expect($last->fresh()->parameters)->toMatchArray(['kept_text_fingerprint' => $now, 'source_text_fingerprint' => $last->parameters['source_text_fingerprint']])
        ->and($last->fresh()->writtenForOtherTexts($now))->toBeFalse()
        ->and($last->fresh()->writtenForOtherTexts(StudioDiscussionContext::textFingerprint('Another title', null)))->toBeTrue()
        ->and($earlier->fresh()->parameters)->toBe($earlier->parameters)
        ->and(DB::table('studio_plans')->where('id', $this->plan->id)->first())->toEqual($plan)
        ->and(storedStudioDiscussionNodes($this->plan))->toBe($nodes);
});

it('writes nothing without a whole tree taken in', function () {
    requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->persona->id], 'rootCount' => 1]);

    app(KeepStudioDiscussionAction::class)->handle($this->admin, $this->plan, 1);

    expect(StudioPlanDiscussionGeneration::query()->sole()->parameters)->not->toHaveKey('kept_text_fingerprint');
});

it('keeps nothing for an administrator who may not, or a plan changed, past its draft or gone', function (Closure $arrange, string $refusal) {
    $generation = treeTakenIn($this->admin, $this->plan, $this->persona);
    [$admin, $version] = $arrange($this);

    expect(fn () => app(KeepStudioDiscussionAction::class)->handle($admin, $this->plan, $version))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->{$refusal}())->toBeTrue());

    expect($generation->fresh()->parameters)->not->toHaveKey('kept_text_fingerprint');
})->with([
    'a moderator' => [fn ($test) => [User::factory()->moderator()->create(), 2], 'isNotAllowed'],
    'a version moved since' => [fn ($test) => [$test->admin, 1], 'isStale'],
    'past its draft' => [function ($test) {
        $test->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

        return [$test->admin, 2];
    }, 'isNotDraft'],
]);
