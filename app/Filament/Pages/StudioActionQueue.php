<?php

namespace App\Filament\Pages;

use App\Actions\Studio\CancelStudioActionAction;
use App\Actions\Studio\ResumeStudioActionAction;
use App\Actions\Studio\RetryStudioActionAction;
use App\Actions\Studio\SwitchStudioExecutionAction;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioActionPlan;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\ModerationListFormat;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Studio\StudioActionsQuery;
use App\Support\Media\AvatarUrlResolver;
use App\Support\Studio\StudioExecutionSwitch;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Content Studio's Action queue in Admin v2: every action approved plans
 * wrote — posts, comments, replies, votes and rating answers — from the
 * business queue itself (`studio_plan_actions`), never from Laravel's jobs:
 * narrowed by state, kind and publication, searched, 25 a page, each with its
 * planned time, when it may run, when it finished, why it stopped and what it
 * wrote (docs/architecture/studio-plans.md, "The Action queue").
 *
 * What an administrator does here goes through Content Studio's actions,
 * which ask again on the locked rows: Retry a failed action, Resume a held
 * one, Cancel one not yet run — and its branch with it, asked first — and
 * switch the executor on or off, asked first too. Nothing done is ever
 * taken back. While the executor is on and anything waits, is sent or runs,
 * the page reads itself again every few seconds.
 */
final class StudioActionQueue extends Page
{
    /** Seconds between reads while any action waits, is sent or runs. */
    public const POLL_SECONDS = 5;

    protected string $view = 'filament.pages.studio-action-queue';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::CONTENT_STUDIO;

    protected static ?string $navigationLabel = 'Action queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $slug = 'studio-actions';

    // After Publications & plans, before Personas, as the section lists them.
    protected static ?int $navigationSort = 6;

    /** The search over the publication, the action's id and its persona. */
    #[Url(except: '')]
    public mixed $q = '';

    /** A state, or every one. Typed loosely: whatever a link puts here reads as a filter. */
    #[Url(except: '')]
    public mixed $status = '';

    /** A kind of action, or every kind. */
    #[Url(except: '')]
    public mixed $type = '';

    /** A publication's id, or every publication. */
    #[Url(except: '')]
    public mixed $plan = '';

