<?php

namespace App\Livewire\Admin;

use App\Actions\Studio\ApplyStudioDiscussionGenerationAction;
use App\Actions\Studio\RequestStudioDiscussionGenerationAction;
use App\Actions\Studio\SaveStudioDiscussionTreeAction;
use App\Data\Studio\SavedStudioDiscussion;
use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionGenerationData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPostTextTone;
use App\Exceptions\Studio\CannotApplyStudioDiscussionGenerationException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioDiscussionGenerationException;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use App\Queries\Personas\PersonasQuery;
use App\Support\Studio\StudioDiscussionValidationRules;
use App\Support\StudioAi\StudioDiscussionGenerator;
use App\Support\StudioAi\StudioPostTextContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The editor's Discussion step (docs/architecture/studio-plans.md, "The
 * editor"): the comments and replies the post is to get, edited by hand as a
 * draft of their own and saved with Save discussion
 * (SaveStudioDiscussionTreeAction) — the one way a discussion is written —
 * and written by AI on an explicit request, whole or one part at a time
 * (RequestStudioDiscussionGenerationAction), a proposal taken in only with
 * Apply proposal (ApplyStudioDiscussionGenerationAction).
 *
 * The draft (`nodes`) is this component's, compared with the discussion as
 * saved (`savedNodes`) to say what is unsaved; nothing reaches the database
 * but through the actions, which check everything again. A saved comment
 * keeps its id; a new one goes by a key of this draft's own until it is saved.
 *
 * It lives as long as the plan's editor page does — hidden on the other
 * steps, never drawn again — so nothing typed is lost by moving between
 * steps. Generations are shown, read again while one is on its way, and
 * previewed by a component of their own (StudioPlanDiscussionProposals), so
 * reading them never draws this draft again.
 *
 * One version for the whole plan: every write here or in the editor around
 * it raises it, and each says so with `studio-plan-version-moved` — the plan,
 * the version it was written from and the one it left. A version moved by
 * this page is taken; any other — another administrator's save — is not, and
 * Reload is asked for. Every request checks the viewer may still manage plans.
 */
final class StudioPlanDiscussionEditor extends Component
{
    /** What the two editors of a plan say a write of theirs moved its version with. */
    public const VERSION_EVENT = 'studio-plan-version-moved';

    /** The most Personas offered at once to choose from. */
    private const CHOICES = 8;

    #[Locked]
    public int $planId = 0;

    /** The plan's lock_version as this draft last read or wrote it. */
    #[Locked]
    public int $lockVersion = 0;

    /** Whether the plan moved to a version this page did not write, so nothing here is saved until Reload. */
    #[Locked]
    public bool $conflict = false;

    /** Whether the plan was deleted meanwhile. */
    #[Locked]
    public bool $gone = false;

    /** Whether the plan is past its Draft. */
    #[Locked]
    public bool $closed = false;

    /**
     * The draft discussion, by a key of its own — `n12` for the saved comment
     * 12, `k3` for a new one — in the order it is listed: each comment on the
     * post, then its replies.
     *
     * @var array<string, array{id: int|null, parent: string|null, personaUserId: int|null, body: string, offsetSeconds: int}>
     */
    public array $nodes = [];

    /**
     * The discussion as saved, in the same shape: what the draft is compared
     * with.
     *
     * @var array<string, array{id: int|null, parent: string|null, personaUserId: int|null, body: string, offsetSeconds: int}>
     */
    #[Locked]
    public array $savedNodes = [];

    /** The number the next new comment's key is made from. */
    #[Locked]
    public int $nextKey = 1;

    /** The comment open for editing. */
    #[Locked]
    public ?string $editing = null;

    /** The comment on the post asked to be deleted with its replies. */
    #[Locked]
    public ?string $deleting = null;

    /** The comment whose Regenerate choices are open. */
    #[Locked]
    public ?string $regenerating = null;

    /** The search over the Personas offered to the comment open for editing. */
    public string $personaSearch = '';

    /** How many comments and replies a whole discussion is to have. */
    public int $totalCount = 6;

    /** How many of them are comments on the post; the rest are replies. */
    public int $rootCount = 3;

    /** manual: the Personas chosen one by one; random: a team picked by Pick personas. */
    public string $participantMode = 'manual';

    /**
     * The Personas a whole discussion is to be written by.
     *
     * @var list<int>
     */
    #[Locked]
    public array $participants = [];

    /** How many Personas Pick personas picks. */
    public int $randomCount = 3;

    /** The search over the Personas offered to take part. */
    public string $participantSearch = '';

