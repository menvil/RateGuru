<?php

namespace App\Livewire\Admin;

use App\Actions\Studio\ApplyStudioDiscussionGenerationAction;
use App\Actions\Studio\RequestStudioDiscussionGenerationAction;
use App\Actions\Studio\SaveStudioDiscussionTreeAction;
use App\Data\Studio\SavedStudioDiscussion;
use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionGenerationData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
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
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The editor's Discussion step (docs/architecture/studio-plans.md, "The
 * editor"), working as the prototype's does: Discussion settings — how many
 * comments, how many of them on the post, and the Personas, picked at random
 * when the tree is generated or checked one by one — Generate tree under
 * them, and the discussion tree: each comment with Reply, Edit, Regenerate
 * and Delete, and the Timing of them all.
 *
 * Every change is written to the plan at once, as the Image step's photo is:
 * a comment saved from its dialog, deleted, or given another moment, through
 * SaveStudioDiscussionTreeAction — the one way a discussion is written. What
 * AI writes is taken in through ApplyStudioDiscussionGenerationAction the
 * moment it is ready: a whole tree, which replaces a discussion with comments
 * only once that is confirmed, or one comment written again. Delete and
 * Regenerate can be undone from their toast. Nothing is published.
 *
 * Generations run on the queue. While one this page asked for, or found on
 * its way, is, the component is read again every few seconds until it
 * settles — the whole tree drawn as placeholders, or the comment being
 * written again faded. It lives as long as the plan's editor page does,
 * hidden on the other steps.
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

    /** Seconds between reads while a generation is on its way. */
    public const POLL_SECONDS = 3;

    /** The units a moment after the post is given in, in seconds. */
    public const UNITS = ['min' => 60, 'h' => 3600, 'd' => 86400];

    /** The most Personas listed to check, and offered in a comment's dialog. */
    private const PERSONA_LIST = 50;

    private const DIALOG_PERSONAS = 12;

    #[Locked]
    public int $planId = 0;

    /** The plan's lock_version as this page last read or wrote it. */
    #[Locked]
    public int $lockVersion = 0;

    /** Whether the plan moved to a version this page did not write, so nothing here is written until Reload. */
    #[Locked]
    public bool $conflict = false;

    /** Whether the plan was deleted meanwhile. */
    #[Locked]
    public bool $gone = false;

    /** Whether the plan is past its Draft. */
    #[Locked]
    public bool $closed = false;

    /** How many comments and replies a whole tree is to have; none is no discussion. */
    public int $totalCount = 14;

    /** How many of them are comments on the post; the rest are replies. */
    public int $rootCount = 5;

    /** random: as many Personas as asked for, picked when the tree is generated; manual: the ones checked. */
    public string $participantMode = 'random';

    /** How many Personas a random team has. */
    public int $randomCount = 6;

    /**
     * The Personas checked to take part.
     *
     * @var list<int>
     */
    #[Locked]
    public array $manual = [];

    /** The search over the Personas listed to check. */
    public string $participantSearch = '';

    /** The discussion tree's tab on screen: its comments, or their timing. */
    public string $tab = 'tree';

    /** The generation this page waits for, to take in the moment it is ready. */
    #[Locked]
    public ?int $awaiting = null;

    /** The comment that generation writes again, when it writes one. */
    #[Locked]
    public ?int $rewriting = null;

    /** Why the last whole tree asked for was not taken in. */
    #[Locked]
    public ?string $failure = null;

    /** Whether Generate is asking before it replaces the discussion. */
    #[Locked]
    public bool $confirmingGenerate = false;

    /** The comment Delete is asking about. */
    #[Locked]
    public ?int $deleting = null;

    /**
     * The comment dialog: what it does — edit a comment, reply to one, or
     * write a new one on the post — the comment it edits, and the comment it
     * replies to.
     *
     * @var array{mode: string, id: int|null, parentId: int|null}|null
     */
    #[Locked]
    public ?array $dialog = null;

    /** Who writes the comment in the dialog. */
    #[Locked]
    public ?int $dialogPersona = null;

    public string $dialogBody = '';

    /** When the comment in the dialog comes, in seconds after the post. */
    public int $dialogSeconds = 0;

    /** The unit that moment is given in: min, h or d. */
    public string $dialogUnit = 'min';

    /** The search over the Personas offered in the dialog. */
    public string $dialogSearch = '';

    /**
     * The discussion as it was before the last change that can be undone, and
     * the token its toast's Undo carries.
     *
     * @var array{token: string, tree: list<array<string, mixed>>}|null
     */
    #[Locked]
    public ?array $undo = null;

    public function boot(): void
    {
        abort_unless(Gate::allows('manage-studio-plans'), 403);
    }

    public function mount(int $planId = 0): void
    {
        $this->planId = $planId;

        if ($planId === 0) {
            return;
        }

        $this->load();

        $now = CarbonImmutable::now();
        $underway = StudioPlanDiscussionGeneration::query()
            ->where('studio_plan_id', $planId)
            ->whereIn('status', [StudioDiscussionGenerationStatus::Queued, StudioDiscussionGenerationStatus::Running])
            ->latest('id')
            ->get()
            ->first(fn (StudioPlanDiscussionGeneration $generation): bool => $generation->isUnderway($now));

        if ($underway !== null) {
            $this->awaiting = (int) $underway->id;
            $this->rewriting = $underway->mode->isTargeted() ? $underway->targetNodeId() : null;
        }

        // The settings the last whole tree was asked with.
        $last = StudioPlanDiscussionGeneration::query()
            ->where('studio_plan_id', $planId)
            ->where('mode', StudioDiscussionGenerationMode::FullDiscussion)
            ->latest('id')
            ->first();

        if ($last !== null) {
            $roots = (int) ($last->parameters['root_count'] ?? 0);
            $this->rootCount = max(1, $roots);
            $this->totalCount = max(1, $roots + (int) ($last->parameters['reply_count'] ?? 0));
            $this->randomCount = max(1, count($last->persona_user_ids));
        }

        $this->updatedTotalCount();
        $this->updatedRandomCount();
    }

    // ------------------------------------------------------------------
    // Discussion settings
    // ------------------------------------------------------------------

    public function updatedTotalCount(): void
    {
        $this->totalCount = max(0, min($this->mostNodes(), $this->totalCount));
        $this->rootCount = $this->totalCount === 0 ? 0 : max(1, min($this->totalCount, $this->rootCount));
    }

    public function updatedRootCount(): void
    {
        $this->rootCount = $this->totalCount === 0 ? 0 : max(1, min($this->totalCount, $this->rootCount));
    }

    public function updatedRandomCount(): void
    {
        $this->randomCount = max(1, min($this->mostRandom(), $this->randomCount));
    }

    public function updatedParticipantMode(): void
    {
        $this->participantMode = $this->participantMode === 'manual' ? 'manual' : 'random';
        $this->resetErrorBag('participants');
    }

    public function updatedTab(): void
    {
        $this->tab = $this->tab === 'timing' ? 'timing' : 'tree';
    }

    /** Checks a Persona to take part, or unchecks it. */
    public function toggleParticipant(mixed $id): void
    {
        if (! is_numeric($id)) {
            return;
        }

        $id = (int) $id;
        $this->resetErrorBag('participants');

        if (in_array($id, $this->manual, true)) {
            $this->manual = array_values(array_diff($this->manual, [$id]));

            return;
        }

        if (count($this->manual) >= $this->mostPersonas()) {
            $this->addError('participants', "Up to {$this->mostPersonas()} personas take part in one discussion.");

            return;
        }

        if (app(PersonasQuery::class)->choosable($id) !== null) {
            $this->manual[] = $id;
        }
    }

    /** Checks every Persona the search shows, as many as may take part. */
    public function selectShown(): void
    {
        $this->resetErrorBag('participants');

        foreach ($this->personaList() as $persona) {
            if (in_array((int) $persona->id, $this->manual, true)) {
                continue;
            }

            if (count($this->manual) >= $this->mostPersonas()) {
                $this->addError('participants', "Up to {$this->mostPersonas()} personas take part in one discussion.");

                return;
            }

            $this->manual[] = (int) $persona->id;
        }
    }

    public function clearParticipants(): void
    {
        $this->manual = [];
        $this->resetErrorBag('participants');
    }

    // ------------------------------------------------------------------
    // Generate tree, and Regenerate
    // ------------------------------------------------------------------

    /**
     * Generate tree, or Regenerate tree: asks first when the discussion has
     * comments, since the new tree replaces them all. Nothing is asked while
     * the post has unsaved changes — the discussion is written from what is
     * saved — or while a generation is on its way.
     */
    public function generateTree(mixed $postChanges = 0): void
    {
        $this->updatedTotalCount();

        if (! $this->readyToGenerate($postChanges) || ($this->totalCount > 0 && $this->team(pick: false) === null)) {
            return;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan !== null && $plan->discussionNodes()->exists()) {
            $this->confirmingGenerate = true;

            return;
        }

        $this->startGeneration();
    }

    public function confirmGenerate(mixed $postChanges = 0): void
    {
        $this->confirmingGenerate = false;

        if ($this->readyToGenerate($postChanges)) {
            $this->startGeneration();
        }
    }

    public function closeConfirmation(): void
    {
        $this->confirmingGenerate = false;
        $this->deleting = null;
    }

    /**
     * Regenerate: writes one comment's text again, by the same Persona, in
     * its place and at its time — taken in the moment it is ready, with Undo.
     */
    public function regenerate(mixed $id, mixed $postChanges = 0): void
    {
        $node = is_numeric($id) ? $this->savedNode((int) $id) : null;

        if ($node === null || ! $this->readyToGenerate($postChanges)) {
            return;
        }

        $generation = $this->request(new StudioDiscussionGenerationData(
            mode: $node->parentId === null ? StudioDiscussionGenerationMode::RegenerateComment : StudioDiscussionGenerationMode::RegenerateReply,
            expectedLockVersion: $this->lockVersion,
            targetNodeId: (int) $node->id,
            tone: StudioPostTextTone::DEFAULT,
        ));

        if ($generation !== null) {
            $this->awaiting = (int) $generation->id;
            $this->rewriting = (int) $node->id;
        }
    }

    /**
     * Read again every few seconds while a generation is on its way: once it
     * has settled, what it wrote is taken in — or why it was not, said.
     */
    public function check(): void
    {
        if ($this->awaiting === null) {
            return;
        }

        $generation = StudioPlanDiscussionGeneration::query()->where('studio_plan_id', $this->planId)->whereKey($this->awaiting)->first();
        $now = CarbonImmutable::now();

        if ($generation !== null && $generation->isUnderway($now)) {
            return;
        }

        $rewriting = $this->rewriting;
        $this->awaiting = null;
        $this->rewriting = null;

        if ($generation === null) {
            return;
        }

        if ($generation->status !== StudioDiscussionGenerationStatus::Completed) {
            $this->failed($generation->isStale($now) ? 'The generation did not finish. Try again.' : ($generation->error_code ?? StudioDiscussionGenerationError::UnexpectedFailure)->message(), $rewriting);

            return;
        }

        if ($generation->applied_at !== null) {
            // Taken in already, from another page of this plan.
            $this->load();

            return;
        }

        $plan = $this->writablePlan();

        if ($plan === null) {
            return;
        }

        $before = $this->savedRows($plan);

        try {
            $saved = app(ApplyStudioDiscussionGenerationAction::class)->handle($this->admin(), $plan, (int) $generation->id, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception);

            return;
        } catch (CannotApplyStudioDiscussionGenerationException $exception) {
            $this->failed($exception->error->message(), $rewriting);

            return;
        } catch (ValidationException $exception) {
            $this->failed('What was written no longer fits the discussion’s rules: '.collect($exception->errors())->flatten()->first(), $rewriting);

            return;
        }

        $this->settle($saved);

        if ($rewriting !== null) {
            $label = self::labels(self::ordered($this->savedRows($plan)))[$rewriting] ?? 'the comment';
            $this->remember($before);
            $this->toast("New text for {$label} · same author and time", 'success', undoable: true);
        }
    }

    // ------------------------------------------------------------------
    // A comment: new, a reply, or edited
    // ------------------------------------------------------------------

    /** + Comment: a new comment on the post, twenty minutes after the last one. */
    public function openNew(): void
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return;
        }

        $roots = array_filter($this->savedRows($plan), fn (array $row): bool => $row['parentId'] === null);
        $last = max([0, ...array_map(fn (array $row): int => (int) $row['offsetSeconds'], $roots)]);

        $this->openDialog(['mode' => 'new', 'id' => null, 'parentId' => null], $this->teamPersonas()[0] ?? null, '', $last + 20 * 60);
    }

    /** Reply: an answer to a comment on the post, ten minutes after it, by somebody else. */
    public function openReply(mixed $id): void
    {
        $parent = is_numeric($id) ? $this->savedNode((int) $id) : null;

        if ($parent === null || $parent->parentId !== null || $this->writablePlan() === null) {
            return;
        }

        $persona = collect($this->teamPersonas())->first(fn (int $persona): bool => $persona !== $parent->personaUserId) ?? $parent->personaUserId;
        $this->openDialog(['mode' => 'reply', 'id' => null, 'parentId' => (int) $parent->id], $persona, '', $parent->offsetSeconds + 10 * 60);
    }

    public function openEdit(mixed $id): void
    {
        $node = is_numeric($id) ? $this->savedNode((int) $id) : null;

        if ($node === null || $this->writablePlan() === null) {
            return;
        }

        $this->openDialog(['mode' => 'edit', 'id' => (int) $node->id, 'parentId' => $node->parentId], $node->personaUserId, $node->body, $node->offsetSeconds);
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
        $this->dialogPersona = null;
        $this->dialogBody = '';
        $this->dialogSearch = '';
        $this->resetErrorBag();
    }

    public function choosePersona(mixed $id): void
    {
        if ($this->dialog === null) {
            return;
        }

        $persona = is_numeric($id) ? app(PersonasQuery::class)->choosable((int) $id) : null;

        if ($persona === null) {
            $this->addError('dialogPersona', 'Choose a persona who is not deleted.');

            return;
        }

        $this->dialogPersona = (int) $persona->id;
        $this->resetErrorBag('dialogPersona');
    }

    /** Replies to: a comment on the post, or none — a comment with replies stays on the post. */
    public function chooseParent(mixed $id): void
    {
        if ($this->dialog === null) {
            return;
        }

        $parent = is_numeric($id) ? $this->savedNode((int) $id) : null;
        $editing = $this->dialog['id'];

        if ($parent === null) {
            $this->dialog['parentId'] = null;

            return;
        }

        $hasReplies = $editing !== null && collect($this->savedRowsNow())->contains(fn (array $row): bool => $row['parentId'] === $editing);

        if ($parent->parentId === null && (int) $parent->id !== $editing && ! $hasReplies) {
            $this->dialog['parentId'] = (int) $parent->id;
        }
    }

    /** Save: the comment is written to the plan at once, its errors kept in the dialog. */
    public function saveDialog(): void
    {
        if ($this->dialog === null) {
            return;
        }

        $body = trim($this->dialogBody);

        if ($body === '') {
            $this->addError('dialogBody', 'Write the comment first.');

            return;
        }

        $dialog = $this->dialog;
        $persona = $this->dialogPersona;
        $seconds = max(0, $this->dialogSeconds);

        $saved = $this->write(function (array $rows) use ($dialog, $persona, $body, $seconds): array {
            if ($dialog['mode'] === 'edit') {
                return array_map(fn (array $row): array => $row['id'] === $dialog['id']
                    ? [...$row, 'parentId' => $dialog['parentId'], 'personaUserId' => $persona, 'body' => $body, 'offsetSeconds' => $seconds]
                    : $row, $rows);
            }

            return [...$rows, self::row(null, $dialog['parentId'], $persona, $body, $seconds, key: 'new')];
        }, focus: $dialog['mode'] === 'edit' ? 'n'.$dialog['id'] : 'new');

        if ($saved !== null) {
            $this->closeDialog();
        }
    }

    // ------------------------------------------------------------------
    // Delete, timing, undo
    // ------------------------------------------------------------------

    public function askDelete(mixed $id): void
    {
        $node = is_numeric($id) ? $this->savedNode((int) $id) : null;
        $this->deleting = $node !== null && $this->writable() ? (int) $node->id : null;
    }

    /** Delete comment, or Delete branch: a comment's replies go with it. */
    public function confirmDelete(): void
    {
        $id = $this->deleting;
        $this->deleting = null;
        $plan = $this->writablePlan();

        if ($id === null || $plan === null || $this->savedNode($id) === null) {
            return;
        }

        $rows = $this->savedRows($plan);
        $label = self::labels(self::ordered($rows))[$id] ?? 'the comment';
        $branch = collect($rows)->contains(fn (array $row): bool => $row['parentId'] === $id);

        $saved = $this->write(
            fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['id'] !== $id && $row['parentId'] !== $id)),
            remember: true,
        );

        if ($saved !== null) {
            $this->toast(($branch ? 'Branch ' : 'Comment ').$label.' deleted', 'success', undoable: true);
        }
    }

    /** Timing: a comment given another moment after the post, written at once. */
    public function setOffset(mixed $id, mixed $seconds): void
    {
        if (! is_numeric($id) || ! is_numeric($seconds) || $this->savedNode((int) $id) === null) {
            return;
        }

        [$id, $seconds] = [(int) $id, max(0, (int) round((float) $seconds))];

        $this->write(fn (array $rows): array => array_map(
            fn (array $row): array => $row['id'] === $id ? [...$row, 'offsetSeconds' => $seconds] : $row,
            $rows,
        ));
    }

    /** Undo, from the toast of a deletion or a comment written again: the discussion as it was put back. */
    #[On('undo-studio-discussion')]
    public function undoChange(mixed $token = null): void
    {
        $undo = $this->undo;

        if ($undo === null || ! is_string($token) || ! hash_equals($undo['token'], $token)) {
            return;
        }

        $this->undo = null;
        $this->write(fn (array $rows): array => self::restored($undo['tree'], $rows));
    }

    /** Draws the discussion as it is saved now, with the plan's version. */
    #[On('reload-studio-discussion')]
    public function reload(): void
    {
        $this->load();
    }

    /**
     * The other editor of this plan on the page — the post's, the photo's —
     * moved its version with a write of its own: taken, when it was written
     * from the version this page holds. Any other move is somebody else's.
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
        $rows = $plan !== null ? $this->savedRows($plan) : [];
        $ordered = self::ordered($rows);
        $labels = self::labels($ordered);
        $picked = $plan !== null ? $this->pickedForTree($plan) : [];
        $personaIds = array_values(array_unique(array_filter([
            ...array_column($rows, 'personaUserId'),
            ...$this->manual,
            ...$picked,
            $this->dialogPersona,
        ])));
        $people = User::query()->whereKey($personaIds)->with('avatarAsset.variants')->get()->keyBy('id');
        $context = $plan !== null ? StudioPostTextContext::of($plan->image_asset_id, $plan->imageAnalysis) : null;
        $listed = $this->participantMode === 'manual' ? $this->personaList() : new EloquentCollection;

        return view('livewire.admin.studio-plan-discussion-editor', [
            'editable' => $this->writable(),
            'busy' => $this->awaiting !== null && $this->rewriting === null,
            'underway' => $this->awaiting !== null,
            'pollSeconds' => self::POLL_SECONDS,
            'configured' => app(StudioDiscussionGenerator::class)->isConfigured(),
            'hasTitle' => $plan !== null && trim((string) $plan->title) !== '',
            'analysed' => $context !== null && $context->complete,
            'mostNodes' => $this->mostNodes(),
            'mostRandom' => $this->mostRandom(),
            'available' => app(PersonasQuery::class)->choosableCount(),
            'personaCount' => $this->participantMode === 'random' ? $this->randomCount : count($this->manual),
            'pickedNames' => array_values(array_filter(array_map(fn (int $id): ?string => StudioPlanFormat::author($people->get($id))['name'] ?? null, $picked))),
            'chosen' => array_values(array_filter(array_map(fn (int $id): ?array => StudioPlanFormat::author($people->get($id)), $this->manual))),
            'listed' => $listed->map(fn (User $persona): array => [
                ...(StudioPlanFormat::author($persona) ?? []),
                'short' => Str::limit((string) ($persona->personaProfile->short_description ?? ''), 90),
                'on' => in_array((int) $persona->id, $this->manual, true),
            ])->values()->all(),
            'searching' => trim($this->participantSearch) !== '',
            'rows' => array_map(fn (array $entry): array => $this->displayRow($entry, $labels, $rows, $people), $ordered),
            'rootTotal' => count(array_filter($rows, fn (array $row): bool => $row['parentId'] === null)),
            'timing' => $this->timingRows($rows, $labels, $people),
            'dialogData' => $this->dialog !== null ? $this->dialogData($rows, $labels, $people) : null,
            'deletingRow' => $this->deleting !== null ? $this->deletingData($rows, $ordered, $labels, $people) : null,
        ]);
    }

    // ------------------------------------------------------------------

    /** Reads the plan as it is now: its version, and whether it can be written. */
    private function load(): void
    {
        $plan = StudioPlan::query()->find($this->planId);
        $this->resetErrorBag();
        $this->conflict = false;
        $this->dialog = null;
        $this->deleting = null;
        $this->confirmingGenerate = false;

        if ($plan === null) {
            $this->gone = true;

            return;
        }

        $this->lockVersion = $plan->lock_version;
        $this->closed = ! $plan->isDraft();
    }

    /** Takes the version a write left, and says it to the editor around this one. */
    private function settle(SavedStudioDiscussion $saved): void
    {
        $previous = $this->lockVersion;
        $this->lockVersion = $saved->lockVersion;
        $this->failure = null;

        $this->dispatch(self::VERSION_EVENT, planId: $this->planId, previous: $previous, current: $this->lockVersion, source: 'discussion');
    }

    /**
     * Writes the discussion as the change leaves it — every comment in its
     * place among its siblings by time — through the one action that writes
     * discussions. What it refuses is said in the dialog when it is about the
     * dialog's comment (`focus`), and in a toast otherwise.
     *
     * @param  Closure(list<array<string, mixed>>): list<array<string, mixed>>  $change
     */
    private function write(Closure $change, ?string $focus = null, bool $remember = false): ?SavedStudioDiscussion
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return null;
        }

        $before = $this->savedRows($plan);
        [$data, $order] = self::data($change($before));

        try {
            $saved = app(SaveStudioDiscussionTreeAction::class)->handle($this->admin(), $plan, $data, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception);

            return null;
        } catch (ValidationException $exception) {
            $this->showErrors($exception, $order, $focus);

            return null;
        }

        if ($remember) {
            $this->remember($before);
        }

        $this->settle($saved);

        return $saved;
    }

    /**
     * Puts each message by the field of the dialog it is about, when it is
     * about the dialog's comment, and says the first other one in the dialog
     * or a toast.
     *
     * @param  list<string>  $order
     */
    private function showErrors(ValidationException $exception, array $order, ?string $focus): void
    {
        $fields = ['body' => 'dialogBody', 'personaUserId' => 'dialogPersona', 'offsetSeconds' => 'dialogSeconds', 'parentId' => 'dialogParent', 'parentKey' => 'dialogParent'];
        $other = null;

        foreach ($exception->errors() as $field => $messages) {
            $message = (string) ($messages[0] ?? '');

            if ($focus !== null && preg_match('/^nodes\.(\d+)\.(\w+)$/', $field, $match) === 1 && ($order[(int) $match[1]] ?? null) === $focus && isset($fields[$match[2]])) {
                $this->addError($fields[$match[2]], $message);

                continue;
            }

            $other ??= $message;
        }

        if ($other === null) {
            return;
        }

        if ($focus !== null && $this->dialog !== null) {
            $this->addError('dialog', $other);

            return;
        }

        $this->toast($other);
    }

    /** Asks for a generation; says what stands in its way when something does. */
    private function request(StudioDiscussionGenerationData $data): ?StudioPlanDiscussionGeneration
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return null;
        }

        try {
            $generation = app(RequestStudioDiscussionGenerationAction::class)->handle($this->admin(), $plan, $data);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception);

            return null;
        } catch (CannotRequestStudioDiscussionGenerationException $exception) {
            $this->toast($exception->error->message());

            return null;
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first());

            return null;
        }

        $this->failure = null;

        return $generation;
    }

    /** A whole tree by the team the settings make: none planned empties the discussion, with no request at all. */
    private function startGeneration(): void
    {
        if ($this->totalCount === 0) {
            $this->write(fn (array $rows): array => []);

            return;
        }

        $team = $this->team(pick: true);

        if ($team === null) {
            return;
        }

        $generation = $this->request(new StudioDiscussionGenerationData(
            mode: StudioDiscussionGenerationMode::FullDiscussion,
            expectedLockVersion: $this->lockVersion,
            personaUserIds: $team,
            rootCount: $this->rootCount,
            replyCount: $this->totalCount - $this->rootCount,
            tone: StudioPostTextTone::DEFAULT,
        ));

        if ($generation !== null) {
            $this->awaiting = (int) $generation->id;
            $this->rewriting = null;
        }
    }

    /**
     * Who writes a whole tree: the Personas checked, or as many picked at
     * random as asked for — picked only when the tree is generated (`pick`),
     * never when it is only checked whether it can be.
     *
     * @return list<int>|null
     */
    private function team(bool $pick): ?array
    {
        $this->resetErrorBag('participants');

        if ($this->participantMode === 'manual') {
            $team = array_values(array_filter($this->manual, fn (int $id): bool => app(PersonasQuery::class)->choosable($id) !== null));

            if ($team === []) {
                $this->addError('participants', 'Select at least one persona.');

                return null;
            }

            return $team;
        }

        $this->updatedRandomCount();
        $available = app(PersonasQuery::class)->choosableCount();

        if ($available < $this->randomCount) {
            $this->addError('participants', $available === 0
                ? 'There are no personas to pick from. Create a persona first.'
                : "Only {$available} ".($available === 1 ? 'persona is' : 'personas are').' available. Pick fewer.');

            return null;
        }

        return $pick
            ? app(PersonasQuery::class)->randomChoosable($this->randomCount)->map(fn (User $persona): int => (int) $persona->id)->values()->all()
            : [];
    }

    /** Whether a generation may be asked for now: a draft this page may write, the post saved, and none on its way. */
    private function readyToGenerate(mixed $postChanges): bool
    {
        if ($this->writablePlan() === null) {
            return false;
        }

        if (is_numeric($postChanges) && (int) $postChanges > 0) {
            $this->toast('Save the post first: the discussion is written from the saved title and description.');

            return false;
        }

        if ($this->awaiting !== null) {
            $this->toast('A generation is already on its way. Wait for it before asking for another.');

            return false;
        }

        return true;
    }

    /** A generation that settled without anything taken in: said over the tree, or of a comment in a toast. */
    private function failed(string $message, ?int $rewriting): void
    {
        if ($rewriting !== null) {
            $this->toast("The comment wasn’t written again: {$message}");

            return;
        }

        $this->failure = $message;
    }

    /** Says why nothing was written. */
    private function refuse(CannotManageStudioPlanException $exception): void
    {
        match (true) {
            $exception->isStale() => $this->conflict = true,
            $exception->isGone() => $this->gone = true,
            $exception->isNotDraft() => $this->closed = true,
            default => null,
        };

        $this->dialog = null;
        $this->toast(match (true) {
            $exception->isStale() => 'Someone changed this draft after you opened it, so nothing was saved. Reload to see their version.',
            $exception->isGone() => 'This draft was deleted, so nothing was saved.',
            $exception->isNotDraft() => 'This plan is no longer a draft, so nothing was saved.',
            default => 'You can’t manage publication plans any more, so nothing was saved.',
        });
    }

    /** @param list<array<string, mixed>> $before */
    private function remember(array $before): void
    {
        $this->undo = ['token' => Str::random(16), 'tree' => $before];
    }

    /** @param array{mode: string, id: int|null, parentId: int|null} $dialog */
    private function openDialog(array $dialog, ?int $persona, string $body, int $seconds): void
    {
        $this->resetErrorBag();
        $this->dialog = $dialog;
        $this->dialogPersona = $persona;
        $this->dialogBody = $body;
        $this->dialogSeconds = min(StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS, max(0, $seconds));
        $this->dialogUnit = self::unitFor($this->dialogSeconds);
        $this->dialogSearch = '';
    }

    /**
     * The Personas a new comment is offered to first: the team of the current
     * tree, the ones checked, or any.
     *
     * @return list<int>
     */
    private function teamPersonas(): array
    {
        $plan = StudioPlan::query()->find($this->planId);
        $team = $plan !== null ? $this->pickedForTree($plan) : [];

        if ($team === [] && $this->participantMode === 'manual') {
            $team = $this->manual;
        }

        return $team !== [] ? $team : app(PersonasQuery::class)->choices('', 2)->map(fn (User $persona): int => (int) $persona->id)->values()->all();
    }

    /**
     * The Personas the current tree was written by — the last whole tree
     * taken in, while the discussion has comments — or the one on its way.
     *
     * @return list<int>
     */
    private function pickedForTree(StudioPlan $plan): array
    {
        $generation = StudioPlanDiscussionGeneration::query()
            ->where('studio_plan_id', $plan->id)
            ->where('mode', StudioDiscussionGenerationMode::FullDiscussion)
            ->where(fn ($query) => $query->whereNotNull('applied_at')->when($this->awaiting !== null, fn ($query) => $query->orWhere('id', $this->awaiting)))
            ->latest('id')
            ->first();

        if ($generation === null || ((int) $generation->id !== $this->awaiting && ! $plan->discussionNodes()->exists())) {
            return [];
        }

        return array_map('intval', $generation->persona_user_ids);
    }

    /** @return EloquentCollection<int, User> the Personas listed to check, matching the search */
    private function personaList(): EloquentCollection
    {
        return app(PersonasQuery::class)->choices(mb_substr(trim($this->participantSearch), 0, 100), self::PERSONA_LIST)->load('personaProfile');
    }

    /**
     * The discussion as saved, a row a comment.
     *
     * @return list<array<string, mixed>>
     */
    private function savedRows(StudioPlan $plan): array
    {
        return array_map(
            fn (StudioDiscussionNodeData $node): array => self::row((int) $node->id, $node->parentId, $node->personaUserId, $node->body, $node->offsetSeconds, sortOrder: $node->sortOrder),
            StudioDiscussionData::of($plan)->nodes,
        );
    }

    /** @return list<array<string, mixed>> */
    private function savedRowsNow(): array
    {
        $plan = StudioPlan::query()->find($this->planId);

        return $plan !== null ? $this->savedRows($plan) : [];
    }

    private function savedNode(int $id): ?StudioDiscussionNodeData
    {
        $plan = StudioPlan::query()->find($this->planId);

        return $plan !== null
            ? collect(StudioDiscussionData::of($plan)->nodes)->first(fn (StudioDiscussionNodeData $node): bool => (int) $node->id === $id)
            : null;
    }

    private function writablePlan(): ?StudioPlan
    {
        if (! $this->writable()) {
            return null;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone());
        }

        return $plan;
    }

    private function writable(): bool
    {
        return $this->planId !== 0 && ! $this->gone && ! $this->closed && ! $this->conflict;
    }

    /**
     * What a row of the tree shows.
     *
     * @param  array{row: array<string, mixed>, depth: int}  $entry
     * @param  array<int, string>  $labels
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>
     */
    private function displayRow(array $entry, array $labels, array $rows, Collection $people): array
    {
        $row = $entry['row'];
        $parent = $row['parentId'] !== null ? collect($rows)->firstWhere('id', $row['parentId']) : null;

        return [
            'id' => $row['id'],
            'label' => $labels[$row['id']] ?? '',
            'reply' => $parent !== null,
            'replyTo' => $parent !== null ? ($labels[$parent['id']] ?? '').' · '.self::nameOf($people, $parent['personaUserId']) : null,
            'persona' => StudioPlanFormat::author($people->get($row['personaUserId'])),
            'body' => $row['body'],
            'offset' => '+'.self::relative((int) $row['offsetSeconds']),
            'rewriting' => $this->rewriting === $row['id'],
        ];
    }

    /**
     * The Timing tab: every comment by its moment, what it comes after, and
     * its moment to change.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $labels
     * @param  Collection<int, User>  $people
     * @return list<array<string, mixed>>
     */
    private function timingRows(array $rows, array $labels, Collection $people): array
    {
        usort($rows, fn (array $a, array $b): int => [$a['offsetSeconds'], $a['parentId'] !== null, (int) substr($labels[$a['id']] ?? 'C0', 1)] <=> [$b['offsetSeconds'], $b['parentId'] !== null, (int) substr($labels[$b['id']] ?? 'C0', 1)]);
        $byId = collect($rows)->keyBy('id');

        return array_map(function (array $row) use ($labels, $byId, $people): array {
            $parent = $row['parentId'] !== null ? $byId->get($row['parentId']) : null;

            return [
                'id' => $row['id'],
                'reply' => $parent !== null,
                'action' => ($parent !== null ? 'Reply ' : 'Comment ').($labels[$row['id']] ?? ''),
                'who' => self::nameOf($people, $row['personaUserId']),
                'parent' => $parent !== null ? ($labels[$parent['id']] ?? '') : 'Post',
                'seconds' => (int) $row['offsetSeconds'],
                'unit' => self::unitFor((int) $row['offsetSeconds']),
                'dependency' => $parent !== null ? 'After '.($labels[$parent['id']] ?? '').' at +'.self::relative((int) $parent['offsetSeconds']) : 'After the post',
            ];
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $labels
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>
     */
    private function dialogData(array $rows, array $labels, Collection $people): array
    {
        $dialog = (array) $this->dialog;
        $byId = collect($rows)->keyBy('id');
        $parent = $dialog['parentId'] !== null ? $byId->get($dialog['parentId']) : null;
        $hasReplies = $dialog['id'] !== null && collect($rows)->contains(fn (array $row): bool => $row['parentId'] === $dialog['id']);
        $offered = app(PersonasQuery::class)->choices(mb_substr(trim($this->dialogSearch), 0, 100), self::DIALOG_PERSONAS);
        $chosen = $this->dialogPersona !== null ? $people->get($this->dialogPersona) : null;

        if ($chosen !== null && ! $offered->contains('id', $chosen->id)) {
            $offered->prepend($chosen);
        }

        $roots = array_values(array_filter(self::ordered($rows), fn (array $entry): bool => $entry['row']['parentId'] === null && $entry['row']['id'] !== $dialog['id']));

        return [
            'title' => match ($dialog['mode']) {
                'edit' => 'Edit '.($labels[$dialog['id']] ?? 'comment'),
                'reply' => 'Reply to '.($labels[$dialog['parentId']] ?? 'comment'),
                default => 'New top-level comment',
            },
            'personas' => $offered->map(fn (User $persona): array => [...(StudioPlanFormat::author($persona) ?? []), 'on' => (int) $persona->id === $this->dialogPersona])->values()->all(),
            'parentLabel' => $parent !== null
                ? ($labels[$parent['id']] ?? '').' · '.self::nameOf($people, $parent['personaUserId']).' · '.$parent['body']
                : 'No parent · top-level comment',
            'parentOffset' => $parent !== null ? (int) $parent['offsetSeconds'] : null,
            'parentRef' => $parent !== null ? ($labels[$parent['id']] ?? '') : null,
            'parents' => $hasReplies ? [] : array_map(fn (array $entry): array => [
                'id' => $entry['row']['id'],
                'label' => ($labels[$entry['row']['id']] ?? '').' · '.self::nameOf($people, $entry['row']['personaUserId']),
                'sub' => $entry['row']['body'],
                'on' => $entry['row']['id'] === $dialog['parentId'],
            ], $roots),
            'hasReplies' => $hasReplies,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{row: array<string, mixed>, depth: int}>  $ordered
     * @param  array<int, string>  $labels
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>|null
     */
    private function deletingData(array $rows, array $ordered, array $labels, Collection $people): ?array
    {
        $row = collect($rows)->firstWhere('id', $this->deleting);

        if ($row === null) {
            return null;
        }

        $branch = array_values(array_filter($ordered, fn (array $entry): bool => $entry['row']['id'] === $row['id'] || $entry['row']['parentId'] === $row['id']));

        return [
            'label' => $labels[$row['id']] ?? '',
            'replies' => count($branch) - 1,
            'body' => $row['body'],
            'meta' => self::nameOf($people, $row['personaUserId']).' · +'.self::relative((int) $row['offsetSeconds']),
            'labels' => implode(', ', array_map(fn (array $entry): string => $labels[$entry['row']['id']] ?? '', $branch)),
        ];
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function toast(string $message, string $tone = 'error', bool $undoable = false): void
    {
        if ($undoable && $this->undo !== null) {
            $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: ['label' => 'Undo', 'event' => 'undo-studio-discussion', 'params' => ['token' => $this->undo['token']]]);

            return;
        }

        $this->dispatch('rg-admin-toast', message: $message, tone: $tone);
    }

    private function mostNodes(): int
    {
        return max(1, min(app(StudioDiscussionGenerator::class)->maxNodes(), StudioDiscussionValidationRules::MOST_NODES));
    }

    private function mostPersonas(): int
    {
        return max(1, app(StudioDiscussionGenerator::class)->maxPersonas());
    }

    /** The most Personas a random team can have: as many as there are, up to the most that take part. */
    private function mostRandom(): int
    {
        return max(1, min($this->mostPersonas(), app(PersonasQuery::class)->choosableCount()));
    }

    /** @param Collection<int, User> $people */
    private static function nameOf(Collection $people, mixed $id): string
    {
        return StudioPlanFormat::author(is_numeric($id) ? $people->get((int) $id) : null)['name'] ?? 'A persona';
    }

    /**
     * One comment as this component handles it.
     *
     * @return array<string, mixed>
     */
    private static function row(?int $id, ?int $parentId, ?int $personaUserId, string $body, int $offsetSeconds, ?string $key = null, ?string $parentKey = null, int $sortOrder = 0): array
    {
        return ['id' => $id, 'key' => $key, 'parentId' => $parentId, 'parentKey' => $parentKey, 'personaUserId' => $personaUserId, 'body' => $body, 'offsetSeconds' => $offsetSeconds, 'sortOrder' => $sortOrder];
    }

    /**
     * The comments as the action takes them, each in its place among its
     * siblings by time, and which comment each index is: `n12` for the saved
     * comment 12, its key for a new one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: StudioDiscussionData, 1: list<string>}
     */
    private static function data(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $groups[$row['parentId'] ?? ($row['parentKey'] !== null ? 'k:'.$row['parentKey'] : 'post')][] = $index;
        }

        $places = [];

        foreach ($groups as $indexes) {
            usort($indexes, fn (int $a, int $b): int => [$rows[$a]['offsetSeconds'], $rows[$a]['sortOrder'], $rows[$a]['id'] ?? PHP_INT_MAX] <=> [$rows[$b]['offsetSeconds'], $rows[$b]['sortOrder'], $rows[$b]['id'] ?? PHP_INT_MAX]);

            foreach ($indexes as $place => $index) {
                $places[$index] = $place;
            }
        }

        $nodes = [];
        $order = [];

        foreach ($rows as $index => $row) {
            $order[] = $row['id'] !== null ? 'n'.$row['id'] : (string) $row['key'];
            $nodes[] = new StudioDiscussionNodeData(
                id: $row['id'],
                key: $row['id'] === null ? $row['key'] : null,
                parentId: $row['parentId'],
                parentKey: $row['parentId'] === null ? $row['parentKey'] : null,
                personaUserId: $row['personaUserId'],
                body: (string) $row['body'],
                sortOrder: $places[$index] ?? 0,
                offsetSeconds: (int) $row['offsetSeconds'],
            );
        }

        return [new StudioDiscussionData($nodes), $order];
    }

    /**
     * The discussion as it was, put back over the one there is now: a comment
     * still there keeps its id, one deleted since comes back as a new one.
     *
     * @param  list<array<string, mixed>>  $snapshot
     * @param  list<array<string, mixed>>  $current
     * @return list<array<string, mixed>>
     */
    private static function restored(array $snapshot, array $current): array
    {
        $there = array_flip(array_map(fn (array $row): int => (int) $row['id'], $current));

        return array_map(function (array $row) use ($there): array {
            $kept = isset($there[(int) $row['id']]);
            $parentKept = $row['parentId'] === null || isset($there[(int) $row['parentId']]);

            return [
                ...$row,
                'id' => $kept ? $row['id'] : null,
                'key' => $kept ? null : 'u'.$row['id'],
                'parentId' => $parentKept ? $row['parentId'] : null,
                'parentKey' => $parentKept ? null : 'u'.$row['parentId'],
            ];
        }, $snapshot);
    }

    /**
     * The comments in the order the tree shows them: each comment on the post
     * by its time, each followed by its replies by theirs.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row: array<string, mixed>, depth: int}>
     */
    private static function ordered(array $rows): array
    {
        $byTime = fn (array $a, array $b): int => [$a['offsetSeconds'], $a['sortOrder'], $a['id']] <=> [$b['offsetSeconds'], $b['sortOrder'], $b['id']];
        $roots = array_values(array_filter($rows, fn (array $row): bool => $row['parentId'] === null));
        usort($roots, $byTime);
        $ordered = [];

        foreach ($roots as $root) {
            $ordered[] = ['row' => $root, 'depth' => 0];
            $replies = array_values(array_filter($rows, fn (array $row): bool => $row['parentId'] === $root['id']));
            usort($replies, $byTime);

            foreach ($replies as $reply) {
                $ordered[] = ['row' => $reply, 'depth' => 1];
            }
        }

        return $ordered;
    }

    /**
     * Each comment's label in the order the tree shows them: C1, C2, …
     *
     * @param  list<array{row: array<string, mixed>, depth: int}>  $ordered
     * @return array<int, string>
     */
    private static function labels(array $ordered): array
    {
        $labels = [];

        foreach ($ordered as $index => $entry) {
            $labels[(int) $entry['row']['id']] = 'C'.($index + 1);
        }

        return $labels;
    }

    /** The unit a moment reads best in: whole days, whole hours, or minutes. */
    public static function unitFor(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 && $seconds % 86400 === 0 => 'd',
            $seconds >= 3600 && $seconds % 3600 === 0 => 'h',
            default => 'min',
        };
    }

    /** A time after the post as it is read: `8 min`, `2 h 15 min`, `1 d 3 h`. */
    public static function relative(int $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes < 1440) {
            return intdiv($minutes, 60).' h'.($minutes % 60 > 0 ? ' '.($minutes % 60).' min' : '');
        }

        return intdiv($minutes, 1440).' d'.(intdiv($minutes % 1440, 60) > 0 ? ' '.intdiv($minutes % 1440, 60).' h' : '');
    }
}