    /** The earliest planned first, or — by default — the latest. */
    #[Url(except: '')]
    public mixed $order = '';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /**
     * The confirmation on screen: what it confirms — cancelling an action, or
     * switching the executor on or off.
     *
     * @var array{what: 'cancel'|'switch', actionId?: int, on?: bool}|null
     */
    #[Locked]
    public ?array $confirming = null;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-studio-plans');
    }

    public function getTitle(): string
    {
        return 'Action queue';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new search or filter starts again from the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'status', 'type', 'plan', 'order'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = max(1, is_numeric($page) ? (int) $page : 1);
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->status = '';
        $this->type = '';
        $this->plan = '';
        $this->pageNumber = 1;
    }

    public function toggleOrder(): void
    {
        $this->order = $this->order === 'oldest' ? '' : 'oldest';
        $this->pageNumber = 1;
    }

    /** Retry: a failed action runs again, its attempts counted afresh. */
    public function retry(mixed $id): void
    {
        $this->operate($id, fn (int $actionId) => app(RetryStudioActionAction::class)->handle($this->admin(), $actionId), 'Action #%d queued to run again');
    }

    /** Resume: a held action runs again, its persona asked afresh. */
    public function resume(mixed $id): void
    {
        $this->operate($id, fn (int $actionId) => app(ResumeStudioActionAction::class)->handle($this->admin(), $actionId), 'Action #%d resumed');
    }

    public function askToCancel(mixed $id): void
    {
        $this->confirming = is_numeric($id) ? ['what' => 'cancel', 'actionId' => (int) $id] : null;
    }

    public function askToSwitch(mixed $on): void
    {
        $this->confirming = ['what' => 'switch', 'on' => $on === true || $on === 'on'];
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
    }

    /** Cancel, as confirmed: the action and whatever of its branch still waits. */
    public function cancel(): void
    {
        $id = ($this->confirming['what'] ?? null) === 'cancel' ? ($this->confirming['actionId'] ?? null) : null;
        $this->confirming = null;

        if ($id === null) {
            return;
        }

        $branch = [];
        $done = $this->operate($id, function (int $actionId) use (&$branch): void {
            $branch = app(CancelStudioActionAction::class)->handle($this->admin(), $actionId);
        }, null);

        if ($done) {
            $this->dispatch('rg-admin-toast', message: 'Action #'.$id.' cancelled'.($branch !== [] ? ' with '.count($branch).' '.Str::plural('action', count($branch)).' depending on it' : ''), tone: 'success');
        }
    }

    /** Switches the executor as confirmed. */
    public function switchExecution(): void
    {
        $on = ($this->confirming['what'] ?? null) === 'switch' ? ($this->confirming['on'] ?? null) : null;
        $this->confirming = null;

        if (! is_bool($on)) {
            return;
        }

        try {
            app(SwitchStudioExecutionAction::class)->handle($this->admin(), $on);
        } catch (CannotManageStudioActionException) {
            $this->dispatch('rg-admin-toast', message: 'You can’t switch the executor, so nothing changed.', tone: 'error');

            return;
        }

        $this->dispatch('rg-admin-toast', message: $on ? 'Executor switched on. What is due runs now.' : 'Executor switched off. Nothing more is sent; a run already under way finishes.', tone: 'success');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(StudioActionsQuery::class);
        $status = StudioActionStatus::tryFrom((string) $this->status);
        $type = StudioActionType::tryFrom((string) $this->type);
        $planId = is_numeric($this->plan) ? (int) $this->plan : null;
        $oldest = $this->order === 'oldest';
        $paginator = $query->paginate((string) $this->q, $status, $type, $planId, $oldest, (int) $this->pageNumber);

        // A page past the end shows the last one there is instead, and says so in the address.
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $this->pageNumber = $paginator->lastPage();
            $paginator = $query->paginate((string) $this->q, $status, $type, $planId, $oldest, $this->pageNumber);
        }

        $now = CarbonImmutable::now('UTC');
        $stats = $query->stats();
        $executorOn = app(StudioExecutionSwitch::class)->isOn();
        $publications = $query->publications($planId);
        $chosen = $planId !== null ? $publications->firstWhere('id', $planId) : null;
        $confirmed = ($this->confirming['what'] ?? null) === 'cancel' ? StudioAction::query()->with('plan')->find($this->confirming['actionId'] ?? 0) : null;

        return [
            'stats' => array_map(fn (int $count): string => number_format($count), $stats),
            'executorOn' => $executorOn,
            'canSwitch' => Gate::allows('manage-project-settings'),
            'statuses' => [
                ['value' => '', 'label' => 'All states', 'trigger' => 'All'],
                ...array_map(fn (StudioActionStatus $state): array => ['value' => $state->value, 'label' => StudioActionPlan::state($state)['label']], StudioActionStatus::cases()),
            ],
            'types' => [
                ['value' => '', 'label' => 'All kinds', 'trigger' => 'All'],
                ...array_map(fn (StudioActionType $kind): array => ['value' => $kind->value, 'label' => self::kind($kind)], StudioActionType::cases()),
            ],
            'publications' => [
                ['value' => '', 'label' => 'All publications', 'trigger' => 'All'],
                ...$publications->map(fn (StudioPlan $plan): array => ['value' => (string) $plan->id, 'label' => '#'.$plan->id.' · '.StudioPlanFormat::title($plan)])->all(),
            ],
            'statusName' => $status !== null ? StudioActionPlan::state($status)['label'] : null,
            'typeName' => $type !== null ? self::kind($type) : null,
            'planName' => $planId !== null ? '#'.$planId.($chosen !== null ? ' · '.StudioPlanFormat::title($chosen) : '') : null,
            'oldest' => $oldest,
            'paginator' => $paginator,
            'rows' => array_map(fn (StudioAction $action): array => $this->row($action, $now), $paginator->items()),
            'filtered' => trim((string) $this->q) !== '' || $status !== null || $type !== null || $planId !== null,
            'empty' => StudioAction::query()->doesntExist(),
            // Nothing moves while the executor is off; once it is on, the page follows what waits or runs.
            'polling' => $executorOn && $stats['pending'] + $stats['running'] > 0,
            'confirmation' => match ($this->confirming['what'] ?? null) {
                'cancel' => $confirmed !== null ? ['what' => 'cancel', 'id' => (int) $confirmed->id, 'title' => self::kind($confirmed->type).' #'.$confirmed->id, 'plan' => $confirmed->plan !== null ? StudioPlanFormat::title($confirmed->plan) : 'A deleted plan', 'waiting' => StudioAction::query()->where('parent_action_id', $confirmed->id)->whereIn('status', [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Dispatched])->count()] : null,
                'switch' => ['what' => 'switch', 'on' => (bool) ($this->confirming['on'] ?? false), 'pending' => (int) $stats['pending']],
                default => null,
            },
        ];
    }

    /**
     * One action as its row shows it.
     *
     * @return array<string, mixed>
     */
    private function row(StudioAction $action, CarbonImmutable $now): array
    {
        $actor = $action->actor;
        $failure = StudioActionFailure::tryFrom((string) $action->failure_code);
        $name = $actor !== null && ! $actor->isTombstoned() ? (string) ($actor->display_name ?: ($actor->name ?: $actor->username)) : User::TOMBSTONE_DISPLAY_NAME;

        return [
            'id' => (int) $action->id,
            'plan' => $action->plan !== null ? [
                'id' => (int) $action->plan->id,
                'title' => StudioPlanFormat::title($action->plan),
                'url' => EditStudioPlan::getUrl(['record' => $action->plan->id]),
            ] : null,
            'kind' => self::kind($action->type),
            'icon' => self::icon($action),
            'what' => self::what($action),
            'parent' => $action->parent_action_id,
            'actor' => [
                'name' => $name,
                'username' => $actor !== null && ! $actor->isTombstoned() ? (string) $actor->username : null,
                'avatar' => $actor !== null && ! $actor->isTombstoned() ? app(AvatarUrlResolver::class)->url($actor) : null,
                'initials' => $actor !== null && ! $actor->isTombstoned() ? ModerationListFormat::initials($name) : '',
            ],
            'planned' => StudioActionPlan::at($action->occurs_at),
            'overdue' => $action->status === StudioActionStatus::Pending && $action->occurs_at->lessThan($now),
            'runAfter' => StudioActionPlan::at($action->run_after),
            'finished' => $action->finished_at !== null ? StudioActionPlan::at($action->finished_at) : null,
            'late' => $action->finished_at !== null && $action->finished_at->diffInSeconds($action->occurs_at, true) > 3600,
            ...StudioActionPlan::runs($action, $now),
            'state' => StudioActionPlan::state($action->status),
            'reason' => $failure !== null && $action->status !== StudioActionStatus::Done ? $failure->description() : null,
            'code' => $failure?->value,
            'attempts' => $action->attempts,
            'deferrals' => $action->deferrals,
            'dispatched' => $action->dispatched_at !== null ? StudioActionPlan::at($action->dispatched_at) : null,
            'links' => StudioActionPlan::links($action),
            'canRetry' => $action->status === StudioActionStatus::Failed,
            'canResume' => $action->status === StudioActionStatus::Held,
            'canCancel' => in_array($action->status, [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Failed, StudioActionStatus::Dispatched], true),
        ];
    }

    /** What a kind of action is called. */
    public static function kind(StudioActionType $type): string
    {
        return match ($type) {
            StudioActionType::CreatePost => 'Post',
            StudioActionType::CreateComment => 'Comment',
            StudioActionType::CreateReply => 'Reply',
            StudioActionType::VotePost => 'Post vote',
            StudioActionType::VoteComment => 'Comment vote',
            StudioActionType::VoteRating => 'Rating answer',
        };
    }

    private static function icon(StudioAction $action): string
    {
        $up = ($action->payload['vote_type'] ?? null) === 'up';

        return match ($action->type) {
            StudioActionType::CreatePost => 'newspaper',
            StudioActionType::CreateComment => 'message-square',
            StudioActionType::CreateReply => 'reply',
            StudioActionType::VotePost, StudioActionType::VoteComment => $up ? 'thumbs-up' : 'thumbs-down',
            StudioActionType::VoteRating => 'star',
        };
    }

    /** What the action does, in a line: its kind and what it acts on, never the text a comment says. */
    private static function what(StudioAction $action): string
    {
        $way = ($action->payload['vote_type'] ?? null) === 'up' ? 'Upvote' : 'Downvote';

        return match ($action->type) {
            StudioActionType::CreatePost => 'Publish the post',
            StudioActionType::CreateComment => 'Write a comment on the post',
            StudioActionType::CreateReply => 'Reply to the comment of #'.$action->parent_action_id,
            StudioActionType::VotePost => $way.' the post',
            StudioActionType::VoteComment => $way.' the comment of #'.$action->parent_action_id,
            StudioActionType::VoteRating => 'Answer a rating question',
        };
    }

    /**
     * Runs one of the operations on an action, saying why when it wrote nothing.
     *
     * @param  callable(int): mixed  $operation
     */
    private function operate(mixed $id, callable $operation, ?string $said): bool
    {
        if (! is_numeric($id)) {
            return false;
        }

        try {
            $operation((int) $id);
        } catch (CannotManageStudioActionException $exception) {
            $this->dispatch('rg-admin-toast', message: self::refusal((int) $id, $exception), tone: 'error');

            return false;
        }

        if ($said !== null) {
            $this->dispatch('rg-admin-toast', message: sprintf($said, (int) $id), tone: 'success');
        }

        return true;
    }

    private static function refusal(int $id, CannotManageStudioActionException $exception): string
    {
        return match (true) {
            $exception->isNotAllowed() => 'You can’t manage Content Studio any more, so nothing changed.',
            $exception->reason === 'gone' => 'Action #'.$id.' no longer exists.',
            $exception->reason === 'parent_not_done' => 'Action #'.$id.' depends on one that isn’t done, so it can’t run again yet.',
            $exception->status !== null => 'Action #'.$id.' is '.Str::lower(StudioActionPlan::state($exception->status)['label']).' now, so nothing changed. The list shows it as it is.',
            default => 'Nothing changed.',
        };
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $this->status = is_string($this->status) && StudioActionStatus::tryFrom($this->status) !== null ? $this->status : '';
        $this->type = is_string($this->type) && StudioActionType::tryFrom($this->type) !== null ? $this->type : '';
        $this->plan = is_numeric($this->plan) && preg_match('/\A[0-9]{1,18}\z/', (string) $this->plan) === 1 ? (string) (int) $this->plan : '';
        $this->order = $this->order === 'oldest' ? 'oldest' : '';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);
    }
}
