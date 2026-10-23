<?php

namespace App\Livewire\Admin;

use App\Actions\Studio\SaveStudioVotesAction;
use App\Data\Studio\SavedStudioVotes;
use App\Data\Studio\StudioDiscussionVotesSettings;
use App\Data\Studio\StudioDiscussionVoteTargetSettings;
use App\Data\Studio\StudioVoteData;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioActionType;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Personas\PersonasQuery;
use App\Support\Studio\BuildStudioDiscussionVotesData;
use App\Support\Studio\StudioVoteValidationRules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Discussion step's votes on its comments (docs/architecture/studio-plans.md,
 * "The Comment votes editor"): the Comment voters — drawn at random from the
 * active Personas or chosen one by one — and the Comment votes: how many vote
 * each prepared comment and reply up and down, shared out automatically by
 * priority or set by hand, who casts which, and when, after each comment.
 * A comment's own Persona never votes on it, and votes on the others all the
 * same.
 *
 * What is set here is a recipe, never saved: the votes are made of it
 * (BuildStudioDiscussionVotesData) as soon as it asks for other votes than
 * the plan has saved, drawn once and shown as they will be saved — on the
 * discussion tree, each comment's counts, beside it the breakdown of the one
 * opened, and on its Timing tab every vote at its moment among the comments,
 * where a vote's own moment is set and a comment's is moved — and Save votes
 * saves exactly those, through SaveStudioVotesAction. The totals are always
 * the comments' votes added up: a total changed gives or takes the difference
 * by priority, a comment's own votes changed move it. The votes on the post and
 * its rating questions are kept as the plan has them at that moment; only the
 * votes on comments are replaced. Opened again, the editor draws the votes as
 * they are saved: their counts, their Personas, their times. Nothing is cast
 * — prepared votes are no votes on the site. Leaving the step with votes not
 * saved asks first, listing what changed: save them, discard them, or stay.
 *
 * One version for the whole plan, as with the other editors on the page: a
 * save here raises it and says so with `studio-plan-version-moved` from
 * `discussion_votes`, and a move said by another editor is taken only when
 * the plan is at that version now with this page's part of it as it was —
 * its comment votes the same, and the comments they are on too, unless the
 * move is the discussion's own, whose save may only take votes away with the
 * comments it deletes, or move them with the comments it moves. Votes made
 * before the comments changed are made again. Any other move is somebody
 * else's, and nothing here is written until Reload. Every request checks the
 * viewer may still manage plans.
 */
final class StudioPlanDiscussionVotesEditor extends Component
{
    /** What this editor's writes and Reloads are said from. */
    public const SOURCE = 'discussion_votes';

    /** When a comment's votes come by default: five minutes after it, spread over six hours. */
    private const TIMING = [300, 21600];

    /** How many voters a random draw starts with, at most. */
    private const DEFAULT_VOTERS = 20;

    /** The most Personas listed to choose from at once. */
    private const PERSONA_LIST = 50;

    /** Said by the discussion tree's See breakdown, with the comment to open beside it. */
    public const SELECT_EVENT = 'studio-comment-votes-select';

    /** Said by the step's Back, Continue and tabs while votes are not saved, with where to go. */
    public const LEAVE_EVENT = 'studio-comment-votes-leave';

    /**
     * Where what is asked of the comments to come — totals, timing, voters —
     * is kept in the session, by plan, while the plan has no comments: it
     * outlives the page, opened again or by a save of a new publication,
     * until the tree is written and takes it.
     */
    public const ASKED_SESSION = 'rg-admin-studio-comment-votes-asked';

    /** Said to the plan's editor once the votes are saved or discarded: go on where it was asked to. */
    public const STEP_EVENT = 'studio-plan-step';

    #[Locked]
    public int $planId = 0;

    /** The plan's lock_version as this page last read or wrote it. */
    #[Locked]
    public int $lockVersion = 0;

    /**
     * The comment votes as this page last read or wrote them, one value each.
     *
     * @var list<string>
     */
    #[Locked]
    public array $heldVotes = [];

    /** The comments those votes are on — their ids, places, Personas and moments — in one value. */
    #[Locked]
    public string $treeFingerprint = '';

    #[Locked]
    public bool $conflict = false;

    #[Locked]
    public bool $gone = false;

    #[Locked]
    public bool $closed = false;

    /** random: as many as voterCount drawn from the active Personas; manual: the ones chosen. */
    public string $voterMode = 'random';

    public int $voterCount = 0;

    /** @var list<int> */
    #[Locked]
    public array $voterIds = [];

    /** The search over the Personas listed to choose. */
    public string $voterSearch = '';

    /** The Voters tab's filter and search. */
    public string $votersFilter = 'all';

    public string $votersSearch = '';

    /**
     * The comments' up and down votes added up; a total changed gives or takes
     * the difference by priority. With no comments yet, what is asked of the
     * comments to come: shared out over them once the tree is written.
     */
    public int $totalUp = 0;

    public int $totalDown = 0;

    /**
     * When every comment's votes come, as last asked of them all: a comment
     * added since takes it, and with no comments yet the comments to come do.
     *
     * @var array{first: int, spread: int}
     */
    #[Locked]
    public array $timingAsked = ['first' => self::TIMING[0], 'spread' => self::TIMING[1]];

    /**
     * Each comment's votes: how many up and down, its priority for the
     * automatic share, and when, after the comment — spread from the first
     * moment over the spread, but a Persona's vote set to a moment of its own
     * (`moments`, by Persona) then.
     *
     * @var array<int, array{up: int, down: int, priority: string, first: int, spread: int, moments: array<int, int>}>
     */
    #[Locked]
    public array $targets = [];

    #[Locked]
    public ?int $seed = null;

    /** Whether the saved votes are preferred: until a new draw is asked for. */
    #[Locked]
    public bool $keepSaved = true;

    /**
     * The votes the settings make while they are not the saved votes', exactly
     * as Save votes saves them.
     *
     * @var list<array<string, mixed>>|null
     */
    #[Locked]
    public ?array $preview = null;

    /**
     * The Personas the preview's draw took part, voting or not.
     *
     * @var list<int>
     */
    #[Locked]
    public array $drawn = [];

    /** What the preview was made from (previewKey()). */
    #[Locked]
    public string $previewFor = '';

    /** The settings as drawn from the saved votes (settingsKey()). */
    #[Locked]
    public string $restoredFor = '';

    /** The comment whose breakdown is open beside the discussion tree. */
    #[Locked]
    public ?int $selected = null;

    /** Whether the votes as set here are not the saved ones, and could be saved. */
    #[Locked]
    public bool $unsaved = false;

    /**
     * Each comment's votes as set here, for the discussion tree: how many up
     * and down, whether not saved yet, and what stands in the way.
     *
     * @var array<int, array{up: int, down: int, changed: bool, problem: string|null}>
     */
    #[Locked]
    public array $summary = [];

    /** The comment votes as they stand, in a few words, for the Discussion step's Generate strip. */
    #[Locked]
    public string $status = '';

    /** Where the step was asked to go while the votes are not saved: a step, `next` or `previous`. */
    #[Locked]
    public ?string $leaving = null;

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
    // Settings
    // ------------------------------------------------------------------

    public function updatedVoterCount(): void
    {
        $this->voterCount = self::count($this->voterCount);
    }

    public function updatedVoterMode(): void
    {
        $this->voterMode = $this->voterMode === 'manual' ? 'manual' : 'random';
    }

    public function updatedVotersFilter(): void
    {
        $this->votersFilter = in_array($this->votersFilter, ['all', 'voting', 'writers', 'idle'], true) ? $this->votersFilter : 'all';
    }