    /** The tone the next generation is asked in. */
    public string $tone = StudioPostTextTone::DEFAULT->value;

    public function boot(): void
    {
        abort_unless(Gate::allows('manage-studio-plans'), 403);
    }

    public function mount(int $planId = 0): void
    {
        $this->planId = $planId;

        if ($planId !== 0) {
            $this->load();
        }
    }

    // ------------------------------------------------------------------
    // The draft, by hand
    // ------------------------------------------------------------------

    public function addComment(): void
    {
        if (! $this->canAdd()) {
            return;
        }

        $latest = max([0, ...array_map(fn (array $node): int => $node['offsetSeconds'], array_filter($this->nodes, fn (array $node): bool => $node['parent'] === null))]);
        $key = $this->newKey();
        $this->nodes[$key] = self::node(null, null, $this->participants[0] ?? null, '', min(StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS, $latest + 600));
        $this->open($key);
    }

    public function addReply(mixed $key): void
    {
        $key = $this->known($key);

        if ($key === null || $this->nodes[$key]['parent'] !== null || ! $this->canAdd()) {
            return;
        }

        $siblings = $this->childrenOf($key);
        $after = $siblings === [] ? $key : end($siblings);
        $latest = max([$this->nodes[$key]['offsetSeconds'], ...array_map(fn (string $child): int => $this->nodes[$child]['offsetSeconds'], $siblings)]);
        $reply = $this->newKey();

        $this->nodes = self::inserted($this->nodes, $after, $reply, self::node(null, $key, null, '', min(StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS, $latest + 300)));
        $this->open($reply);
    }

    public function edit(mixed $key): void
    {
        $key = $this->known($key);

        if ($key !== null) {
            $this->editing === $key ? $this->closeEditor() : $this->open($key);
        }
    }

    public function closeEditor(): void
    {
        $this->editing = null;
        $this->personaSearch = '';
    }

    /** Chooses who writes a comment: a Persona that is not deleted, as the discussion's rules allow. */
    public function choosePersona(mixed $key, mixed $id): void
    {
        $key = $this->known($key);
        $persona = is_numeric($id) ? app(PersonasQuery::class)->choosable((int) $id) : null;

        if ($key === null) {
            return;
        }

        if ($persona === null) {
            $this->addError("nodes.{$key}.personaUserId", 'Choose a persona who is not deleted.');

            return;
        }

        $this->nodes[$key]['personaUserId'] = (int) $persona->id;
        $this->personaSearch = '';
        $this->resetErrorBag("nodes.{$key}.personaUserId");
    }

    /** Moves a comment one place up among its siblings: the comments on the post, or one comment's replies. */
    public function moveUp(mixed $key): void
    {
        $this->move($key, -1);
    }

    public function moveDown(mixed $key): void
    {
        $this->move($key, 1);
    }

    /**
     * Takes a reply, or a comment with no replies, out of the draft; a
     * comment with replies is asked about first, since its replies go with it.
     */
    public function delete(mixed $key): void
    {
        $key = $this->known($key);

        if ($key === null) {
            return;
        }

        if ($this->nodes[$key]['parent'] === null && $this->childrenOf($key) !== []) {
            $this->deleting = $key;

            return;
        }

        $this->remove($key);
    }

    public function confirmDelete(): void
    {
        $key = $this->known($this->deleting);
        $this->deleting = null;

        if ($key !== null) {
            $this->remove($key);
        }
    }

    public function cancelDelete(): void
    {
        $this->deleting = null;
    }

    /** Puts the discussion as saved back in place of the draft, reading nothing anew. */
    public function discardChanges(): void
    {
        $this->nodes = $this->savedNodes;
        $this->closeEditor();
        $this->deleting = null;
        $this->regenerating = null;
        $this->resetErrorBag();
    }

    /** Draws the discussion as it is saved now, with the plan's version: what was typed here is replaced. */
    #[On('reload-studio-discussion')]
    public function reload(): void
    {
        $this->load();
        $this->refreshProposals();
    }

    /**
     * Saves the draft as the plan's whole discussion: comments added, changed
     * and deleted together, through the one action that writes discussions.
     */
    public function save(): void
    {
        if (! $this->writable()) {
            return;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), 'the discussion wasn’t saved');

