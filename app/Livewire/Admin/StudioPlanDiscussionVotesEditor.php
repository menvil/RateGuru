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
 * What is set here is a recipe, never saved: Build preview makes the votes
 * of it (BuildStudioDiscussionVotesData), drawn once, and Save votes saves
 * exactly those, through SaveStudioVotesAction — nothing is drawn again on
 * Save. The votes on the post and its rating questions are kept as the plan
 * has them at that moment; only the votes on comments are replaced. Opened
 * again, the editor draws the votes as they are saved: their counts, their
 * Personas, their times. Nothing is cast — prepared votes are no votes on the
 * site.
 *
 * One version for the whole plan, as with the other editors on the page: a
 * save here raises it and says so with `studio-plan-version-moved` from
 * `discussion_votes`, and a move said by another editor is taken only when
 * the plan is at that version now with this page's part of it as it was —
 * its comment votes the same, and the comments they are on too, unless the
 * move is the discussion's own, whose save may only take votes away with the
 * comments it deletes. A preview made before the comments changed is out of
 * date. Any other move is somebody else's, and nothing here is written until
 * Reload. Every request checks the viewer may still manage plans.
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

    /** The card's tab on screen: the comments' votes, the voters, or their timing. */
    public string $tab = 'votes';

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

    /** How many up and down votes Distribute automatically shares among the comments. */
    public int $totalUp = 0;

    public int $totalDown = 0;

    /**
     * Each comment's votes: how many up and down, its priority for the
     * automatic share, and when, after the comment.
     *
     * @var array<int, array{up: int, down: int, priority: string, first: int, spread: int}>
     */
    #[Locked]
    public array $targets = [];

    #[Locked]
    public ?int $seed = null;

    /** Whether the saved votes are preferred: until a new draw is asked for. */
    #[Locked]
    public bool $keepSaved = true;

    /**
     * The votes Build preview made, exactly as Save votes saves them.
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

    #[Locked]
    public bool $confirmingRemove = false;

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

    public function updatedTab(): void
    {
        $this->tab = in_array($this->tab, ['votes', 'voters', 'timing'], true) ? $this->tab : 'votes';
    }

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

    public function updatedTotalUp(): void
    {
        $this->totalUp = self::count($this->totalUp);
    }

    public function updatedTotalDown(): void
    {
        $this->totalDown = self::count($this->totalDown);
    }

    /** Up or Down of one comment, set by hand. */
    public function setVotes(mixed $node, mixed $way, mixed $count): void
    {
        $node = (int) $node;

        if (isset($this->targets[$node]) && in_array($way, ['up', 'down'], true) && is_numeric($count)) {
            $this->targets[$node][$way] = self::count((int) $count);
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
     * Distribute automatically: the total up votes and the total down votes,
     * each shared among the comments by their priorities — Low 1, Normal 2,
     * High 3 — the remainders to the largest fractions, then in the tree's
     * order. Nothing is drawn and nothing is saved; each comment can be set
     * by hand afterwards.
     */
    public function distribute(): void
    {
        $priorities = [];

        foreach ($this->orderedNodes() as $node) {
            if (isset($this->targets[(int) $node->id])) {
                $priorities[(int) $node->id] = $this->targets[(int) $node->id]['priority'];
            }
        }

        $up = BuildStudioDiscussionVotesData::distribute($priorities, $this->totalUp);
        $down = BuildStudioDiscussionVotesData::distribute($priorities, $this->totalDown);

        foreach (array_keys($priorities) as $node) {
            $this->targets[$node]['up'] = $up[$node] ?? 0;
            $this->targets[$node]['down'] = $down[$node] ?? 0;
        }
    }

    /** First vote after, and Spread over — after each comment — for every comment. */
    public function setTiming(mixed $field, mixed $seconds): void
    {
        if (! is_numeric($seconds) || ! in_array($field, ['first', 'spread'], true)) {
            return;
        }

        $seconds = (int) $seconds;
        $current = array_column($this->targets, $field);
        $offered = array_keys($field === 'first' ? StudioPlanPostVotesEditor::FIRST_CHOICES : StudioPlanPostVotesEditor::SPREAD_CHOICES);

        if (in_array($seconds, [...$offered, ...$current], true)) {
            foreach (array_keys($this->targets) as $node) {
                $this->targets[$node][$field] = $seconds;
            }
        }
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

    /** Build preview, or Rebuild: the votes the settings ask for, drawn once and shown as they will be saved. */
    public function build(): void
    {
        if ($this->writable()) {
            $this->seed ??= random_int(1, 2_147_483_647);
            $this->makePreview();
        }
    }

    /** Pick others, or Reshuffle: a new draw, with the same numbers, the saved votes no longer preferred. */
    public function redraw(): void
    {
        if ($this->writable()) {
            $this->seed = random_int(1, 2_147_483_647);
            $this->keepSaved = false;
            $this->makePreview();
        }
    }

    /**
     * Save votes: exactly the preview, with the votes on the post and its
     * questions as the plan has them now, through the one action that writes
     * a plan's votes. A preview that is the saved votes again writes nothing.
     */
    public function save(): void
    {
        if (! $this->writable()) {
            return;
        }

        if ($this->preview === null || $this->previewFor !== $this->previewKey()) {
            $this->addError('preview', 'Build the preview first: the votes saved are the ones it shows.');

            return;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan !== null && $plan->lock_version === $this->lockVersion && self::comparable($this->preview) === self::comparable(self::commentVotesOf($plan))) {
            $this->load();
            $this->toast('Nothing to save: these are the votes saved.', 'info');

            return;
        }

        $this->write(array_map(fn (array $vote): StudioVoteData => new StudioVoteData(...$vote), $this->preview), 'Comment votes saved');
    }

    /** Remove comment votes asks first; the votes on the post and its questions stay. */
    public function askRemove(): void
    {
        $this->confirmingRemove = $this->writable();
    }

    public function closeRemove(): void
    {
        $this->confirmingRemove = false;
    }

    public function confirmRemove(): void
    {
        $this->confirmingRemove = false;

        if ($this->writable()) {
            $this->write([], 'Comment votes removed');
        }
    }

    /** Discard: the settings as the saved votes have them, the preview gone. */
    public function discard(): void
    {
        $this->load();
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
     * kept; otherwise the votes are drawn again from what is saved.
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
        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;
        $nodes = $plan !== null ? $this->orderedNodes() : [];
        $rows = array_map(fn (StudioDiscussionNode $node): array => self::nodeRow($node), $nodes);
        $labels = StudioPlanDiscussionEditor::labels(StudioPlanDiscussionEditor::ordered($rows));
        $saved = $plan !== null ? self::commentVotesOf($plan) : [];
        $preview = $this->preview ?? [];
        $voterIds = match (true) {
            $this->voterMode === 'manual' => $this->voterIds,
            $this->preview !== null => $this->drawn,
            default => array_values(array_unique(array_column($saved, 'personaUserId'))),
        };
        $people = User::query()
            ->whereKey(array_values(array_unique([...$voterIds, ...array_column($saved, 'personaUserId'), ...array_column($preview, 'personaUserId'), ...array_map(fn (StudioDiscussionNode $node): int => $node->persona_user_id, $nodes)])))
            ->with('avatarAsset.variants')
            ->get()
            ->keyBy('id');
        $active = app(PersonasQuery::class)->activeIds();
        $cap = count($active);
        $eligible = array_values(array_intersect($this->voterIds, $active));
        $voters = $this->voterMode === 'manual' ? count($eligible) : min($this->voterCount, $cap);
        $wrote = [];

        foreach ($nodes as $node) {
            $wrote[$node->persona_user_id][] = $labels[(int) $node->id] ?? '';
        }

        return view('livewire.admin.studio-plan-discussion-votes-editor', [
            'editable' => $this->writable(),
            'cap' => $cap,
            'voters' => $voters,
            'stale' => $this->preview !== null && $this->previewFor !== $this->previewKey(),
            'changed' => $this->settingsKey() !== $this->restoredFor,
            'comments' => array_map(function (StudioDiscussionNode $node) use ($labels, $people, $eligible): array {
                $target = $this->targets[(int) $node->id] ?? ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => self::TIMING[0], 'spread' => self::TIMING[1]];
                $can = count(array_diff($eligible, [$node->persona_user_id]));

                return [
                    'id' => (int) $node->id,
                    'label' => $labels[(int) $node->id] ?? '',
                    'reply' => $node->parent_id !== null,
                    'persona' => StudioPlanFormat::author($people->get($node->persona_user_id)),
                    'body' => Str::limit((string) $node->body, 90),
                    'at' => '+'.StudioPlanDiscussionEditor::relative((int) $node->offset_seconds),
                    ...$target,
                    'short' => $this->voterMode === 'manual' ? max(0, $target['up'] + $target['down'] - $can) : 0,
                    'can' => $can,
                    'window' => self::window((int) $node->offset_seconds, $target),
                ];
            }, $nodes),
            'listed' => $this->voterMode === 'manual' ? $this->personaList()->map(fn (User $persona): array => [
                ...(StudioPlanFormat::author($persona) ?? []),
                'short' => Str::limit((string) ($persona->personaProfile->short_description ?? ''), 90),
                'on' => in_array((int) $persona->id, $this->voterIds, true),
                'writes' => $wrote[(int) $persona->id] ?? [],
            ])->values()->all() : [],
            'chosen' => array_map(fn (int $id): array => StudioPlanFormat::author($people->get($id)) ?? ['id' => $id, 'name' => 'Deleted user', 'username' => null, 'avatar' => null, 'initials' => '', 'status' => null, 'problem' => 'deleted'], $this->voterMode === 'manual' ? $this->voterIds : []),
            'savedRows' => $this->rows($saved, $people, $labels, $nodes, $plan !== null ? $this->readiness($plan) : []),
            'previewRows' => $this->preview !== null ? $this->rows($preview, $people, $labels, $nodes, []) : null,
            'voterTable' => $this->voterTable($preview !== [] || $this->preview !== null ? $preview : $saved, $voterIds, $people, $labels, $wrote),
            'chips' => [
                'first' => self::chips(StudioPlanPostVotesEditor::FIRST_CHOICES, array_values(array_unique(array_column($this->targets, 'first')))),
                'spread' => self::chips(StudioPlanPostVotesEditor::SPREAD_CHOICES, array_values(array_unique(array_column($this->targets, 'spread')))),
            ],
        ]);
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
        $this->confirmingRemove = false;
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
        $this->heldVotes = self::hashes($saved);
        $this->treeFingerprint = self::treeOf($nodes);
        $this->targets = [];

        foreach ($nodes as $node) {
            $votes = array_values(array_filter($saved, fn (array $vote): bool => $vote['discussionNodeId'] === (int) $node->id));
            $up = count(array_filter($votes, fn (array $vote): bool => $vote['voteType'] === 'up'));
            $offsets = array_map(fn (array $vote): int => (int) $vote['offsetSeconds'] - (int) $node->offset_seconds, $votes);

            $this->targets[(int) $node->id] = [
                'up' => $up,
                'down' => count($votes) - $up,
                'priority' => 'normal',
                'first' => $offsets !== [] ? min($offsets) : self::TIMING[0],
                'spread' => count($offsets) > 1 ? max($offsets) - min($offsets) : self::TIMING[1],
            ];
        }

        $this->totalUp = array_sum(array_column($this->targets, 'up'));
        $this->totalDown = array_sum(array_column($this->targets, 'down'));
        $voters = array_values(array_unique(array_column($saved, 'personaUserId')));
        $this->voterMode = $voters !== [] ? 'manual' : 'random';
        $this->voterIds = $voters;
        $this->voterCount = $voters !== [] ? count($voters) : min(self::DEFAULT_VOTERS, count(app(PersonasQuery::class)->activeIds()));
        $this->restoredFor = $this->settingsKey();
    }

    /**
     * Takes a version another editor's write left. Its comments as the plan
     * has them now — a new one asked about with no votes, a deleted one gone —
     * and the settings drawn again from the saved votes when nothing here was
     * changed yet; a preview made before the comments changed is out of date.
     */
    private function take(int $version): void
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->gone = true;

            return;
        }

        $untouched = $this->preview === null && $this->settingsKey() === $this->restoredFor;
        $this->lockVersion = $version;
        $this->conflict = false;

        if ($untouched) {
            $this->load();
            $this->lockVersion = $version;

            return;
        }

        $nodes = $this->orderedNodes();
        $this->heldVotes = self::hashes(self::commentVotesOf($plan));
        $this->treeFingerprint = self::treeOf($nodes);
        $targets = [];

        foreach ($nodes as $node) {
            $targets[(int) $node->id] = $this->targets[(int) $node->id] ?? ['up' => 0, 'down' => 0, 'priority' => 'normal', 'first' => self::TIMING[0], 'spread' => self::TIMING[1]];
        }

        $this->targets = $targets;
    }

    /**
     * Whether the plan is at this version now with this page's part of it as
     * it holds it: the comment votes the same, and the comments too — or, after
     * the discussion's own save, which may only take away the votes of the
     * comments it deletes, no vote added or changed.
     */
    private function holdsAt(int $version, ?string $source): bool
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null || $plan->lock_version !== $version) {
            return false;
        }

        $votes = self::hashes(self::commentVotesOf($plan));

        if ($source === 'discussion') {
            return array_diff($votes, $this->heldVotes) === [];
        }

        return $votes === $this->heldVotes && self::treeOf($this->orderedNodes()) === $this->treeFingerprint;
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

    private function makePreview(): void
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return;
        }

        $this->resetErrorBag();

        if ($this->orderedNodes() === []) {
            $this->addError('votes', 'The discussion has no comments to vote on yet.');

            return;
        }

        try {
            $built = app(BuildStudioDiscussionVotesData::class)->handle($plan, $this->settings());
        } catch (ValidationException $exception) {
            $this->preview = null;

            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, (string) ($messages[0] ?? ''));
            }

            return;
        }

        $this->preview = array_map(fn (StudioVoteData $vote): array => $vote->toArray(), $built->votes->votes);
        $this->drawn = $built->voters;
        $this->previewFor = $this->previewKey();
    }

    /** The settings as BuildStudioDiscussionVotesData takes them. */
    private function settings(): StudioDiscussionVotesSettings
    {
        $targets = [];

        foreach ($this->targets as $node => $target) {
            $targets[(int) $node] = new StudioDiscussionVoteTargetSettings((int) $node, $target['up'], $target['down'], $target['priority'], $target['first'], $target['spread']);
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
            array_map(fn (array $target): array => [$target['up'], $target['down'], $target['first'], $target['spread']], $this->targets),
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
     * Each vote in one value, the list sorted.
     *
     * @param  list<array<string, mixed>>  $votes
     * @return list<string>
     */
    private static function hashes(array $votes): array
    {
        $hashes = array_map(fn (array $vote): string => hash('xxh3', json_encode($vote, JSON_THROW_ON_ERROR)), $votes);
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
     * A comment's votes in time: when the first and the last come after the
     * post, and after the comment.
     *
     * @param  array{up: int, down: int, priority: string, first: int, spread: int}  $target
     */
    private static function window(int $comment, array $target): string
    {
        $count = $target['up'] + $target['down'];

        if ($count === 0) {
            return 'No votes';
        }

        $first = $comment + $target['first'];
        $last = $first + ($count > 1 ? $target['spread'] : 0);

        return '+'.StudioPlanDiscussionEditor::relative($first).($count > 1 ? ' → +'.StudioPlanDiscussionEditor::relative($last) : '')
            .' after the post · from '.StudioPlanDiscussionEditor::relative($target['first']).' after the comment';
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