    /** Total up, changed: the votes it gained or lost given or taken at once, the rest kept. */
    public function updatedTotalUp(): void
    {
        $this->totalUp = self::count($this->totalUp);

        if ($this->targets !== []) {
            $this->adjust('up');
        }
    }

    public function updatedTotalDown(): void
    {
        $this->totalDown = self::count($this->totalDown);

        if ($this->targets !== []) {
            $this->adjust('down');
        }
    }

    /** Up or Down of one comment, set by hand: the totals follow. */
    public function setVotes(mixed $node, mixed $way, mixed $count): void
    {
        $node = (int) $node;

        if (isset($this->targets[$node]) && in_array($way, ['up', 'down'], true) && is_numeric($count)) {
            $this->targets[$node][$way] = self::count((int) $count);
            $this->tally();
        }
    }

    /** A comment's priority for the automatic share: low, normal or high. */
    public function setPriority(mixed $node, mixed $priority): void
    {
        $node = (int) $node;

        if (isset($this->targets[$node]) && array_key_exists((string) $priority, StudioDiscussionVoteTargetSettings::WEIGHTS)) {
            $this->targets[$node]['priority'] = (string) $priority;
        }
    }

    /**
     * Distribute automatically: both totals shared out again, after the
     * comments' priorities or their own votes were changed.
     */
    public function distribute(): void
    {
        if ($this->targets !== []) {
            $this->share('up');
            $this->share('down');
        }
    }

    /**
     * First vote after, and Spread over — after each comment — for one
     * comment, or for every comment: their votes spread again, none keeping a
     * moment of its own.
     */
    public function setTiming(mixed $field, mixed $seconds, mixed $node = null): void
    {
        if (! is_numeric($seconds) || ! in_array($field, ['first', 'spread'], true) || ($node !== null && ! isset($this->targets[(int) $node]))) {
            return;
        }

        $seconds = (int) $seconds;
        $current = array_column($this->targets, $field);
        $offered = array_keys($field === 'first' ? StudioPlanPostVotesEditor::FIRST_CHOICES : StudioPlanPostVotesEditor::SPREAD_CHOICES);

        if (in_array($seconds, [...$offered, ...$current], true)) {
            foreach ($node !== null ? [(int) $node] : array_keys($this->targets) as $target) {
                $this->targets[$target][$field] = $seconds;
                $this->targets[$target]['moments'] = [];
            }

            if ($node === null) {
                $this->timingAsked[$field] = $seconds;
            }
        }
    }

    /**
     * A vote's own moment, set on the Timing tab in seconds after the post:
     * the Persona's vote on that comment comes then, the others keeping
     * theirs. Never before the comment, nor later than a vote may come.
     */
    public function setMoment(mixed $node, mixed $persona, mixed $seconds): void
    {
        $plan = $this->writablePlan();

        if ($plan === null || ! is_numeric($node) || ! is_numeric($persona) || ! is_numeric($seconds) || ! isset($this->targets[(int) $node])) {
            return;
        }

        [$node, $persona, $seconds] = [(int) $node, (int) $persona, (int) round((float) $seconds)];
        $comment = StudioDiscussionNode::query()->where('studio_plan_id', $plan->id)->find($node);
        $shown = $this->drafted() ? ($this->preview ?? []) : self::commentVotesOf($plan);

        if ($comment === null || ! in_array($persona, array_column(self::on($shown, $node), 'personaUserId'), true)) {
            return;
        }

        if ($seconds < (int) $comment->offset_seconds) {
            $this->toast('No vote can come before the comment it is on: '.$this->labelOf($node).' comes at +'.StudioPlanDiscussionEditor::relative((int) $comment->offset_seconds).'.');

            return;
        }

        if ($seconds > StudioVoteValidationRules::LATEST_OFFSET_SECONDS) {
            $this->toast('A vote comes at most 30 days after the post.');

            return;
        }

        $this->targets[$node]['moments'][$persona] = $seconds - (int) $comment->offset_seconds;
        ksort($this->targets[$node]['moments']);
    }

    /** See breakdown: the comment's votes opened beside the tree, or closed when they are open already. */
    #[On(self::SELECT_EVENT)]
    public function select(mixed $node): void
    {
        $this->selected = is_numeric($node) && isset($this->targets[(int) $node]) && $this->selected !== (int) $node ? (int) $node : null;
    }

    public function closeBreakdown(): void
    {
        $this->selected = null;
    }

    /** Chooses a Persona that may vote now, or takes one chosen away, whatever its status now. */
    public function toggleVoter(mixed $id): void
    {
        if (! is_numeric($id)) {
            return;
        }

        $id = (int) $id;

        if (in_array($id, $this->voterIds, true)) {
            $this->voterIds = array_values(array_diff($this->voterIds, [$id]));

            return;
        }

        if (app(PersonasQuery::class)->active($id) !== null) {
            $this->voterIds[] = $id;
        }
    }

    /** Chooses every Persona the search shows, or takes them away when all are chosen. */
    public function selectShown(): void
    {
        $shown = $this->personaList()->map(fn (User $persona): int => (int) $persona->id)->values()->all();

        $this->voterIds = array_diff($shown, $this->voterIds) === []
            ? array_values(array_diff($this->voterIds, $shown))
            : array_values(array_unique([...$this->voterIds, ...$shown]));
    }

    public function clearVoters(): void
    {
        $this->voterIds = [];
    }

    // ------------------------------------------------------------------
    // Preview, Save and Remove
    // ------------------------------------------------------------------

    /** Pick others, or Reshuffle: a new draw, with the same numbers, the saved votes no longer preferred. */
    public function redraw(): void
    {
        if ($this->writable()) {
            $this->seed = random_int(1, 2_147_483_647);
            $this->keepSaved = false;
        }
    }

    /**
     * Save votes: exactly the votes shown, with the votes on the post and its
     * questions as the plan has them now, through the one action that writes
     * a plan's votes. Votes that are the saved ones again write nothing.
     */
    public function save(): void
    {
        if (! $this->writable()) {
            return;
        }

        $this->sync();
        $plan = StudioPlan::query()->find($this->planId);

        if (! $this->drafted() || ($plan !== null && $plan->lock_version === $this->lockVersion && $this->preview !== null && self::comparable($this->preview) === self::comparable(self::commentVotesOf($plan)))) {
            $this->load();
            $this->toast('Nothing to save: these are the votes saved.', 'info');

            return;
        }

        if ($this->preview === null) {
            $this->toast('These votes can’t be saved yet: fix what is marked on the comments first.');

            return;
        }

        $this->write(array_map(fn (array $vote): StudioVoteData => new StudioVoteData(...$vote), $this->preview), 'Comment votes saved');
    }

    /** Discard: the settings as the saved votes have them, the votes made of others gone. */
    public function discard(): void
    {
        $this->load();
    }

    /**
     * The step's Back, Continue or one of its tabs, asked while the votes are
     * not saved: whether to save them first, discard them, or stay. Asked with
     * nothing unsaved, it goes on at once.
     */
    #[On(self::LEAVE_EVENT)]
    public function askLeave(mixed $step): void
    {
        if (! is_string($step) || ! in_array($step, ['next', 'previous', ...array_keys(EditStudioPlan::STEPS)], true)) {
            return;
        }

        if ($this->pending()) {
            $this->leaving = $step;

            return;
        }

        $this->dispatch(self::STEP_EVENT, step: $step);
    }

    public function stay(): void
    {
        $this->leaving = null;
    }