            return;
        }

        [$data, $order] = $this->data();
        $this->resetErrorBag();

        try {
            $saved = app(SaveStudioDiscussionTreeAction::class)->handle($this->admin(), $plan, $data, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception, 'the discussion wasn’t saved');

            return;
        } catch (ValidationException $exception) {
            $this->showErrors($exception, $order);

            return;
        }

        $this->settle($saved);
        $this->toast('Discussion saved', 'success');
    }

    // ------------------------------------------------------------------
    // Who takes part in a whole discussion
    // ------------------------------------------------------------------

    public function updatedParticipantMode(): void
    {
        $this->participantMode = $this->participantMode === 'random' ? 'random' : 'manual';
        $this->participants = [];
        $this->resetErrorBag('participants');
    }

    public function updatedTotalCount(): void
    {
        $this->totalCount = max(1, min($this->mostNodes(), $this->totalCount));
        $this->rootCount = max(1, min($this->totalCount, $this->rootCount));
    }

    public function updatedRootCount(): void
    {
        $this->rootCount = max(1, min($this->totalCount, $this->rootCount));
    }

    public function updatedRandomCount(): void
    {
        $this->randomCount = max(1, min($this->mostPersonas(), $this->randomCount));
    }

    /** Adds a Persona to the team, or takes it out of it. */
    public function toggleParticipant(mixed $id): void
    {
        if (! is_numeric($id)) {
            return;
        }

        $id = (int) $id;
        $this->resetErrorBag('participants');

        if (in_array($id, $this->participants, true)) {
            $this->participants = array_values(array_diff($this->participants, [$id]));

            return;
        }

        if (count($this->participants) >= $this->mostPersonas()) {
            $this->addError('participants', "Choose up to {$this->mostPersonas()} personas.");

            return;
        }

        if (app(PersonasQuery::class)->choosable($id) !== null) {
            $this->participants[] = $id;
        }
    }

    public function clearParticipants(): void
    {
        $this->participants = [];
        $this->resetErrorBag('participants');
    }

    /**
     * Picks a team at random — only here, on this explicit action, and shown
     * before anything is generated; picking again picks again.
     */
    public function pickPersonas(): void
    {
        $this->updatedRandomCount();
        $picked = app(PersonasQuery::class)->randomChoosable($this->randomCount);
        $this->resetErrorBag('participants');

        if ($picked->count() < $this->randomCount) {
            $this->participants = [];
            $this->addError('participants', $picked->isEmpty()
                ? 'There are no personas to pick from. Create a persona first.'
                : "Only {$picked->count()} ".($picked->count() === 1 ? 'persona is' : 'personas are').' available. Pick fewer.');

            return;
        }

        $this->participants = $picked->map(fn (User $persona): int => (int) $persona->id)->values()->all();
    }

    // ------------------------------------------------------------------
    // Generation
    // ------------------------------------------------------------------

    /**
     * Asks for a whole discussion — an explicit, paid request — by the team
     * shown, never one picked here. Nothing is asked while the post or this
     * discussion has unsaved changes: the discussion is written from what is
     * saved.
     */
    public function generateTree(mixed $postChanges = 0): void
    {
        $this->updatedTotalCount();
        $this->resetErrorBag('participants');

        if (! $this->readyToGenerate($postChanges)) {
            return;
        }

        if ($this->participants === []) {
            $this->addError('participants', $this->participantMode === 'random'
                ? 'Pick the personas first: Pick personas shows the team before anything is generated.'
                : 'Choose at least one persona to take part.');

            return;
        }

        $this->request(new StudioDiscussionGenerationData(
            mode: StudioDiscussionGenerationMode::FullDiscussion,
            expectedLockVersion: $this->lockVersion,
            personaUserIds: $this->participants,
            rootCount: $this->rootCount,
            replyCount: $this->totalCount - $this->rootCount,
            tone: $this->chosenTone(),
        ));
    }

    public function openRegenerate(mixed $key): void
    {
        $key = $this->known($key);
        $this->regenerating = $key !== null && $this->regenerating !== $key ? $key : null;
    }

    /**
     * Writes one saved part of the discussion again — a comment, a reply, or a
     * comment with its replies — its authors, places and times kept.
     */
    public function regenerate(mixed $key, mixed $what, mixed $postChanges = 0): void
    {
        $key = $this->known($key);
        $mode = match ($what) {
            'comment' => StudioDiscussionGenerationMode::RegenerateComment,
            'reply' => StudioDiscussionGenerationMode::RegenerateReply,
            'branch' => StudioDiscussionGenerationMode::RegenerateBranch,
            default => null,
        };

        if ($key === null || $mode === null) {
            return;
        }

        if ($this->nodes[$key]['id'] === null || ($this->savedNodes[$key]['id'] ?? null) === null) {
            $this->toast('Save the discussion first: only a saved comment can be written again.');

            return;
        }

        if (! $this->readyToGenerate($postChanges)) {
            return;
        }

        $this->regenerating = null;
        $this->request(new StudioDiscussionGenerationData(
            mode: $mode,
            expectedLockVersion: $this->lockVersion,
            targetNodeId: $this->nodes[$key]['id'],
            tone: $this->chosenTone(),
        ));
    }

    /**
     * Asks again what a generation asked — Retry, a new explicit request of
     * the administrator's, never one made by itself.
     */
    #[On('retry-studio-discussion-generation')]
    public function retryGeneration(mixed $generationId, mixed $postChanges = 0): void
    {
        $generation = is_numeric($generationId)
            ? StudioPlanDiscussionGeneration::query()->where('studio_plan_id', $this->planId)->whereKey((int) $generationId)->first()
            : null;

        if ($generation === null || ! $this->readyToGenerate($postChanges)) {
            return;
        }

        $parameters = $generation->parameters;
        $this->request(new StudioDiscussionGenerationData(
            mode: $generation->mode,
            expectedLockVersion: $this->lockVersion,
            personaUserIds: $generation->mode->isTargeted() ? [] : $generation->persona_user_ids,
            targetNodeId: $generation->targetNodeId(),
            rootCount: (int) ($parameters['root_count'] ?? 0),
            replyCount: (int) ($parameters['reply_count'] ?? 0),
            tone: StudioPostTextTone::tryFrom((string) ($parameters['tone'] ?? '')) ?? StudioPostTextTone::DEFAULT,
        ));
    }

    /**
     * Takes a proposal into the plan's discussion — only with nothing unsaved
     * here, since the proposal is about the discussion as saved. Whether it
     * still fits is the action's to say.
     */
    #[On('apply-studio-discussion-generation')]
    public function applyGeneration(mixed $generationId): void
    {
        if (! is_numeric($generationId) || ! $this->writable()) {
            return;
        }

        if ($this->dirty()) {
            $this->toast('Save or discard your changes to the discussion first: a proposal is applied to the discussion as saved.');

            return;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), 'the proposal wasn’t applied');

            return;
        }

        try {
            $saved = app(ApplyStudioDiscussionGenerationAction::class)->handle($this->admin(), $plan, (int) $generationId, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception, 'the proposal wasn’t applied');

            return;
        } catch (CannotApplyStudioDiscussionGenerationException $exception) {
            $this->toast($exception->error->message());
            $this->refreshProposals();

            return;
        } catch (ValidationException $exception) {
            $this->toast('The proposal no longer fits the discussion’s rules, so it wasn’t applied: '.collect($exception->errors())->flatten()->first());

            return;
        }

        $this->settle($saved);
        $this->toast('Proposal applied to the discussion', 'success');
    }

    /**
     * The generation on its way settled, as the generations' component saw:
     * drawn again, so Generate and Regenerate are offered again. Nothing is
     * read into the draft, which stays as it is.
     */
    #[On('studio-discussion-generation-settled')]
    public function generationSettled(): void {}

    /**
     * The other editor of this plan on the page — the post's, the photo's —
     * moved its version with a write of its own: taken, when it was written
     * from the version this draft holds. Any other move is somebody else's.
     */
    #[On(self::VERSION_EVENT)]
    public function followVersion(mixed $planId, mixed $previous, mixed $current, mixed $source = null): void
    {
        if ($source === 'discussion' || ! is_numeric($planId) || (int) $planId !== $this->planId || ! is_numeric($previous) || ! is_numeric($current)) {
            return;
        }

        [$previous, $current] = [(int) $previous, (int) $current];

        if ($current === $this->lockVersion) {
            return;
        }

        if ($previous === $this->lockVersion && $current === $previous + 1) {
            $this->lockVersion = $current;

            return;
        }

        $this->conflict = true;
    }

    public function render(): View
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->with('imageAnalysis')->find($this->planId) : null;
        $personaIds = array_values(array_unique(array_filter([
            ...array_map(fn (array $node): ?int => $node['personaUserId'], $this->nodes),
            ...$this->participants,
        ])));
        $people = User::query()->whereKey($personaIds)->with('avatarAsset.variants')->get()->keyBy('id');
        $generator = app(StudioDiscussionGenerator::class);
        $context = $plan !== null ? StudioPostTextContext::of($plan->image_asset_id, $plan->imageAnalysis) : null;
        $postAt = $plan?->publication_mode === StudioPlanPublicationMode::AtDate ? $plan->publish_at : null;
        $underway = $plan !== null && StudioPlanDiscussionGeneration::query()
            ->where('studio_plan_id', $plan->id)
            ->whereIn('status', ['queued', 'running'])
            ->get()
            ->contains(fn (StudioPlanDiscussionGeneration $generation): bool => $generation->isUnderway(CarbonImmutable::now()));
        $editingNode = $this->editing !== null ? ($this->nodes[$this->editing] ?? null) : null;

        return view('livewire.admin.studio-plan-discussion-editor', [
            'rows' => $this->rows($people, $postAt),
            'count' => count($this->nodes),
            'mostNodes' => StudioDiscussionValidationRules::MOST_NODES,
            'dirty' => $this->dirty(),
            'editable' => $this->writable(),
            'timezone' => (string) config('app.timezone'),
            'postAt' => $postAt?->toIso8601String(),
            'latestOffset' => StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS,
            'bodyLimit' => StudioDiscussionValidationRules::BODY_LIMIT,
            'personaChoices' => $editingNode !== null ? $this->choices($this->personaSearch, $editingNode['personaUserId'] !== null ? [$editingNode['personaUserId']] : []) : [],
            'participantChoices' => $this->participantMode === 'manual' ? $this->choices($this->participantSearch, $this->participants) : [],
            'participantsShown' => array_values(array_filter(array_map(fn (int $id): ?array => StudioPlanFormat::author($people->get($id)), $this->participants))),
            'mostPersonas' => $this->mostPersonas(),
            'mostGenerated' => $this->mostNodes(),
            'tones' => array_map(fn (StudioPostTextTone $tone): array => ['value' => $tone->value, 'label' => $tone->label(), 'hint' => $tone->hint()], StudioPostTextTone::cases()),
            'chosenTone' => $this->chosenTone(),
            'configured' => $generator->isConfigured(),
            'hasTitle' => $plan !== null && trim((string) $plan->title) !== '',
            'analysed' => $context !== null && $context->complete,
            'underway' => $underway,
            'generated' => $plan !== null && StudioPlanDiscussionGeneration::query()->where('studio_plan_id', $plan->id)->exists(),
            'deletingRow' => $this->deleting !== null && isset($this->nodes[$this->deleting])
                ? ['replies' => count($this->childrenOf($this->deleting)), 'body' => $this->nodes[$this->deleting]['body'], 'author' => StudioPlanFormat::author($people->get((int) $this->nodes[$this->deleting]['personaUserId']))]
                : null,
        ]);
    }

    // ------------------------------------------------------------------

    /** Reads the plan as it is now: its version, whether it can be written, and its discussion as the draft. */
    private function load(): void
    {
        $plan = StudioPlan::query()->find($this->planId);
        $this->resetErrorBag();
        $this->closeEditor();
        $this->deleting = null;
        $this->regenerating = null;
        $this->conflict = false;

        if ($plan === null) {
            $this->gone = true;
            $this->nodes = $this->savedNodes = [];

            return;
        }

        $this->lockVersion = $plan->lock_version;
        $this->closed = ! $plan->isDraft();
        $this->nodes = $this->savedNodes = self::localTree(StudioDiscussionData::of($plan));
    }

    /**
     * Takes what a save or an applied proposal left as the draft and as what
     * it is compared with, and says the plan's new version to the editor
     * around it.
     */
    private function settle(SavedStudioDiscussion $saved): void
    {
        $previous = $this->lockVersion;
        $this->lockVersion = $saved->lockVersion;
        $this->nodes = $this->savedNodes = self::localTree(new StudioDiscussionData($saved->nodes
            ->map(fn ($node): StudioDiscussionNodeData => StudioDiscussionNodeData::of($node))
            ->values()
            ->all()));
        $this->closeEditor();
        $this->deleting = null;
        $this->regenerating = null;
        $this->resetErrorBag();

        $this->dispatch(self::VERSION_EVENT, planId: $this->planId, previous: $previous, current: $this->lockVersion, source: 'discussion');
        $this->refreshProposals();
    }

    /**
     * The draft as the action takes it — each comment in its order, its place
     * among its siblings counted afresh — and which draft key each index is.
     *
     * @return array{0: StudioDiscussionData, 1: list<string>}
     */
    private function data(): array
    {
        $places = [];
        $nodes = [];
        $order = [];

        foreach ($this->nodes as $key => $node) {
            $parent = $node['parent'] !== null ? ($this->nodes[$node['parent']] ?? null) : null;
            $siblings = $node['parent'] ?? '';
            $places[$siblings] = ($places[$siblings] ?? -1) + 1;
            $order[] = $key;

            $nodes[] = new StudioDiscussionNodeData(
                id: $node['id'],
                key: $node['id'] === null ? $key : null,
                parentId: $parent !== null ? $parent['id'] : null,
                parentKey: $parent !== null && $parent['id'] === null ? $node['parent'] : null,
                personaUserId: $node['personaUserId'],
                body: (string) $node['body'],
                sortOrder: $places[$siblings],
                offsetSeconds: (int) $node['offsetSeconds'],
            );
        }

        return [new StudioDiscussionData($nodes), $order];
    }

    /**
     * Puts each message the action gave by the comment it is about, and opens
     * the first such comment, the draft kept as it is.
     *
     * @param  list<string>  $order
     */
    private function showErrors(ValidationException $exception, array $order): void
    {
        $first = null;

        foreach ($exception->errors() as $field => $messages) {
            if (preg_match('/^nodes\.(\d+)\.(\w+)$/', $field, $match) === 1 && isset($order[(int) $match[1]])) {
                $key = $order[(int) $match[1]];
                $first ??= $key;
                $this->addError("nodes.{$key}.{$match[2]}", (string) ($messages[0] ?? ''));
            } else {
                $this->addError('discussion', (string) ($messages[0] ?? ''));
            }
        }

        if ($first !== null) {
            $this->open($first);
        }

        $this->toast('The discussion wasn’t saved. Fix what is marked and save again.');
    }

    /** Sends a request for a generation, and says what stands in its way when something does. */
    private function request(StudioDiscussionGenerationData $data): void
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), 'nothing was sent');

            return;
        }

        try {
            app(RequestStudioDiscussionGenerationAction::class)->handle($this->admin(), $plan, $data);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception, 'nothing was sent');

            return;
        } catch (CannotRequestStudioDiscussionGenerationException $exception) {
            $this->toast($exception->error->message());

            return;
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->refreshProposals(requested: true);
        $this->toast('Discussion requested. It appears below as a proposal once it is written.', 'success');
    }

    /**
     * Whether a generation may be asked for now: a draft this page may still
     * write, nothing unsaved in the post or here, and none already on its way.
     */
    private function readyToGenerate(mixed $postChanges): bool
    {
        if (! $this->writable()) {
            return false;
        }

        if (is_numeric($postChanges) && (int) $postChanges > 0) {
            $this->toast('Save the post first: the discussion is written from the saved title and description.');

            return false;
        }

        if ($this->dirty()) {
            $this->toast('Save or discard your changes to the discussion first: it is written from the discussion as saved.');

            return false;
        }

        $underway = StudioPlanDiscussionGeneration::query()
            ->where('studio_plan_id', $this->planId)
            ->whereIn('status', ['queued', 'running'])
            ->get()
            ->contains(fn (StudioPlanDiscussionGeneration $generation): bool => $generation->isUnderway(CarbonImmutable::now()));

        if ($underway) {
            $this->toast('A generation is already on its way. Wait for it before asking for another.');

            return false;
        }

        return true;
    }

    /** Says why nothing was written, keeping the draft as it is. */
    private function refuse(CannotManageStudioPlanException $exception, string $nothing): void
    {
        match (true) {
            $exception->isStale() => $this->conflict = true,
            $exception->isGone() => $this->gone = true,
            $exception->isNotDraft() => $this->closed = true,
            default => null,
        };

        $this->toast(match (true) {
            $exception->isStale() => "Someone changed this draft after you opened it, so {$nothing}. Reload to see their version.",
            $exception->isGone() => "This draft was deleted, so {$nothing}.",
            $exception->isNotDraft() => "This plan is no longer a draft, so {$nothing}.",
            default => "You can’t manage publication plans any more, so {$nothing}.",
        });
    }

    /**
     * The draft as rows: each comment on the post, then its replies, with who
     * writes it, its time, and what is unsaved or wrong about it.
     *
     * @param  Collection<int, User>  $people
     * @return list<array<string, mixed>>
     */
    private function rows($people, ?CarbonImmutable $postAt): array
    {
        $rows = [];
        $roots = array_keys(array_filter($this->nodes, fn (array $node): bool => $node['parent'] === null));

        foreach ($roots as $rootIndex => $root) {
            $replies = $this->childrenOf($root);
            $rows[] = $this->row($root, (string) ($rootIndex + 1), $rootIndex === 0, $rootIndex === count($roots) - 1, $people, $postAt, count($replies));

            foreach ($replies as $replyIndex => $reply) {
                $rows[] = $this->row($reply, ($rootIndex + 1).'.'.($replyIndex + 1), $replyIndex === 0, $replyIndex === count($replies) - 1, $people, $postAt, 0);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, bool $first, bool $last, $people, ?CarbonImmutable $postAt, int $replies): array
    {
        $node = $this->nodes[$key];
        $parent = $node['parent'] !== null ? ($this->nodes[$node['parent']] ?? null) : null;
        $persona = $node['personaUserId'] !== null ? $people->get($node['personaUserId']) : null;

        return [
            'key' => $key,
            'label' => '#'.$label,
            'reply' => $node['parent'] !== null,
            'saved' => $node['id'] !== null && isset($this->savedNodes[$key]),
            'changed' => ($this->savedNodes[$key] ?? null) !== $node,
            'replies' => $replies,
            'persona' => StudioPlanFormat::author($persona),
            'personaUserId' => $node['personaUserId'],
            'body' => (string) $node['body'],
            'offset' => (int) $node['offsetSeconds'],
            'relative' => self::relative((int) $node['offsetSeconds']),
            'sinceParent' => $parent !== null ? self::relative(max(0, (int) $node['offsetSeconds'] - (int) $parent['offsetSeconds']), false) : null,
            'parentOffset' => $parent !== null ? (int) $parent['offsetSeconds'] : null,
            'at' => $postAt?->addSeconds((int) $node['offsetSeconds'])->setTimezone((string) config('app.timezone'))->format('d M Y, H:i:s'),
            'first' => $first,
            'last' => $last,
        ];
    }

    /**
     * Personas to choose from, matching the search, never those already chosen.
     *
     * @param  list<int>  $chosen
     * @return list<array<string, mixed>>
     */
    private function choices(string $search, array $chosen): array
    {
        return app(PersonasQuery::class)
            ->choices(mb_substr(trim($search), 0, 100), self::CHOICES + count($chosen))
            ->reject(fn (User $persona): bool => in_array((int) $persona->id, $chosen, true))
            ->take(self::CHOICES)
            ->map(fn (User $persona): ?array => StudioPlanFormat::author($persona))
            ->filter()
            ->values()
            ->all();
    }

    private function move(mixed $key, int $by): void
    {
        $key = $this->known($key);

        if ($key === null) {
            return;
        }

        $parent = $this->nodes[$key]['parent'];
        $siblings = $parent === null
            ? array_keys(array_filter($this->nodes, fn (array $node): bool => $node['parent'] === null))
            : $this->childrenOf($parent);
        $at = array_search($key, $siblings, true);
        $to = $at + $by;

        if ($at === false || $to < 0 || $to >= count($siblings)) {
            return;
        }

        [$siblings[$at], $siblings[$to]] = [$siblings[$to], $siblings[$at]];

        // The comments on the post in their new order, each followed by its replies.
        $roots = $parent === null ? $siblings : array_keys(array_filter($this->nodes, fn (array $node): bool => $node['parent'] === null));
        $nodes = [];

        foreach ($roots as $root) {
            $nodes[$root] = $this->nodes[$root];

            foreach ($root === $parent ? $siblings : $this->childrenOf($root) as $child) {
                $nodes[$child] = $this->nodes[$child];
            }
        }

        $this->nodes = $nodes;
    }

    private function remove(string $key): void
    {
        foreach ([$key, ...$this->childrenOf($key)] as $gone) {
            unset($this->nodes[$gone]);
            $this->resetErrorBag(["nodes.{$gone}.body", "nodes.{$gone}.personaUserId", "nodes.{$gone}.offsetSeconds"]);
        }

        if ($this->editing !== null && ! isset($this->nodes[$this->editing])) {
            $this->closeEditor();
        }
    }

    private function open(string $key): void
    {
        $this->editing = $key;
        $this->personaSearch = '';
        $this->regenerating = null;
    }

    /** @return list<string> a comment's replies, in their order */
    private function childrenOf(string $key): array
    {
        return array_keys(array_filter($this->nodes, fn (array $node): bool => $node['parent'] === $key));
    }

    private function known(mixed $key): ?string
    {
        return is_string($key) && isset($this->nodes[$key]) ? $key : null;
    }

    private function canAdd(): bool
    {
        if (! $this->writable()) {
            return false;
        }

        if (count($this->nodes) >= StudioDiscussionValidationRules::MOST_NODES) {
            $this->toast('A discussion holds up to '.StudioDiscussionValidationRules::MOST_NODES.' comments and replies. Delete one to add another.');

            return false;
        }

        return true;
    }

    private function newKey(): string
    {
        return 'k'.$this->nextKey++;
    }

    private function writable(): bool
    {
        return $this->planId !== 0 && ! $this->gone && ! $this->closed && ! $this->conflict;
    }

    /** Whether the draft holds anything the saved discussion does not. */
    private function dirty(): bool
    {
        return self::comparable($this->nodes) !== self::comparable($this->savedNodes);
    }

    private function chosenTone(): StudioPostTextTone
    {
        return StudioPostTextTone::tryFrom($this->tone) ?? StudioPostTextTone::DEFAULT;
    }

    private function mostNodes(): int
    {
        return max(1, min(app(StudioDiscussionGenerator::class)->maxNodes(), StudioDiscussionValidationRules::MOST_NODES));
    }

    private function mostPersonas(): int
    {
        return max(1, app(StudioDiscussionGenerator::class)->maxPersonas());
    }

    private function refreshProposals(bool $requested = false): void
    {
        $this->dispatch('studio-discussion-generations-changed', requested: $requested)->to(StudioPlanDiscussionProposals::class);
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function toast(string $message, string $tone = 'error'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone);
    }

    /**
     * A discussion as the draft holds it: each comment on the post, then its
     * replies, each level in its order.
     *
     * @return array<string, array{id: int|null, parent: string|null, personaUserId: int|null, body: string, offsetSeconds: int}>
     */
    private static function localTree(StudioDiscussionData $data): array
    {
        $byId = [];

        foreach ($data->nodes as $node) {
            $byId[(int) $node->id] = $node;
        }

        $byPlace = fn (StudioDiscussionNodeData $a, StudioDiscussionNodeData $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id];
        $roots = array_values(array_filter($byId, fn (StudioDiscussionNodeData $node): bool => $node->parentId === null));
        usort($roots, $byPlace);
        $tree = [];

        foreach ($roots as $root) {
            $tree['n'.$root->id] = self::node($root->id, null, $root->personaUserId, $root->body, $root->offsetSeconds);
            $replies = array_values(array_filter($byId, fn (StudioDiscussionNodeData $node): bool => $node->parentId === $root->id));
            usort($replies, $byPlace);

            foreach ($replies as $reply) {
                $tree['n'.$reply->id] = self::node($reply->id, 'n'.$root->id, $reply->personaUserId, $reply->body, $reply->offsetSeconds);
            }
        }

        return $tree;
    }

    /** @return array{id: int|null, parent: string|null, personaUserId: int|null, body: string, offsetSeconds: int} */
    private static function node(?int $id, ?string $parent, ?int $personaUserId, string $body, int $offsetSeconds): array
    {
        return ['id' => $id, 'parent' => $parent, 'personaUserId' => $personaUserId, 'body' => $body, 'offsetSeconds' => $offsetSeconds];
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private static function comparable(array $nodes): array
    {
        return array_map(fn (array $node): array => [
            is_numeric($node['id'] ?? null) ? (int) $node['id'] : null,
            is_string($node['parent'] ?? null) ? $node['parent'] : null,
            is_numeric($node['personaUserId'] ?? null) ? (int) $node['personaUserId'] : null,
            (string) ($node['body'] ?? ''),
            (int) ($node['offsetSeconds'] ?? 0),
        ], $nodes);
    }

    /**
     * Inserts a node right after another, keeping every key.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $node
     * @return array<string, array<string, mixed>>
     */
    private static function inserted(array $nodes, string $after, string $key, array $node): array
    {
        $result = [];

        foreach ($nodes as $existing => $value) {
            $result[$existing] = $value;

            if ($existing === $after) {
                $result[$key] = $node;
            }
        }

        return $result;
    }

    /** A time after the post as it is read: `+2 h 15 min`. */
    public static function relative(int $seconds, bool $signed = true): string
    {
        $parts = array_filter([
            intdiv($seconds, 86400) > 0 ? intdiv($seconds, 86400).' d' : null,
            intdiv($seconds % 86400, 3600) > 0 ? intdiv($seconds % 86400, 3600).' h' : null,
            intdiv($seconds % 3600, 60) > 0 ? intdiv($seconds % 3600, 60).' min' : null,
            $seconds % 60 > 0 ? ($seconds % 60).' s' : null,
        ]);

        return ($signed ? '+' : '').($parts === [] ? '0 min' : implode(' ', $parts));
    }
}
