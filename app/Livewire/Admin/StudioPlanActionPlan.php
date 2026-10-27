<?php

namespace App\Livewire\Admin;

use App\Actions\Studio\StopStudioPlanAction;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Filament\Pages\StudioActionQueue;
use App\Filament\Resources\StudioPlans\StudioActionPlan;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Studio\StudioExecutionSwitch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Publication step's Action plan (StudioActionPlan): before approval, a
 * preview of exactly what approval would write, drawn from the saved plan and
 * drawn again whenever another editor on the page saves; once approved, the
 * plan's own actions as they stand, under how the publication stands — who
 * approved it, its post, whether the executor runs — with the Action queue a
 * click away and Stop remaining actions, asked first.
 *
 * A component of its own, so that while the executor is on and any of the
 * plan's actions waits, is sent or runs, it alone is read again every few
 * seconds — never the editor — and stops once nothing of it is left to move. A plan whose state moved
 * meanwhile tells the page, which draws its header again.
 */
final class StudioPlanActionPlan extends Component
{
    /** Seconds between reads while any of the plan's actions waits, is sent or runs. */
    public const POLL_SECONDS = 5;

    /** The event that tells the editor its plan moved on — approved, running, completed — so its header says so. */
    public const MOVED_EVENT = 'studio-plan-moved';

    #[Locked]
    public int $planId = 0;

    /** All, the post's part, or the discussion's. */
    public string $part = 'all';

    #[Locked]
    public int $page = 1;

    /** The plan's state as it was last drawn. */
    #[Locked]
    public ?string $seenStatus = null;

    /** Whether Stop remaining actions is asking to be confirmed. */
    #[Locked]
    public bool $confirmingStop = false;

    public function boot(): void
    {
        abort_unless(Gate::allows('manage-studio-plans'), 403);
    }

    public function mount(int $planId): void
    {
        $this->planId = $planId;
        $this->seenStatus = StudioPlan::query()->find($planId)?->status->value;
    }

    public function updatedPart(): void
    {
        $this->part = array_key_exists($this->part, StudioActionPlan::PARTS) ? $this->part : 'all';
        $this->page = 1;
    }

    public function goToPage(mixed $page): void
    {
        $this->page = max(1, is_numeric($page) ? (int) $page : 1);
    }

    /** Draws the preview again from the plan as it is saved now. */
    public function recalculate(): void
    {
        $this->dispatch('rg-admin-toast', message: 'Action plan recalculated', tone: 'success');
    }

    /**
     * Another editor on the page saved, or drew the plan again: the preview
     * is drawn afresh from what is saved now.
     */
    #[On(StudioPlanDiscussionEditor::VERSION_EVENT)]
    #[On(StudioPlanDiscussionEditor::RELOAD_EVENT)]
    public function follow(mixed $planId = null, mixed $previous = null, mixed $current = null, mixed $source = null): void {}

    public function askToStop(): void
    {
        $this->confirmingStop = true;
    }

    public function closeConfirmation(): void
    {
        $this->confirmingStop = false;
    }

    /** Stop remaining actions, as confirmed: nothing published is taken back. */
    public function stop(): void
    {
        $this->confirmingStop = false;

        try {
            $cancelled = app(StopStudioPlanAction::class)->handle($this->admin(), $this->planId);
        } catch (CannotManageStudioActionException $exception) {
            $this->dispatch('rg-admin-toast', message: match (true) {
                $exception->isNotAllowed() => 'You can’t manage publication plans any more, so nothing was stopped.',
                $exception->isGone() => 'This plan was deleted, so nothing was stopped.',
                default => 'This plan has nothing left to stop.',
            }, tone: 'error');

            return;
        }

        $this->dispatch('rg-admin-toast', message: count($cancelled) === 0
            ? 'Nothing was left to stop'
            : number_format(count($cancelled)).' remaining '.Str::plural('action', count($cancelled)).' stopped', tone: 'success');
    }

    public function render(): View
    {
        $plan = StudioPlan::query()->with(['approvedBy'])->find($this->planId);

        if ($plan !== null && $plan->status->value !== $this->seenStatus) {
            $this->seenStatus = $plan->status->value;
            $this->dispatch(self::MOVED_EVENT, planId: $this->planId);
        }

        $presenter = app(StudioActionPlan::class);
        $part = array_key_exists($this->part, StudioActionPlan::PARTS) ? $this->part : 'all';
        $data = $plan === null ? null : ($plan->isDraft() ? $presenter->preview($plan, $part, $this->page) : $presenter->actual($plan, $part, $this->page));

        if ($data !== null) {
            $this->page = $data['paginator']->currentPage();
        }

        $status = $plan !== null && ! $plan->isDraft() ? $this->status($plan) : null;

        return view('livewire.admin.studio-plan-action-plan', [
            'plan' => $plan,
            'data' => $data,
            'status' => $status,
            // Nothing moves while the executor is off; once it is on, the plan is followed while anything of it waits or runs.
            'polling' => ($data['active'] ?? false) === true && ($status['executorOn'] ?? false) === true,
        ]);
    }

    /**
     * How an approved plan stands: its state, who approved it and when, its
     * post, whether the executor runs, and what can be done about it.
     *
     * @return array<string, mixed>
     */
    private function status(StudioPlan $plan): array
    {
        $post = StudioAction::query()->where('studio_plan_id', $plan->id)->where('type', StudioActionType::CreatePost)->first();
        $published = $post?->status === StudioActionStatus::Done ? Post::query()->find($post->result_id) : null;
        $open = StudioAction::query()
            ->where('studio_plan_id', $plan->id)
            ->whereIn('status', [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Failed, StudioActionStatus::Dispatched])
            ->count();
        $approver = $plan->approvedBy;

        return [
            'state' => StudioPlanFormat::state($plan->status),
            'approved' => $plan->approved_at !== null
                ? 'Approved '.StudioActionPlan::at($plan->approved_at).($approver !== null && ! $approver->isTombstoned() ? ' by @'.$approver->username : '')
                : null,
            'post' => match (true) {
                $post === null => 'No post action',
                $post->status === StudioActionStatus::Done => 'Published, dated '.StudioActionPlan::at($post->occurs_at).' · ran '.StudioActionPlan::at($post->finished_at ?? $post->occurs_at),
                $post->status === StudioActionStatus::Cancelled => 'Never published: its action was cancelled',
                default => 'Not published yet · '.StudioActionPlan::state($post->status)['label'],
            },
            'postUrl' => $published !== null && $published->canReceiveComments() ? route('posts.show', $published) : null,
            'executorOn' => app(StudioExecutionSwitch::class)->isOn(),
            'queueUrl' => StudioActionQueue::getUrl(['plan' => $plan->id]),
            'canStop' => in_array($plan->status, [StudioPlanStatus::Approved, StudioPlanStatus::Running], true) && $open > 0,
            'open' => $open,
        ];
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
