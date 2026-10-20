<?php

namespace App\Livewire\Admin;

use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use App\Support\StudioAi\StudioDiscussionContext;
use App\Support\StudioAi\StudioDiscussionProposal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A plan's discussion generations, on the editor's Discussion step: the
 * newest one's state — waiting, generating, a proposal ready, failed, or
 * lost — the earlier ones, ten at a time, and a proposal previewed apart from
 * the discussion it would change: a whole discussion as the tree it would
 * become, a part as each text it would change, before and after.
 *
 * It only reads. Apply proposal and Retry are asked of the discussion's own
 * editor (StudioPlanDiscussionEditor), which holds the draft and the plan's
 * version; Ignore proposal closes the preview and changes nothing — the
 * proposal stays in the history, as the model has no state for a proposal
 * turned down.
 *
 * A component of its own, so that while a generation is on its way it alone
 * is read again every few seconds — never the discussion being edited beside
 * it. Every request checks that the viewer may still manage plans.
 */
final class StudioPlanDiscussionProposals extends Component
{
    /** Seconds between reads while a generation is on its way. */
    public const POLL_SECONDS = 3;

    /** How many generations are shown at first, and how many more each time. */
    public const PAGE = 10;

    #[Locked]
    public int $planId = 0;

    /** The generation whose proposal is previewed. */
    #[Locked]
    public ?int $previewId = null;

    /** The whole discussion asked to be confirmed before it replaces the saved one. */
    #[Locked]
    public ?int $confirmingId = null;

    /** How many generations are shown. */
    #[Locked]
    public int $shown = self::PAGE;

    public function boot(): void
    {
        abort_unless(Gate::allows('manage-studio-plans'), 403);
    }

    public function mount(int $planId = 0): void
    {
        $this->planId = $planId;
    }

    /** The discussion's editor asked for, applied or saved something: read the generations again. */
    #[On('studio-discussion-generations-changed')]
    public function refresh(): void
    {
        if ($this->previewId !== null && $this->generation($this->previewId) === null) {
            $this->previewId = null;
        }
    }

    public function preview(mixed $generationId): void
    {
        $generation = is_numeric($generationId) ? $this->generation((int) $generationId) : null;
        $this->previewId = $generation !== null && $generation->status === StudioDiscussionGenerationStatus::Completed ? (int) $generation->id : null;
    }

    /** Ignore proposal: closes the preview, nothing changed and nothing stored. */
    public function closePreview(): void
    {
        $this->previewId = null;
        $this->confirmingId = null;
    }