    /** Save and continue: on to where it was asked once the votes are saved; nowhere when they are not. */
    public function saveAndLeave(): void
    {
        $to = $this->leaving;
        $this->leaving = null;

        if ($to === null) {
            return;
        }

        $this->save();

        // Saved, or nothing to save: the settings are the saved votes' again.
        if (! $this->drafted()) {
            $this->dispatch(self::STEP_EVENT, step: $to);
        }
    }

    /** Discard changes: the votes as saved, and on to where it was asked. */
    public function discardAndLeave(): void
    {
        $to = $this->leaving;
        $this->leaving = null;

        if ($to !== null) {
            $this->discard();
            $this->dispatch(self::STEP_EVENT, step: $to);
        }
    }

    // ------------------------------------------------------------------
    // The plan's one version, shared with the other editors on the page
    // ------------------------------------------------------------------

    /** Reload votes: the comment votes as saved now, said to the other editors on the page. */
    #[On('reload-studio-discussion-votes')]
    public function reload(): void
    {
        $this->load();

        if (! $this->gone) {
            $this->dispatch(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->planId, current: $this->lockVersion, source: self::SOURCE);
        }
    }

    /**
     * Another editor on the page drew the plan again at the version it says:
     * taken when this page's part of the plan is as it holds it, the preview
     * kept; otherwise the votes are drawn again from what is saved — unless
     * votes here are not saved, which stay, the conflict said, until Reload
     * votes.
     */
    #[On(StudioPlanDiscussionEditor::RELOAD_EVENT)]
    public function followReload(mixed $planId, mixed $current, mixed $source = null): void
    {
        if ($source === self::SOURCE || ! is_numeric($planId) || (int) $planId !== $this->planId || $this->planId === 0 || ! is_numeric($current)) {
            return;
        }

        if ((int) $current === $this->lockVersion && ! $this->conflict && ! $this->gone) {
            return;
        }

        if ($this->holdsAt((int) $current, null)) {
            $this->take((int) $current);

            return;
        }

        // Votes not saved are never thrown away for another editor's Reload: the notice here asks first.
        if ($this->drafted() && ! $this->gone) {
            $this->conflict = true;

            return;
        }

        $this->load();
    }

    /**
     * Another editor on the page moved the plan's version with a write of its
     * own: taken when it was written from the version this page holds and the
     * plan is at the version it says now, with this page's comment votes as it
     * holds them — or, for the discussion's own save, with no vote but those of
     * the comments it deleted gone. Any other move is somebody else's.
     */
    #[On(StudioPlanDiscussionEditor::VERSION_EVENT)]
    public function followVersion(mixed $planId, mixed $previous, mixed $current, mixed $source = null): void
    {
        if ($source === self::SOURCE || ! is_numeric($planId) || (int) $planId !== $this->planId || ! is_numeric($previous) || ! is_numeric($current)) {
            return;
        }

        [$previous, $current] = [(int) $previous, (int) $current];

        if ($current === $this->lockVersion) {
            return;
        }

        if ($previous === $this->lockVersion && $current === $previous + 1 && $this->holdsAt($current, is_string($source) ? $source : null)) {
            $this->take($current);

            return;
        }

        $this->conflict = true;
    }

    public function render(): View
    {
        $this->sync();
        $this->rememberAsked();
        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;
        $nodes = $plan !== null ? $this->orderedNodes() : [];
        $rows = array_map(fn (StudioDiscussionNode $node): array => self::nodeRow($node), $nodes);
        $labels = StudioPlanDiscussionEditor::labels(StudioPlanDiscussionEditor::ordered($rows));
        $saved = $plan !== null ? self::commentVotesOf($plan) : [];
        $drafted = $this->drafted();
        // The votes as they stand: the ones made of the settings while those are changed, the saved ones otherwise.
        $shown = $drafted ? ($this->preview ?? []) : $saved;
        $voterIds = match (true) {
            $this->voterMode === 'manual' => $this->voterIds,
            $this->preview !== null => $this->drawn,
            default => array_values(array_unique(array_column($saved, 'personaUserId'))),
        };
        $people = User::query()
            ->whereKey(array_values(array_unique([...$voterIds, ...array_column($saved, 'personaUserId'), ...array_column($shown, 'personaUserId'), ...array_map(fn (StudioDiscussionNode $node): int => $node->persona_user_id, $nodes)])))
            ->with('avatarAsset.variants')
            ->get()
            ->keyBy('id');
        $active = app(PersonasQuery::class)->activeIds();
        $cap = count($active);
        $eligible = array_values(array_intersect($this->voterIds, $active));
        $voters = $this->voterMode === 'manual' ? count($eligible) : min($this->voterCount, $cap);
        $problems = $plan !== null && ! $drafted ? $this->readiness($plan) : [];
        $wrote = [];

        foreach ($nodes as $node) {
            $wrote[$node->persona_user_id][] = $labels[(int) $node->id] ?? '';
        }

        $this->selected = $this->selected !== null && isset($this->targets[$this->selected]) ? $this->selected : null;
        $this->unsaved = $this->pending($saved);
        $this->summary = $this->summaryOf($nodes, $saved, $eligible, $problems);
        $chosenNode = collect($nodes)->first(fn (StudioDiscussionNode $node): bool => (int) $node->id === $this->selected);
        $marked = array_map(fn (int $node): string => $labels[$node] ?? '', array_keys(array_filter($this->summary, fn (array $entry): bool => $entry['problem'] !== null)));
        $this->status = match (true) {
            $this->unsaved && $marked !== [] => 'comment votes: fix what is marked on '.implode(', ', $marked),
            $marked !== [] => self::tallied($this->totalUp, $this->totalDown).' · see what is marked on '.implode(', ', $marked),
            default => self::tallied($this->totalUp, $this->totalDown),
        };

        return view('livewire.admin.studio-plan-discussion-votes-editor', [
            'editable' => $this->writable(),
            'cap' => $cap,
            'voters' => $voters,
            'commentCount' => count($nodes),
            'voted' => count(array_filter($this->targets, fn (array $target): bool => $target['up'] + $target['down'] > 0)),
            'marked' => $marked,
            'listed' => $this->voterMode === 'manual' ? $this->personaList()->map(fn (User $persona): array => [
                ...(StudioPlanFormat::author($persona) ?? []),
                'short' => Str::limit((string) ($persona->personaProfile->short_description ?? ''), 90),
                'on' => in_array((int) $persona->id, $this->voterIds, true),
                'writes' => $wrote[(int) $persona->id] ?? [],
            ])->values()->all() : [],
            'chosen' => array_map(fn (int $id): array => StudioPlanFormat::author($people->get($id)) ?? ['id' => $id, 'name' => 'Deleted user', 'username' => null, 'avatar' => null, 'initials' => '', 'status' => null, 'problem' => 'deleted'], $this->voterMode === 'manual' ? $this->voterIds : []),
            'voterTable' => $this->voterTable($shown, $voterIds, $people, $labels, $wrote),
            'chips' => [
                'first' => self::chips(StudioPlanPostVotesEditor::FIRST_CHOICES, $this->targets !== [] ? array_values(array_unique(array_column($this->targets, 'first'))) : [$this->timingAsked['first']]),
                'spread' => self::chips(StudioPlanPostVotesEditor::SPREAD_CHOICES, $this->targets !== [] ? array_values(array_unique(array_column($this->targets, 'spread'))) : [$this->timingAsked['spread']]),
            ],
            'breakdown' => $chosenNode !== null ? $this->breakdown($chosenNode, $shown, $people, $labels, $nodes, $problems) : null,
            'timing' => $this->timing($nodes, $shown, $saved, $people, $labels, $problems),
            'moving' => $plan !== null && ! $this->gone && ! $this->closed,
            'changes' => $this->leaving !== null ? $this->changes($nodes, $saved, $labels) : [],
        ]);
    }

