<?php

namespace App\Queries\Studio;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Content Studio's business queue (`studio_plan_actions`) as the Action
 * queue and a plan's Publication step read it — never Laravel's `jobs` or
 * `failed_jobs`: a job only ever carries an action's id.
 *
 * The queue: narrowed by status, kind and publication, searched over the
 * publication's title, the action's id (`#123` or `123`) and its Persona's
 * username and names — never a deleted account's — the latest planned first
 * or the earliest. A plan's own: its actions by when they happen.
 */
final class StudioActionsQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    public const PER_PAGE = 25;

    /**
     * A page of the queue, each action with its plan, its Persona and the
     * Persona's avatar — everything a row draws, loaded once for the page.
     *
     * @return LengthAwarePaginator<int, StudioAction>
     */
    public function paginate(string $search, ?StudioActionStatus $status, ?StudioActionType $type, ?int $planId, bool $oldestFirst, int $page): LengthAwarePaginator
    {
        $direction = $oldestFirst ? 'asc' : 'desc';

        return $this->narrowed($search, $status, $type, $planId)
            ->with(['plan', 'actor.avatarAsset.variants'])
            // Actions planned for the same moment keep their place across pages.
            ->orderBy('studio_plan_actions.occurs_at', $direction)
            ->orderBy('studio_plan_actions.id', $direction)
            ->paginate(self::PER_PAGE, ['studio_plan_actions.*'], 'page', max(1, $page));
    }

    /**
     * A page of one plan's actions, by when they happen, then as approval
     * wrote them — narrowed to some kinds, or all of them.
     *
     * @param  list<StudioActionType>|null  $types
     * @return LengthAwarePaginator<int, StudioAction>
     */
    public function forPlan(int $planId, ?array $types, int $page): LengthAwarePaginator
    {
        return StudioAction::query()
            ->where('studio_plan_id', $planId)
            ->when($types !== null, fn (Builder $query) => $query->whereIn('type', $types ?? []))
            ->with('actor')
            ->orderBy('occurs_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * Every action of a plan, with only what its timeline and its counts need.
     *
     * @return Collection<int, StudioAction>
     */
    public function timelineOf(int $planId): Collection
    {
        return StudioAction::query()
            ->where('studio_plan_id', $planId)
            ->orderBy('occurs_at')
            ->orderBy('id')
            ->get(['id', 'studio_plan_id', 'type', 'status', 'occurs_at', 'run_after', 'result_type', 'result_id', 'failure_code']);
    }

    /**
     * The figures the queue's header shows: waiting, sent or running, held
     * for an administrator, and failed.
     *
     * @return array{pending: int, running: int, held: int, failed: int}
     */
    public function stats(): array
    {
        $count = fn (StudioActionStatus ...$statuses): int => StudioAction::query()->whereIn('status', $statuses)->count();

        return [
            'pending' => $count(StudioActionStatus::Pending),
            'running' => $count(StudioActionStatus::Dispatched, StudioActionStatus::Running),
            'held' => $count(StudioActionStatus::Held),
            'failed' => $count(StudioActionStatus::Failed),
        ];
    }

    /**
     * The publications the queue can be narrowed to: those approved most
     * recently, and the one it is narrowed to now.
     *
     * @return Collection<int, StudioPlan>
     */
    public function publications(?int $chosen, int $most = 30): Collection
    {
        $recent = StudioPlan::query()
            ->whereNot('status', StudioPlanStatus::Draft)
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit($most)
            ->get(['id', 'title', 'status']);

        if ($chosen !== null && ! $recent->contains('id', $chosen)) {
            $plan = StudioPlan::query()->find($chosen, ['id', 'title', 'status']);

            if ($plan !== null) {
                $recent->prepend($plan);
            }
        }

        return $recent;
    }

    /** @return Builder<StudioAction> */
    private function narrowed(string $search, ?StudioActionStatus $status, ?StudioActionType $type, ?int $planId): Builder
    {
        $query = StudioAction::query();
        $search = trim($search);

        if ($status !== null) {
            $query->where('studio_plan_actions.status', $status);
        }

        if ($type !== null) {
            $query->where('studio_plan_actions.type', $type);
        }

        if ($planId !== null) {
            $query->where('studio_plan_actions.studio_plan_id', $planId);
        }

        if ($search !== '') {
            $id = self::idIn($search);
            $title = LikePattern::containing($search);
            $name = LikePattern::containing(ltrim($search, '@'));

            $query->where(fn (Builder $matching) => $matching
                ->when($id !== null, fn (Builder $byId) => $byId->orWhere('studio_plan_actions.id', $id))
                ->orWhereHas('plan', fn (Builder $plan) => $plan->whereRaw("LOWER(studio_plans.title) LIKE LOWER(?) ESCAPE '!'", [$title]))
                ->orWhereHas('actor', fn (Builder $actor) => $actor
                    ->withoutTombstoned()
                    ->where(fn (Builder $called) => $called
                        ->whereRaw("LOWER(users.username) LIKE LOWER(?) ESCAPE '!'", [$name])
                        ->orWhereRaw("LOWER(users.display_name) LIKE LOWER(?) ESCAPE '!'", [$name])
                        ->orWhereRaw("LOWER(users.name) LIKE LOWER(?) ESCAPE '!'", [$name]))));
        }

        return $query;
    }

    /** `#123` or `123` names an action; anything else — or a number too long to be an id — names none. */
    private static function idIn(string $search): ?int
    {
        $digits = ltrim($search, '#');

        return preg_match('/\A[0-9]{1,18}\z/', $digits) === 1 ? (int) $digits : null;
    }
}
