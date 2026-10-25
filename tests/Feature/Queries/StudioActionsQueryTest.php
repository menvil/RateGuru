<?php

use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Studio\StudioActionsQuery;
use Illuminate\Support\Carbon;

/**
 * The Action queue and a plan's Action plan: Content Studio's business queue
 * itself, narrowed by state, kind and publication, searched literally over
 * the publication's title, the action's id and its persona's username and
 * names — never a deleted account's — in an order that keeps every action in
 * its place across pages.
 */
function studioActionsPage(string $search = '', ?StudioActionStatus $status = null, ?StudioActionType $type = null, ?int $planId = null, bool $oldestFirst = false, int $page = 1): array
{
    return app(StudioActionsQuery::class)->paginate($search, $status, $type, $planId, $oldestFirst, $page)->pluck('id')->all();
}

afterEach(function () {
    Carbon::setTestNow();
});

it('lists every action, the latest planned first — or the earliest — and by id among those planned together', function () {
    $early = StudioAction::factory()->create(['occurs_at' => now()->subDays(2), 'run_after' => now()]);
    $late = StudioAction::factory()->create(['occurs_at' => now()->subDay(), 'run_after' => now()]);
    $togetherFirst = StudioAction::factory()->create(['occurs_at' => now()->subHour(), 'run_after' => now()]);
    $togetherSecond = StudioAction::factory()->create(['occurs_at' => $togetherFirst->occurs_at, 'run_after' => now()]);

    expect(studioActionsPage())->toBe([$togetherSecond->id, $togetherFirst->id, $late->id, $early->id])
        ->and(studioActionsPage(oldestFirst: true))->toBe([$early->id, $late->id, $togetherFirst->id, $togetherSecond->id]);
});

it('keeps every action in its place across pages', function () {
    $at = now()->subDay();
    $ids = StudioAction::factory()->count(30)->create(['occurs_at' => $at, 'run_after' => $at])->pluck('id')->sortDesc()->values()->all();

    expect([...studioActionsPage(page: 1), ...studioActionsPage(page: 2)])->toBe($ids)
        ->and(studioActionsPage(page: 1))->toHaveCount(StudioActionsQuery::PER_PAGE);
});

it('narrows by state, by kind and by publication', function () {
    $post = StudioAction::factory()->create();
    $comment = StudioAction::factory()->childOf($post)->create(['status' => StudioActionStatus::Failed]);
    $other = StudioAction::factory()->create(['status' => StudioActionStatus::Done]);

    expect(studioActionsPage(status: StudioActionStatus::Failed))->toBe([$comment->id])
        ->and(studioActionsPage(type: StudioActionType::CreatePost))->toEqualCanonicalizing([$post->id, $other->id])
        ->and(studioActionsPage(planId: $post->studio_plan_id))->toEqualCanonicalizing([$post->id, $comment->id])
        ->and(studioActionsPage(status: StudioActionStatus::Done, planId: $post->studio_plan_id))->toBe([]);
});

it('searches the publication\'s title, the action\'s id and its persona, literally and in any case — never a deleted persona by its old name', function () {
    $persona = User::factory()->persona()->create(['username' => 'night_owl', 'name' => 'Gregor Samsa', 'display_name' => 'Gregor Samsa']);
    $byTitle = StudioAction::factory()->for(StudioPlan::factory()->status(StudioPlanStatus::Approved)->state(['title' => 'A Quiet Harbour at dawn']), 'plan')->create();
    $byPersona = StudioAction::factory()->create(['actor_user_id' => $persona->id]);
    $other = StudioAction::factory()->create();
    $gone = User::factory()->persona()->create(['username' => 'old_name']);
    $byGone = StudioAction::factory()->create(['actor_user_id' => $gone->id]);
    $gone->forceFill(['status' => UserStatus::Deleted])->save();

    expect(studioActionsPage('quiet HARBOUR'))->toBe([$byTitle->id])
        ->and(studioActionsPage('#'.$other->id))->toContain($other->id)
        ->and(studioActionsPage((string) $other->id))->toContain($other->id)
        ->and(studioActionsPage('night_'))->toBe([$byPersona->id])
        ->and(studioActionsPage('@night_owl'))->toBe([$byPersona->id])
        ->and(studioActionsPage('samsa'))->toBe([$byPersona->id])
        ->and(studioActionsPage('old_name'))->not->toContain($byGone->id)
        ->and(studioActionsPage('%'))->toBe([]);
});

it('reads one plan\'s actions by when they happen, then as approval wrote them, narrowed to some kinds', function () {
    $post = StudioAction::factory()->create(['occurs_at' => now()->subHours(3), 'run_after' => now()]);
    $vote = StudioAction::factory()->childOf($post, StudioActionType::VotePost)->create(['occurs_at' => now()->subHour(), 'run_after' => now()]);
    $comment = StudioAction::factory()->childOf($post)->create(['occurs_at' => now()->subHours(2), 'run_after' => now()]);
    $together = StudioAction::factory()->childOf($post)->create(['occurs_at' => $comment->occurs_at, 'run_after' => now()]);
    StudioAction::factory()->create();

    $query = app(StudioActionsQuery::class);

    expect($query->forPlan($post->studio_plan_id, null, 1)->pluck('id')->all())->toBe([$post->id, $comment->id, $together->id, $vote->id])
        ->and($query->forPlan($post->studio_plan_id, [StudioActionType::CreatePost, StudioActionType::VotePost], 1)->pluck('id')->all())->toBe([$post->id, $vote->id])
        ->and($query->timelineOf($post->studio_plan_id)->pluck('id')->all())->toBe([$post->id, $comment->id, $together->id, $vote->id]);
});

it('counts what waits, what is sent or runs, what is held and what failed', function () {
    StudioAction::factory()->count(2)->create();
    StudioAction::factory()->create(['status' => StudioActionStatus::Dispatched]);
    StudioAction::factory()->create(['status' => StudioActionStatus::Running]);
    StudioAction::factory()->create(['status' => StudioActionStatus::Held]);
    StudioAction::factory()->create(['status' => StudioActionStatus::Failed]);
    StudioAction::factory()->create(['status' => StudioActionStatus::Done]);

    expect(app(StudioActionsQuery::class)->stats())->toBe(['pending' => 2, 'running' => 2, 'held' => 1, 'failed' => 1]);
});

it('offers the publications approved most recently, and the one the queue is narrowed to', function () {
    $draft = StudioPlan::factory()->create();
    $older = StudioPlan::factory()->status(StudioPlanStatus::Approved)->create(['approved_at' => now()->subDays(3)]);
    $newer = StudioPlan::factory()->status(StudioPlanStatus::Completed)->create(['approved_at' => now()->subDay()]);

    expect(app(StudioActionsQuery::class)->publications(null)->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and(app(StudioActionsQuery::class)->publications($older->id, 1)->pluck('id')->all())->toBe([$older->id, $newer->id])
        ->and(app(StudioActionsQuery::class)->publications($draft->id, 1)->pluck('id')->all())->toBe([$draft->id, $newer->id]);
});