    /**
     * Apply proposal: a whole discussion that would replace a saved one asks
     * first; anything else is handed to the discussion's editor at once.
     */
    public function apply(mixed $generationId): void
    {
        $generation = is_numeric($generationId) ? $this->generation((int) $generationId) : null;

        if ($generation === null) {
            return;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($generation->mode === StudioDiscussionGenerationMode::FullDiscussion && $plan !== null && $plan->discussionNodes()->exists()) {
            $this->confirmingId = (int) $generation->id;

            return;
        }

        $this->dispatch('apply-studio-discussion-generation', generationId: (int) $generation->id);
    }

    public function confirmApply(): void
    {
        $id = $this->confirmingId;
        $this->confirmingId = null;

        if ($id !== null && $this->generation($id) !== null) {
            $this->dispatch('apply-studio-discussion-generation', generationId: $id);
        }
    }

    public function closeConfirmation(): void
    {
        $this->confirmingId = null;
    }

    public function showMore(): void
    {
        $this->shown = min($this->shown + self::PAGE, 100);
    }

    public function render(): View
    {
        $now = CarbonImmutable::now();
        $plan = $this->planId !== 0 ? StudioPlan::query()->with('imageAnalysis')->find($this->planId) : null;
        $generations = $plan !== null
            ? StudioPlanDiscussionGeneration::query()->where('studio_plan_id', $plan->id)->with('requestedBy')->orderByDesc('id')->limit($this->shown + 1)->get()
            : new Collection;
        $more = $generations->count() > $this->shown;
        $generations = $generations->take($this->shown)->values();
        $latest = $generations->first();
        $previewed = $this->previewId !== null ? $generations->firstWhere('id', $this->previewId) ?? $this->generation($this->previewId) : null;

        return view('livewire.admin.studio-plan-discussion-proposals', [
            'draft' => $plan?->isDraft() === true,
            'latest' => $latest !== null ? $this->summary($latest, $now) : null,
            'history' => $generations->skip(1)->map(fn (StudioPlanDiscussionGeneration $generation): array => $this->summary($generation, $now))->values()->all(),
            'more' => $more,
            'polling' => $latest !== null && $latest->isUnderway($now),
            'preview' => $previewed !== null && $plan !== null ? $this->previewOf($previewed, $plan) : null,
            'confirming' => $this->confirmingId !== null && $plan !== null ? $plan->discussionNodes()->count() : null,
        ]);
    }

    /**
     * What the history says of a generation: what it wrote, its state, when
     * and by whom it was asked, how many comments, the tone, whether it was
     * applied, and why it failed — a message of the application's own.
     *
     * @return array<string, mixed>
     */
    private function summary(StudioPlanDiscussionGeneration $generation, CarbonImmutable $now): array
    {
        $phase = match (true) {
            $generation->status->isInProgress() && $generation->isStale($now) => 'stale',
            default => $generation->status->value,
        };
        $parameters = $generation->parameters;
        $proposal = $generation->status === StudioDiscussionGenerationStatus::Completed ? StudioDiscussionProposal::fromStored($generation->mode, $generation->result) : null;
        $requested = $generation->mode === StudioDiscussionGenerationMode::FullDiscussion
            ? (int) ($parameters['root_count'] ?? 0) + (int) ($parameters['reply_count'] ?? 0)
            : null;

        return [
            'id' => (int) $generation->id,
            'mode' => $generation->mode,
            'what' => match ($generation->mode) {
                StudioDiscussionGenerationMode::FullDiscussion => 'Whole discussion',
                StudioDiscussionGenerationMode::RegenerateComment => 'One comment',
                StudioDiscussionGenerationMode::RegenerateReply => 'One reply',
                StudioDiscussionGenerationMode::RegenerateBranch => 'One branch',
            },
            'phase' => $phase,
            'state' => match ($phase) {
                'queued' => ['label' => 'Waiting for generation', 'tone' => 'neutral'],
                'running' => ['label' => 'Generating discussion', 'tone' => 'info'],
                'completed' => $generation->applied_at !== null ? ['label' => 'Applied', 'tone' => 'success'] : ['label' => 'Proposal ready', 'tone' => 'info'],
                'stale' => ['label' => 'Generation may have been lost', 'tone' => 'warning'],
                default => ['label' => 'Generation failed', 'tone' => 'danger'],
            },
            'when' => $generation->requested_at->setTimezone((string) config('app.timezone'))->format('d M Y, H:i'),
            'by' => $generation->requestedBy !== null && ! $generation->requestedBy->isTombstoned() ? (string) $generation->requestedBy->username : null,
            'comments' => $proposal?->count() ?? $requested,
            'tone' => StudioPostTextTone::tryFrom((string) ($parameters['tone'] ?? ''))?->label(),
            'applied' => $generation->applied_at !== null,
            'error' => $phase === 'failed' ? ($generation->error_code ?? StudioDiscussionGenerationError::UnexpectedFailure)->message() : ($phase === 'stale' ? 'The generation did not finish. Ask for it again.' : null),
            'previewable' => $proposal !== null,
        ];
    }

    /**
     * A proposal as it would change the discussion as saved — never as it is
     * being edited: a whole discussion as the tree it would become, a part as
     * each text it would change. It says too whether it can still be applied:
     * whether the plan still has what it was written from.
     *
     * @return array<string, mixed>|null
     */
    private function previewOf(StudioPlanDiscussionGeneration $generation, StudioPlan $plan): ?array
    {
        $proposal = StudioDiscussionProposal::fromStored($generation->mode, $generation->result);

        if ($generation->status !== StudioDiscussionGenerationStatus::Completed || $proposal === null) {
            return null;
        }

        $saved = StudioDiscussionData::of($plan);
        $context = StudioDiscussionContext::of($plan, $plan->imageAnalysis, $generation->persona_user_ids);
        $fits = $context instanceof StudioDiscussionContext
            && $generation->isFor($context->imageAssetId, $context->analysisHash, $context->discussionHash, $context->personasHash);
        $proposed = $proposal->applyTo($saved);
        $people = User::query()->whereKey(array_values(array_unique([...$proposed->personaIds(), ...$saved->personaIds()])))->get()->keyBy('id');
        $name = fn (?int $id): string => StudioPlanFormat::author($id !== null ? $people->get($id) : null)['name'] ?? 'A persona';

        $base = [
            'id' => (int) $generation->id,
            'full' => $generation->mode === StudioDiscussionGenerationMode::FullDiscussion,
            'applied' => $generation->applied_at !== null,
            'fits' => $fits && $plan->isDraft(),
            'replacing' => count($saved->nodes),
        ];

        if ($generation->mode === StudioDiscussionGenerationMode::FullDiscussion) {
            return [...$base, 'tree' => self::tree($proposed->nodes, $name)];
        }

        $before = collect($saved->nodes)->keyBy(fn (StudioDiscussionNodeData $node): int => (int) $node->id);
        $changes = [];

        foreach ($proposal->bodies as $id => $body) {
            $node = $before->get($id);
            $changes[] = [
                'reply' => $node?->parentId !== null,
                'author' => $node !== null ? $name($node->personaUserId) : null,
                'before' => $node?->body,
                'after' => $body,
                'gone' => $node === null,
            ];
        }

        return [...$base, 'changes' => $changes];
    }

    /**
     * A whole discussion as rows: each comment on the post, then its replies.
     *
     * @param  list<StudioDiscussionNodeData>  $nodes
     * @param  callable(int|null): string  $name
     * @return list<array<string, mixed>>
     */
    private static function tree(array $nodes, callable $name): array
    {
        $byPlace = fn (StudioDiscussionNodeData $a, StudioDiscussionNodeData $b): int => $a->sortOrder <=> $b->sortOrder;
        $roots = array_values(array_filter($nodes, fn (StudioDiscussionNodeData $node): bool => $node->parentKey === null && $node->parentId === null));
        usort($roots, $byPlace);
        $rows = [];

        foreach ($roots as $index => $root) {
            $rows[] = ['label' => '#'.($index + 1), 'reply' => false, 'author' => $name($root->personaUserId), 'body' => $root->body, 'relative' => StudioPlanDiscussionEditor::relative($root->offsetSeconds)];
            $replies = array_values(array_filter($nodes, fn (StudioDiscussionNodeData $node): bool => $node->parentKey !== null && $node->parentKey === $root->key));
            usort($replies, $byPlace);

            foreach ($replies as $replyIndex => $reply) {
                $rows[] = ['label' => '#'.($index + 1).'.'.($replyIndex + 1), 'reply' => true, 'author' => $name($reply->personaUserId), 'body' => $reply->body, 'relative' => StudioPlanDiscussionEditor::relative($reply->offsetSeconds)];
            }
        }

        return $rows;
    }

    private function generation(int $id): ?StudioPlanDiscussionGeneration
    {
        return StudioPlanDiscussionGeneration::query()->where('studio_plan_id', $this->planId)->whereKey($id)->first();
    }
}