    /**
     * Comment votes counted, as the Generate strip says them after the
     * discussion's own numbers: how many, and how many up and down.
     */
    public static function tallied(int $up, int $down): string
    {
        return $up + $down === 0
            ? 'no comment votes'
            : ($up + $down).' comment '.($up + $down === 1 ? 'vote' : 'votes').' (+'.$up.' / −'.$down.')';
    }

    // ------------------------------------------------------------------

    /**
     * Reads the plan as it is now — its version, whether it can be written,
     * its comments and the votes on them — and the settings from the saved
     * votes: each comment's up and down votes and their times after it, and
     * their Personas, as chosen by hand. A plan with none starts from a random
     * draw; every comment's priority starts Normal, priorities being no part
     * of a vote.
     */
    private function load(): void
    {
        $plan = StudioPlan::query()->find($this->planId);
        $this->resetErrorBag();
        $this->conflict = false;
        $this->preview = null;
        $this->drawn = [];
        $this->previewFor = '';
        $this->seed = null;
        $this->keepSaved = true;

        if ($plan === null) {
            $this->gone = true;

            return;
        }

        $this->lockVersion = $plan->lock_version;
        $this->closed = ! $plan->isDraft();
        $saved = self::commentVotesOf($plan);
        $nodes = $this->orderedNodes();
        $this->heldVotes = self::hashes($saved, $nodes);
        $this->treeFingerprint = self::treeOf($nodes);
        $this->targets = [];

        foreach ($nodes as $node) {
            $votes = array_values(array_filter($saved, fn (array $vote): bool => $vote['discussionNodeId'] === (int) $node->id));
            $up = count(array_filter($votes, fn (array $vote): bool => $vote['voteType'] === 'up'));
            // Each Persona's vote by its time after the comment, in the order they come.
            $offsets = array_column(array_map(fn (array $vote): array => [(int) $vote['personaUserId'], (int) $vote['offsetSeconds'] - (int) $node->offset_seconds], $votes), 1, 0);
            uksort($offsets, fn (int $a, int $b): int => [$offsets[$a], $a] <=> [$offsets[$b], $b]);
            $first = $offsets !== [] ? min($offsets) : self::TIMING[0];
            $spread = count($offsets) > 1 ? max($offsets) - min($offsets) : self::TIMING[1];

            $this->targets[(int) $node->id] = [
                'up' => $up,
                'down' => count($votes) - $up,
                'priority' => 'normal',
                'first' => $first,
                'spread' => $spread,
                // A vote away from its place in the spread came at a moment of its own.
                'moments' => array_filter(
                    $offsets,
                    fn (int $after, int $persona): bool => $after !== $first + (count($offsets) > 1 ? intdiv((int) array_search($persona, array_keys($offsets), true) * $spread, count($offsets) - 1) : 0),
                    ARRAY_FILTER_USE_BOTH,
                ),
            ];
            ksort($this->targets[(int) $node->id]['moments']);
        }

        $this->tally();
        $voted = array_filter($this->targets, fn (array $target): bool => $target['up'] + $target['down'] > 0);

        foreach (['first' => self::TIMING[0], 'spread' => self::TIMING[1]] as $field => $default) {
            $values = array_values(array_unique(array_column($voted, $field)));
            $this->timingAsked[$field] = count($values) === 1 ? (int) $values[0] : $default;
        }

        $voters = array_values(array_unique(array_column($saved, 'personaUserId')));
        $this->voterMode = $voters !== [] ? 'manual' : 'random';
        $this->voterIds = $voters;
        $this->voterCount = $voters !== [] ? count($voters) : min(self::DEFAULT_VOTERS, count(app(PersonasQuery::class)->activeIds()));
        $this->restoreAsked();
        $this->restoredFor = $this->settingsKey();
    }

    /**
     * With no comments yet, what was asked of the comments to come, as the
     * session keeps it for this plan (ASKED_SESSION).
     */
    private function restoreAsked(): void
    {
        $asked = $this->targets === [] ? session(self::ASKED_SESSION.'.'.$this->planId) : null;

        if (! is_array($asked)) {
            return;
        }

        $this->totalUp = self::count((int) ($asked['up'] ?? 0));
        $this->totalDown = self::count((int) ($asked['down'] ?? 0));

        foreach (['first', 'spread'] as $field) {
            if (is_int($asked['timing'][$field] ?? null)) {
                $this->timingAsked[$field] = $asked['timing'][$field];
            }
        }

        if (in_array($asked['mode'] ?? null, ['random', 'manual'], true)) {
            $this->voterMode = $asked['mode'];
            $this->voterIds = array_values(array_filter(array_map('intval', (array) ($asked['ids'] ?? [])), fn (int $id): bool => $id > 0));
            $this->voterCount = self::count((int) ($asked['count'] ?? $this->voterCount));
        }
    }

    /** What is asked of the comments to come kept in the session while there are none; forgotten once there are. */
    private function rememberAsked(): void
    {
        if ($this->planId === 0 || $this->gone || $this->closed) {
            return;
        }

        $key = self::ASKED_SESSION.'.'.$this->planId;

        if ($this->targets !== [] || $this->totalUp + $this->totalDown === 0) {
            session()->forget($key);

            return;
        }

        session()->put($key, [
            'up' => $this->totalUp, 'down' => $this->totalDown, 'timing' => $this->timingAsked,
            'mode' => $this->voterMode, 'ids' => $this->voterIds, 'count' => $this->voterCount,
        ]);
    }

    /**
     * Takes a version another editor's write left. Its comments as the plan
     * has them now — a new one asked about with no votes, a deleted one gone —
     * and the settings drawn again from the saved votes when nothing here was
     * changed yet; votes made before the comments changed are made again. A
     * discussion written anew as a whole keeps what was asked of its votes
     * (carryOver()).
     */
    private function take(int $version): void
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->gone = true;

