<?php

namespace App\Actions\Studio;

use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Jobs\Studio\ExecuteStudioActionJob;
use App\Models\StudioAction;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioExecutionSwitch;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * What `studio:dispatch-due` does every minute (docs/architecture/
 * studio-plans.md, "Running a plan"): hands the actions that are due to the
 * queue, and takes back those lost on their way — each in a short
 * transaction of its own, the job pushed only once it has committed.
 *
 * - Lost: sent, and not claimed `dispatched_stale_after_seconds` later —
 *   Redis lost it, a worker of an older release did not know it, the queue
 *   was flushed. Sent again.
 * - Left behind: claimed, and not finished `running_stale_after_seconds`
 *   later — its worker died, and whatever it had begun was rolled back with
 *   it. Sent again. A run still alive holds the action's row; taking the
 *   action back waits for it, and finds it done.
 * - Due: Pending, its `run_after` come, of a kind that runs, with no parent
 *   or a parent done; oldest `run_after` first, then id.
 *
 * Every send — the first and every one after — raises `attempts` by one, and
 * nothing else does; an action already sent `max_attempts` times is failed
 * instead (`attempts_exhausted`) and waits for an administrator. Each row is
 * locked and asked again before it is sent, so two dispatchers never send one
 * action twice. A push the queue refuses after the commit leaves the action
 * sent, and so found lost, and sent again, later. At most `batch_size` of
 * each per run. Nothing happens while the executor is switched off.
 */
final class DispatchDueStudioActionsAction
{
    public function __construct(
        private readonly StudioExecutionSwitch $switch,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @return array{enabled: bool, sent: int, resent: int, exhausted: int}
     */
    public function handle(): array
    {
        if (! $this->switch->isOn()) {
            return ['enabled' => false, 'sent' => 0, 'resent' => 0, 'exhausted' => 0];
        }

        $now = CarbonImmutable::now();
        $batch = max(1, (int) config('content_studio.actions.batch_size'));
        $tally = ['sent' => 0, 'resent' => 0, 'exhausted' => 0];

        $lostBefore = $now->subSeconds((int) config('content_studio.actions.dispatched_stale_after_seconds'));
        $leftBefore = $now->subSeconds((int) config('content_studio.actions.running_stale_after_seconds'));

        $lost = fn (StudioAction $action): bool => $action->status === StudioActionStatus::Dispatched
            && $action->dispatched_at !== null
            && $action->dispatched_at->lessThanOrEqualTo($lostBefore);
        $left = fn (StudioAction $action): bool => $action->status === StudioActionStatus::Running
            && $action->claimed_at !== null
            && $action->claimed_at->lessThanOrEqualTo($leftBefore);
        $due = fn (StudioAction $action): bool => $action->status === StudioActionStatus::Pending
            && $action->run_after->lessThanOrEqualTo($now)
            && in_array($action->type, ExecuteStudioAction::RUNNABLE, true)
            && ($action->parent_action_id === null
                || StudioAction::query()->whereKey($action->parent_action_id)->where('status', StudioActionStatus::Done)->exists());

        $this->send($this->ids(StudioActionStatus::Dispatched, 'dispatched_at', $lostBefore, $batch), $lost, 'resent', $tally);
        $this->send($this->ids(StudioActionStatus::Running, 'claimed_at', $leftBefore, $batch), $left, 'resent', $tally);
        $this->send($this->dueIds($now, $batch), $due, 'sent', $tally);

        return ['enabled' => true, ...$tally];
    }

    /** @return list<int> */
    private function ids(StudioActionStatus $status, string $since, CarbonImmutable $before, int $batch): array
    {
        return StudioAction::query()
            ->where('status', $status)
            ->where($since, '<=', $before)
            ->orderBy($since)
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function dueIds(CarbonImmutable $now, int $batch): array
    {
        return StudioAction::query()
            ->where('status', StudioActionStatus::Pending)
            ->where('run_after', '<=', $now)
            ->whereIn('type', array_map(fn (StudioActionType $type): string => $type->value, ExecuteStudioAction::RUNNABLE))
            ->where(fn (Builder $query) => $query
                ->whereNull('parent_action_id')
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('status', StudioActionStatus::Done)))
            ->orderBy('run_after')
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @param  Closure(StudioAction): bool  $stillSo  asked again of each row once it is locked
     * @param  'sent'|'resent'  $as
     * @param  array{sent: int, resent: int, exhausted: int}  $tally
     */
    private function send(array $ids, Closure $stillSo, string $as, array &$tally): void
    {
        $maxAttempts = max(1, (int) config('content_studio.actions.max_attempts'));

        foreach ($ids as $id) {
            try {
                $outcome = DB::transaction(function () use ($id, $stillSo, $maxAttempts): ?StudioActionStatus {
                    $action = StudioAction::query()->whereKey($id)->lockForUpdate()->first();

                    if ($action === null || ! $stillSo($action)) {
                        return null;
                    }

                    if ($action->attempts >= $maxAttempts) {
                        self::saved($action->forceFill([
                            'status' => StudioActionStatus::Failed,
                            'failure_code' => StudioActionFailure::AttemptsExhausted->value,
                            'failure_reason' => null,
                            'finished_at' => now(),
                        ]));

                        return StudioActionStatus::Failed;
                    }

                    self::saved($action->forceFill([
                        'status' => StudioActionStatus::Dispatched,
                        'attempts' => $action->attempts + 1,
                        'dispatched_at' => now(),
                        'claimed_at' => null,
                    ]));

                    ExecuteStudioActionJob::dispatch($action->id)->afterCommit();

                    return StudioActionStatus::Dispatched;
                });
            } catch (Throwable $exception) {
                // Committed and not queued, or not committed at all: either way
                // nothing is lost — a sent action no worker claims is found
                // lost and sent again.
                report($exception);
                $this->logger->warning('studio.action.dispatch_failed', ['studio_action_id' => $id, 'exception' => $exception::class]);

                continue;
            }

            if ($outcome === StudioActionStatus::Failed) {
                $tally['exhausted']++;
                $this->logger->warning('studio.action.failed', [
                    'studio_action_id' => $id,
                    'status' => StudioActionStatus::Failed->value,
                    'failure_code' => StudioActionFailure::AttemptsExhausted->value,
                ]);
            } elseif ($outcome === StudioActionStatus::Dispatched) {
                $tally[$as]++;
                $this->logger->info('studio.action.'.$as, ['studio_action_id' => $id, 'status' => StudioActionStatus::Dispatched->value]);
            }
        }
    }

    private static function saved(StudioAction $action): void
    {
        if (! $action->save()) {
            throw new RuntimeException('The studio action was not saved as sent.');
        }
    }
}