            return;
        }

        $untouched = ! $this->drafted();
        $this->lockVersion = $version;
        $this->conflict = false;
        $kept = array_map(fn (StudioDiscussionNode $node): int => (int) $node->id, $this->orderedNodes());

        // The tree written anew as a whole, or written at last with totals asked of it already.
        $anew = $this->targets !== [] && array_intersect_key($this->targets, array_flip($kept)) === [];
        $first = $this->targets === [] && $this->totalUp + $this->totalDown > 0;

        if ($kept !== [] && ($anew || $first)) {
            $this->carryOver($version);

            return;
        }

        if ($untouched) {
            $this->load();
            $this->lockVersion = $version;

            return;
        }

        $nodes = $this->orderedNodes();
        $this->heldVotes = self::hashes(self::commentVotesOf($plan), $nodes);
        $this->treeFingerprint = self::treeOf($nodes);
        $targets = [];

        foreach ($nodes as $node) {
            $targets[(int) $node->id] = $this->targets[(int) $node->id] ?? ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => $this->timingAsked['first'], 'spread' => $this->timingAsked['spread'], 'moments' => []];
        }

        $this->targets = $targets;
        $this->tally();
    }

    /**
     * The whole discussion written anew — generated again, say — while votes
     * were asked of the old one, which took its votes with it, or written at
     * last while totals were asked of the comments to come: the same voters,
     * the same totals shared out over the new comments by priority, and the
     * timing every voted comment had — or the one asked — as votes not saved
     * yet.
     */
    private function carryOver(int $version): void
    {
        [$mode, $ids, $count, $up, $down, $asked] = [$this->voterMode, $this->voterIds, $this->voterCount, $this->totalUp, $this->totalDown, $this->timingAsked];
        $voted = array_filter($this->targets, fn (array $target): bool => $target['up'] + $target['down'] > 0) ?: $this->targets;
        $timing = $voted !== []
            ? ['first' => array_unique(array_column($voted, 'first')), 'spread' => array_unique(array_column($voted, 'spread'))]
            : ['first' => [$asked['first']], 'spread' => [$asked['spread']]];

        $this->load();
        $this->lockVersion = $version;
        [$this->voterMode, $this->voterIds, $this->voterCount, $this->timingAsked] = [$mode, $ids, $count, $asked];

        foreach (array_keys($this->targets) as $node) {
            foreach ($timing as $field => $values) {
                if (count($values) === 1) {
                    $this->targets[$node][$field] = (int) reset($values);
                }
            }
        }

        // Each total shared in turn: a share adds the totals up again from the comments.
        $this->totalUp = $up;
        $this->share('up');
        $this->totalDown = $down;
        $this->share('down');
    }

    /**
     * One way's total shared out among the comments by their priorities —
     * Low 1, Normal 2, High 3 — the remainders to the largest fractions, then
     * in the tree's order. Nothing is saved; each comment can be set by hand
     * afterwards.
     *
     * @param  'up'|'down'  $way
     */
    private function share(string $way): void
    {
        $priorities = $this->priorities();
        $shares = BuildStudioDiscussionVotesData::distribute($priorities, $way === 'up' ? $this->totalUp : $this->totalDown);

        foreach (array_keys($priorities) as $node) {
            $this->targets[$node][$way] = $shares[$node] ?? 0;
        }

        $this->tally();
    }

    /**
     * One way's total changed: each vote it gained given, one at a time, to
     * the comment furthest below its share of the new total by priority —
     * equal ones first in the tree — and each it lost taken from the one
     * furthest above, equal ones last in the tree. Every other comment keeps
     * its votes as set.
     *
     * @param  'up'|'down'  $way
     */
    private function adjust(string $way): void
    {
        $priorities = $this->priorities();
        $total = $way === 'up' ? $this->totalUp : $this->totalDown;
        $shares = BuildStudioDiscussionVotesData::distribute($priorities, $total);
        $left = $total - array_sum(array_map(fn (int $node): int => $this->targets[$node][$way], array_keys($priorities)));

        while ($left !== 0) {
            $step = $left > 0 ? 1 : -1;
            [$pick, $furthest] = [null, null];

            foreach (array_keys($priorities) as $node) {
                $gap = $step * (($shares[$node] ?? 0) - $this->targets[$node][$way]);

                if (($step > 0 || $this->targets[$node][$way] > 0) && ($furthest === null || $gap > $furthest || ($step < 0 && $gap === $furthest))) {
                    [$pick, $furthest] = [$node, $gap];
                }
            }

            if ($pick === null) {
                break;
            }

            $this->targets[$pick][$way] += $step;
            $left -= $step;
        }

        $this->tally();
    }

    /**
     * The comments' priorities, in the tree's order.
     *
     * @return array<int, string>
     */
    private function priorities(): array
    {
        $priorities = [];

        foreach ($this->orderedNodes() as $node) {
            if (isset($this->targets[(int) $node->id])) {
                $priorities[(int) $node->id] = $this->targets[(int) $node->id]['priority'];
            }
        }

        return $priorities;
    }

    /** The totals as the comments' votes add up. */
    private function tally(): void
    {
        $this->totalUp = array_sum(array_column($this->targets, 'up'));
        $this->totalDown = array_sum(array_column($this->targets, 'down'));
    }

    /**
     * Whether the plan is at this version now with this page's part of it as
     * it holds it: the comment votes the same, and the comments too — or, after
     * the discussion's own save, which may only take away the votes of the
     * comments it deletes and move those of the ones it moves, no vote added
     * or changed but for its moment, with its comment's.
     */
    private function holdsAt(int $version, ?string $source): bool
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null || $plan->lock_version !== $version) {
            return false;
        }

        $nodes = $this->orderedNodes();
        $votes = self::hashes(self::commentVotesOf($plan), $nodes);

        if ($source === 'discussion') {
            return array_diff($votes, $this->heldVotes) === [];
        }

        return $votes === $this->heldVotes && self::treeOf($nodes) === $this->treeFingerprint;
    }

    /**
     * Writes the comment votes as given, the votes on the post and its
     * questions as the plan has them now, and takes the version the save left.
     *
     * @param  list<StudioVoteData>  $commentVotes
     */
    private function write(array $commentVotes, string $done): void
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return;
        }

        // Read at the version the save is checked against, and kept as they are.
        $others = array_values(array_filter(
            StudioVotesData::of($plan)->votes,
            fn (StudioVoteData $vote): bool => $vote->type !== StudioActionType::VoteComment->value,
        ));

        try {
            $saved = app(SaveStudioVotesAction::class)->handle($this->admin(), $plan, new StudioVotesData([...$others, ...$commentVotes]), $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception);

            return;
        } catch (ValidationException $exception) {
            $this->toast(self::obstacle($exception, count($others)));

            return;
        }

        $this->settle($saved);
        $this->toast($done, 'success');
    }

    /** Takes the version a save left, says it to the other editors on the page, and draws the votes as saved. */
    private function settle(SavedStudioVotes $saved): void
    {
        $previous = $this->lockVersion;
        $this->load();
        $this->lockVersion = $saved->lockVersion;

        $this->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->planId, previous: $previous, current: $this->lockVersion, source: self::SOURCE);
    }

    /**
     * The votes follow the settings: made again whenever the settings, the
     * draw or the comments are other than the votes were made of, and none
     * while the settings are the saved votes' and no new draw is asked.
     */
    private function sync(): void
    {
        if (! $this->drafted()) {
            if ($this->previewFor !== '') {
                $this->resetErrorBag();
            }

            $this->preview = null;
            $this->drawn = [];
            $this->previewFor = '';

            return;
        }

        if ($this->writable() && $this->previewFor !== $this->previewKey()) {
            $this->seed ??= random_int(1, 2_147_483_647);
            $this->makePreview();
        }
    }

    /** Whether the settings ask for other votes than the saved ones: changed, or drawn anew. */
    private function drafted(): bool
    {
        return $this->settingsKey() !== $this->restoredFor || ! $this->keepSaved;
    }

    /**
     * Whether there are votes here to save or discard: the settings changed
     * and the votes they make — or would make, once what is marked is fixed —
     * not the saved ones.
     *
     * @param  list<array<string, mixed>>|null  $saved  the comment votes as saved, read when not given
     */
    private function pending(?array $saved = null): bool
    {
        if (! $this->writable() || ! $this->drafted()) {
            return false;
        }

        if ($this->preview === null) {
            return true;
        }

        $plan = $saved === null ? StudioPlan::query()->find($this->planId) : null;

        return self::comparable($this->preview) !== self::comparable($saved ?? ($plan !== null ? self::commentVotesOf($plan) : []));
    }

    private function makePreview(): void
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return;
        }

        $this->resetErrorBag();
        $this->preview = null;
        $this->drawn = [];
        $this->previewFor = $this->previewKey();

        try {
            $built = app(BuildStudioDiscussionVotesData::class)->handle($plan, $this->settings());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, (string) ($messages[0] ?? ''));
            }

            return;
        }

        $this->preview = array_map(fn (StudioVoteData $vote): array => $vote->toArray(), $built->votes->votes);
        $this->drawn = $built->voters;
    }

    /** The settings as BuildStudioDiscussionVotesData takes them. */
    private function settings(): StudioDiscussionVotesSettings
    {
        $targets = [];

        foreach ($this->targets as $node => $target) {
            $targets[(int) $node] = new StudioDiscussionVoteTargetSettings((int) $node, $target['up'], $target['down'], $target['priority'], $target['first'], $target['spread'], $target['moments']);
        }

        return new StudioDiscussionVotesSettings(
            voterMode: $this->voterMode,
            voterCount: $this->voterCount,
            voterIds: $this->voterIds,
            targets: $targets,
            seed: $this->seed ?? 1,
            keepSaved: $this->keepSaved,
        );
    }

    /** What the settings ask for, in one value; a comment's priority asks for nothing by itself. */
    private function settingsKey(): string
    {
        return hash('xxh3', json_encode([
            $this->voterMode, $this->voterMode === 'manual' ? $this->voterIds : $this->voterCount,
            array_map(fn (array $target): array => [$target['up'], $target['down'], $target['first'], $target['spread'], $target['moments']], $this->targets),
        ], JSON_THROW_ON_ERROR));
    }

    /** What a preview is made from: the settings, the draw, and the comments as they were. */
    private function previewKey(): string
    {
        return $this->settingsKey().'|'.($this->seed ?? 0).'|'.($this->keepSaved ? 1 : 0).'|'.$this->treeFingerprint;
    }

    /**
     * The plan's comments in the tree's order.
     *
     * @return list<StudioDiscussionNode>
     */
    private function orderedNodes(): array
    {
        $nodes = StudioDiscussionNode::query()->where('studio_plan_id', $this->planId)->get()->keyBy('id');

        return array_map(
            fn (array $entry): StudioDiscussionNode => $nodes->get((int) $entry['row']['id']),
            StudioPlanDiscussionEditor::ordered($nodes->map(fn (StudioDiscussionNode $node): array => self::nodeRow($node))->values()->all()),
        );
    }

    /** @return array<string, mixed> */
    private static function nodeRow(StudioDiscussionNode $node): array
    {
        return ['id' => (int) $node->id, 'parentId' => $node->parent_id, 'offsetSeconds' => (int) $node->offset_seconds, 'sortOrder' => (int) $node->sort_order];
    }

    /** @param  list<StudioDiscussionNode>  $nodes */
    private static function treeOf(array $nodes): string
    {
        return hash('xxh3', json_encode(array_map(fn (StudioDiscussionNode $node): array => [(int) $node->id, $node->parent_id, $node->persona_user_id, (int) $node->offset_seconds], $nodes), JSON_THROW_ON_ERROR));
    }

    /**
     * The votes on comments as the plan has them saved, in the order they are cast.
     *
     * @return list<array<string, mixed>>
     */
    private static function commentVotesOf(StudioPlan $plan): array
    {
        return array_values(array_map(
            fn (StudioVoteData $vote): array => $vote->toArray(),
            array_filter(StudioVotesData::of($plan)->votes, fn (StudioVoteData $vote): bool => $vote->type === StudioActionType::VoteComment->value),
        ));
    }

    /**
     * Each vote in one value, its moment counted from its comment's — the
     * same once the comment, moved, takes it along — the list sorted.
     *
     * @param  list<array<string, mixed>>  $votes
     * @param  list<StudioDiscussionNode>  $nodes
     * @return list<string>
     */
    private static function hashes(array $votes, array $nodes): array
    {
        $moments = [];

        foreach ($nodes as $node) {
            $moments[(int) $node->id] = (int) $node->offset_seconds;
        }

        $hashes = array_map(fn (array $vote): string => hash('xxh3', json_encode(
            [...$vote, 'offsetSeconds' => (int) $vote['offsetSeconds'] - ($moments[(int) $vote['discussionNodeId']] ?? 0)],
            JSON_THROW_ON_ERROR,
        )), $votes);
        sort($hashes);

        return $hashes;
    }

    /**
     * Votes as a save compares them: who, which way, on what, when, in what place, and which row.
     *
     * @param  list<array<string, mixed>>  $votes
     * @return list<array<int, mixed>>
     */
    private static function comparable(array $votes): array
    {
        $rows = array_map(fn (array $vote): array => [$vote['id'], $vote['personaUserId'], $vote['voteType'], $vote['discussionNodeId'], (int) $vote['offsetSeconds'], (int) $vote['sortOrder']], $votes);
        sort($rows);

        return $rows;
    }

    /** What stands in the way of a save, said with whose vote it is when it is not a comment's. */
    private static function obstacle(ValidationException $exception, int $others): string
    {
        foreach ($exception->errors() as $field => $messages) {
            $message = (string) ($messages[0] ?? '');

            if (preg_match('/^votes\.(\d+)\./', $field, $match) === 1 && (int) $match[1] < $others) {
                return 'A vote on the post or its questions saved earlier stands in the way: '.$message.' Fix it on Post · Votes.';
            }

            return $message;
        }

        return 'These votes can’t be saved.';
    }

    /**
     * What stands in the way of casting each saved vote, by its id.
     *
     * @return array<int, string>
     */
    private function readiness(StudioPlan $plan): array
    {
        $votes = StudioVotesData::of($plan)->votes;
        $problems = [];

        foreach (app(StudioVoteValidationRules::class)->readinessErrors($plan) as $field => $message) {
            if (preg_match('/^votes\.(\d+)\./', $field, $match) === 1 && ($votes[(int) $match[1]]->id ?? null) !== null) {
                $problems[(int) $votes[(int) $match[1]]->id] ??= $message;
            }
        }

        return $problems;
    }

    /**
     * Votes as the summary lists them: when — after the post and after the
     * comment — who, on which comment, which way, and what keeps a saved one
     * from being cast.
     *
     * @param  list<array<string, mixed>>  $votes
     * @param  Collection<int, User>  $people
     * @param  array<int, string>  $labels
     * @param  list<StudioDiscussionNode>  $nodes
     * @param  array<int, string>  $problems
     * @return list<array<string, mixed>>
     */
    private function rows(array $votes, Collection $people, array $labels, array $nodes, array $problems): array
    {
        $byId = [];

        foreach ($nodes as $node) {
            $byId[(int) $node->id] = $node;
        }

        return array_map(function (array $vote) use ($people, $labels, $byId, $problems): array {
            $node = $byId[(int) $vote['discussionNodeId']] ?? null;

            return [
                'persona' => StudioPlanFormat::author($people->get($vote['personaUserId'])),
                'label' => $labels[(int) $vote['discussionNodeId']] ?? 'A deleted comment',
                'body' => $node !== null ? Str::limit((string) $node->body, 60) : '',
                'vote' => $vote['voteType'],
                'when' => '+'.StudioPlanDiscussionEditor::relative((int) $vote['offsetSeconds']),
                'after' => $node !== null ? '+'.StudioPlanDiscussionEditor::relative((int) $vote['offsetSeconds'] - (int) $node->offset_seconds).' after it' : '',
                'offset' => (int) $vote['offsetSeconds'],
                'problem' => $vote['id'] !== null ? ($problems[(int) $vote['id']] ?? null) : null,
            ];
        }, $votes);
    }

    /**
     * The Voters tab: each voter with the comments it votes up and down and
     * the ones it writes, from the preview, or the saved votes when there is
     * none.
     *
     * @param  list<array<string, mixed>>  $votes
     * @param  list<int>  $voterIds
     * @param  Collection<int, User>  $people
     * @param  array<int, string>  $labels
     * @param  array<int, list<string>>  $wrote
     * @return array<string, mixed>
     */
    private function voterTable(array $votes, array $voterIds, Collection $people, array $labels, array $wrote): array
    {
        $by = [];

        foreach ($votes as $vote) {
            $by[$vote['personaUserId']][$vote['voteType']][] = $labels[(int) $vote['discussionNodeId']] ?? '?';
        }

        foreach ($by as $id => $ways) {
            foreach ($ways as $way => $list) {
                sort($by[$id][$way], SORT_NATURAL);
            }
        }

        $search = mb_strtolower(ltrim(trim($this->votersSearch), '@'));
        $rows = [];

        foreach (array_values(array_unique([...$voterIds, ...array_keys($by)])) as $id) {
            $persona = StudioPlanFormat::author($people->get($id));
            $voting = isset($by[$id]);
            $writes = $wrote[$id] ?? [];

            if (($this->votersFilter === 'voting' && ! $voting) || ($this->votersFilter === 'idle' && $voting) || ($this->votersFilter === 'writers' && $writes === [])
                || ($search !== '' && ! str_contains(mb_strtolower(($persona['name'] ?? '').' '.($persona['username'] ?? '')), $search))) {
                continue;
            }

            $rows[] = ['persona' => $persona, 'voting' => $voting, 'up' => $by[$id]['up'] ?? [], 'down' => $by[$id]['down'] ?? [], 'writes' => $writes];
        }

        usort($rows, fn (array $a, array $b): int => [$b['voting'], $a['persona']['name'] ?? ''] <=> [$a['voting'], $b['persona']['name'] ?? '']);

        return [
            'rows' => $rows,
            'pool' => count(array_unique([...$voterIds, ...array_keys($by)])),
            'voting' => count($by),
        ];
    }

    /**
     * Each comment's votes as set here, for the discussion tree to show beside
     * its moment: how many up and down, whether that is not saved yet, and
     * what stands in the way of them.
     *
     * @param  list<StudioDiscussionNode>  $nodes
     * @param  list<array<string, mixed>>  $saved
     * @param  list<int>  $eligible
     * @param  array<int, string>  $problems
     * @return array<int, array{up: int, down: int, changed: bool, problem: string|null}>
     */
    private function summaryOf(array $nodes, array $saved, array $eligible, array $problems): array
    {
        $summary = [];

        foreach ($nodes as $node) {
            $id = (int) $node->id;
            $target = $this->targets[$id] ?? ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => self::TIMING[0], 'spread' => self::TIMING[1], 'moments' => []];
            $savedOn = self::on($saved, $id);
            $up = count(array_filter($savedOn, fn (array $vote): bool => $vote['voteType'] === 'up'));

            $summary[$id] = [
                'up' => $target['up'],
                'down' => $target['down'],
                'changed' => $this->unsaved && ($this->preview !== null
                    ? self::moments(self::on($this->preview, $id)) !== self::moments($savedOn)
                    : [$target['up'], $target['down']] !== [$up, count($savedOn) - $up]),
                'problem' => $this->problemOf($node, $target, $eligible, $problems, $savedOn),
            ];
        }

        return $summary;
    }

    /**
     * What stands in the way of a comment's votes: what the settings ask for
     * that can't be made, the voters it is short, or what keeps a saved vote
     * on it from being cast.
     *
     * @param  array{up: int, down: int, priority: string, first: int, spread: int, moments: array<int, int>}  $target
     * @param  list<int>  $eligible
     * @param  array<int, string>  $problems
     * @param  list<array<string, mixed>>  $savedOn
     */
    private function problemOf(StudioDiscussionNode $node, array $target, array $eligible, array $problems, array $savedOn): ?string
    {
        $errors = $this->getErrorBag();
        $can = count(array_diff($eligible, [$node->persona_user_id]));
        $short = $this->voterMode === 'manual' ? max(0, $target['up'] + $target['down'] - $can) : 0;

        foreach (["targets.{$node->id}", "timing.{$node->id}"] as $field) {
            if ($errors->has($field)) {
                return $errors->first($field);
            }
        }

        if ($short > 0) {
            return 'Needs '.($target['up'] + $target['down']).' voters; '.$can.' can vote on it — '.$short.' short.';
        }

        foreach ($savedOn as $vote) {
            if ($vote['id'] !== null && isset($problems[(int) $vote['id']])) {
                return $problems[(int) $vote['id']];
            }
        }

        return null;
    }

    /**
     * The breakdown of the comment opened beside the tree: the comment, its
     * votes as set here — how many, its priority, when they come — and each
     * vote as it stands: who casts it, which way, and when.
     *
     * @param  list<array<string, mixed>>  $shown
     * @param  Collection<int, User>  $people
     * @param  array<int, string>  $labels
     * @param  list<StudioDiscussionNode>  $nodes
     * @param  array<int, string>  $problems
     * @return array<string, mixed>
     */
    private function breakdown(StudioDiscussionNode $node, array $shown, Collection $people, array $labels, array $nodes, array $problems): array
    {
        $id = (int) $node->id;
        $target = $this->targets[$id];

        return [
            'id' => $id,
            'label' => $labels[$id] ?? '',
            'replyTo' => $node->parent_id !== null ? ($labels[(int) $node->parent_id] ?? null) : null,
            'persona' => StudioPlanFormat::author($people->get($node->persona_user_id)),
            'body' => Str::limit((string) $node->body, 180),
            'at' => '+'.StudioPlanDiscussionEditor::relative((int) $node->offset_seconds),
            ...$target,
            'chips' => [
                'first' => self::chips(StudioPlanPostVotesEditor::FIRST_CHOICES, [$target['first']]),
                'spread' => self::chips(StudioPlanPostVotesEditor::SPREAD_CHOICES, [$target['spread']]),
            ],
            'window' => self::window((int) $node->offset_seconds, $target, self::on($shown, $id)),
            'changed' => $this->summary[$id]['changed'] ?? false,
            'problem' => $this->summary[$id]['problem'] ?? null,
            'waiting' => $this->drafted() && $this->preview === null,
            'votes' => $this->rows(self::on($shown, $id), $people, $labels, $nodes, $problems),
        ];
    }

    /**
     * The tree's Timing tab: every comment, reply and vote on them in the order
     * they come after the post — a comment before the votes at its moment —
     * each with who, what it comes after and when, and its moment to change:
     * a comment with its votes as set here, a vote with whether it is not
     * saved and what keeps it from being cast.
     *
     * @param  list<StudioDiscussionNode>  $nodes
     * @param  list<array<string, mixed>>  $shown
     * @param  list<array<string, mixed>>  $saved
     * @param  Collection<int, User>  $people
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $problems
     * @return list<array<string, mixed>>
     */
    private function timing(array $nodes, array $shown, array $saved, Collection $people, array $labels, array $problems): array
    {
        $byId = [];

        foreach ($nodes as $node) {
            $byId[(int) $node->id] = $node;
        }

        $label = fn (int $node): string => $labels[$node] ?? '';
        $kept = array_flip(array_map(fn (array $vote): string => implode('|', [$vote['personaUserId'], $vote['discussionNodeId'], $vote['voteType'], (int) $vote['offsetSeconds']]), $saved));
        $rows = [];

        foreach ($nodes as $node) {
            $id = (int) $node->id;
            $parent = $node->parent_id !== null ? ($byId[(int) $node->parent_id] ?? null) : null;

            $rows[] = [
                'kind' => $parent !== null ? 'reply' : 'comment',
                'key' => 'node-'.$id,
                'node' => $id,
                'persona' => null,
                'action' => ($parent !== null ? 'Reply ' : 'Comment ').$label($id),
                'who' => StudioPlanFormat::author($people->get($node->persona_user_id)),
                'parent' => $parent !== null ? $label((int) $parent->id) : 'Post',
                'seconds' => (int) $node->offset_seconds,
                'after' => $parent !== null ? '+'.StudioPlanDiscussionEditor::relative((int) $node->offset_seconds - (int) $parent->offset_seconds) : null,
                'dependency' => $parent !== null ? 'After '.$label((int) $parent->id).' at +'.StudioPlanDiscussionEditor::relative((int) $parent->offset_seconds) : 'After the post',
                'votes' => [$this->summary[$id]['up'] ?? 0, $this->summary[$id]['down'] ?? 0],
                'window' => self::window((int) $node->offset_seconds, $this->targets[$id] ?? ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => self::TIMING[0], 'spread' => self::TIMING[1], 'moments' => []], self::on($shown, $id)),
                'vote' => null,
                'changed' => $this->summary[$id]['changed'] ?? false,
                'problem' => $this->summary[$id]['problem'] ?? null,
                'order' => 0,
            ];
        }

        foreach ($shown as $vote) {
            $node = $byId[(int) $vote['discussionNodeId']] ?? null;

            if ($node === null) {
                continue;
            }

            $on = $label((int) $node->id);

            $rows[] = [
                'kind' => 'vote',
                'key' => 'vote-'.$node->id.'-'.$vote['personaUserId'],
                'node' => (int) $node->id,
                'persona' => (int) $vote['personaUserId'],
                'action' => $vote['voteType'] === 'up' ? 'Vote up' : 'Vote down',
                'who' => StudioPlanFormat::author($people->get($vote['personaUserId'])),
                'parent' => $on,
                'seconds' => (int) $vote['offsetSeconds'],
                'after' => '+'.StudioPlanDiscussionEditor::relative((int) $vote['offsetSeconds'] - (int) $node->offset_seconds),
                'dependency' => 'After '.$on.' at +'.StudioPlanDiscussionEditor::relative((int) $node->offset_seconds),
                'votes' => null,
                'window' => null,
                'vote' => $vote['voteType'],
                'changed' => $this->unsaved && ! isset($kept[implode('|', [$vote['personaUserId'], $vote['discussionNodeId'], $vote['voteType'], (int) $vote['offsetSeconds']])]),
                'problem' => $vote['id'] !== null ? ($problems[(int) $vote['id']] ?? null) : null,
                'order' => 1,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['seconds'], $a['order']] <=> [$b['seconds'], $b['order']]);

        return $rows;
    }

    /** A comment's label in the tree, read when one action needs it. */
    private function labelOf(int $node): string
    {
        $rows = array_map(fn (StudioDiscussionNode $node): array => self::nodeRow($node), $this->orderedNodes());

        return StudioPlanDiscussionEditor::labels(StudioPlanDiscussionEditor::ordered($rows))[$node] ?? 'its comment';
    }

    /**
     * What is not saved, comment by comment, for the step's question before
     * leaving: each changed comment's votes as set here and as saved, or what
     * stands in the way of saving them.
     *
     * @param  list<StudioDiscussionNode>  $nodes
     * @param  list<array<string, mixed>>  $saved
     * @param  array<int, string>  $labels
     * @return list<array{label: string, text: string, problem: bool}>
     */
    private function changes(array $nodes, array $saved, array $labels): array
    {
        $changes = [];

        foreach ($nodes as $node) {
            $entry = $this->summary[(int) $node->id] ?? null;

            if ($entry === null || (! $entry['changed'] && $entry['problem'] === null)) {
                continue;
            }

            $savedOn = self::on($saved, (int) $node->id);
            $up = count(array_filter($savedOn, fn (array $vote): bool => $vote['voteType'] === 'up'));
            $now = '+'.$entry['up'].' / −'.$entry['down'];
            $was = '+'.$up.' / −'.(count($savedOn) - $up);

            $changes[] = [
                'label' => $labels[(int) $node->id] ?? '',
                'text' => $entry['problem'] ?? ($now === $was ? $now.' · other voters or moments than saved' : $now.' · saved '.$was),
                'problem' => $entry['problem'] !== null,
            ];
        }

        return $changes;
    }

    /**
     * The votes on one comment.
     *
     * @param  list<array<string, mixed>>  $votes
     * @return list<array<string, mixed>>
     */
    private static function on(array $votes, int $node): array
    {
        return array_values(array_filter($votes, fn (array $vote): bool => (int) $vote['discussionNodeId'] === $node));
    }

    /**
     * Votes on one comment as its counts compare them: who, which way, when.
     *
     * @param  list<array<string, mixed>>  $votes
     * @return list<array<int, mixed>>
     */
    private static function moments(array $votes): array
    {
        $rows = array_map(fn (array $vote): array => [$vote['personaUserId'], $vote['voteType'], (int) $vote['offsetSeconds']], $votes);
        sort($rows);

        return $rows;
    }

    /**
     * A comment's votes in time: when the first and the last come after the
     * post, and after the comment — as they stand, or as asked while they
     * can't be made.
     *
     * @param  array{up: int, down: int, priority: string, first: int, spread: int, moments: array<int, int>}  $target
     * @param  list<array<string, mixed>>  $votes  the votes on it as they stand
     */
    private static function window(int $comment, array $target, array $votes): string
    {
        $count = $target['up'] + $target['down'];
        $moments = $votes !== []
            ? array_map(fn (array $vote): int => (int) $vote['offsetSeconds'], $votes)
            : [$comment + $target['first'], $comment + $target['first'] + ($count > 1 ? $target['spread'] : 0)];

        if ($count === 0 && $votes === []) {
            return 'No votes';
        }

        [$first, $last] = [min($moments), max($moments)];

        return '+'.StudioPlanDiscussionEditor::relative($first).($last > $first ? ' → +'.StudioPlanDiscussionEditor::relative($last) : '')
            .' after the post · from '.StudioPlanDiscussionEditor::relative($first - $comment).' after the comment';
    }

    /**
     * The moments offered, and the ones the comments have: on when every
     * comment has it.
     *
     * @param  array<int, string>  $offered
     * @param  list<int>  $values
     * @return list<array{seconds: int, label: string, on: bool}>
     */
    private static function chips(array $offered, array $values): array
    {
        foreach ($values as $value) {
            $offered[$value] ??= StudioPlanDiscussionEditor::relative((int) $value);
        }

        ksort($offered);

        return array_map(fn (int $seconds, string $label): array => ['seconds' => $seconds, 'label' => $label, 'on' => $values === [$seconds]], array_keys($offered), $offered);
    }

    /** @return Collection<int, User> the active Personas listed to choose, matching the search */
    private function personaList(): Collection
    {
        return app(PersonasQuery::class)->activeChoices(mb_substr(trim($this->voterSearch), 0, 100), self::PERSONA_LIST)->load('personaProfile');
    }

    /** A count as a field holds it: a whole number, none at least, never more than a plan may prepare. */
    private static function count(int $value): int
    {
        return max(0, min(StudioVoteValidationRules::MOST_VOTES, $value));
    }

    private function writablePlan(): ?StudioPlan
    {
        if (! $this->writable()) {
            return null;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->gone = true;
            $this->toast('This draft was deleted, so its votes can’t be changed any more.');
        }

        return $plan;
    }

    private function writable(): bool
    {
        return $this->planId !== 0 && ! $this->gone && ! $this->closed && ! $this->conflict;
    }

    private function refuse(CannotManageStudioPlanException $exception): void
    {
        match (true) {
            $exception->isGone() => $this->gone = true,
            $exception->isNotDraft() => $this->closed = true,
            $exception->isStale() => $this->conflict = true,
            default => null,
        };

        $this->toast(match (true) {
            $exception->isGone() => 'This draft was deleted, so its votes can’t be changed any more.',
            $exception->isNotDraft() => 'This plan is no longer a draft, so its votes can’t be changed here.',
            $exception->isStale() => 'Someone changed this draft meanwhile, so nothing was saved. Reload to see their changes.',
            default => 'You can’t manage publication plans any more, so nothing was saved.',
        });
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
}
