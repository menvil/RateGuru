<?php

namespace App\Livewire\Admin;

use App\Actions\Studio\SaveStudioVotesAction;
use App\Data\Studio\SavedStudioVotes;
use App\Data\Studio\StudioPostVotesSettings;
use App\Data\Studio\StudioRatingVotesSettings;
use App\Data\Studio\StudioVoteData;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioActionType;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Personas\PersonasQuery;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Studio\BuildStudioPostVotesData;
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
 * The Votes tab of the editor's Post step (docs/architecture/studio-plans.md,
 * "The Post votes editor"), laid out as the prototype's StudioPostVotes: the
 * post's voters on the left — drawn at random from the active Personas or
 * chosen one by one, never the post's author — and on the right how many
 * vote the post up and down, how many answer each rating question with which
 * option, and when, with the voters and the timeline those make.
 *
 * What is set here is a recipe, never saved: Build preview makes the votes
 * of it (BuildStudioPostVotesData), drawn once, and Save votes saves exactly
 * those, through SaveStudioVotesAction — nothing is drawn again on Save. The
 * votes on the discussion's comments are kept as the plan has them at that
 * moment; only the votes on the post and its rating questions are replaced.
 * Opened again, the editor draws the votes as they are saved: their counts,
 * their Personas, their options and their times, the Personas as chosen by
 * hand. Nothing is cast — prepared votes are no votes on the site.
 *
 * One version for the whole plan, as with the Discussion step: a save here
 * raises it and says so with `studio-plan-version-moved` from `post_votes`,
 * and a move said by the other editors on the page is taken only when the
 * plan is at that version now with its post votes as this page last read or
 * wrote them (`votesFingerprint`). Any other move is somebody else's, and
 * nothing here is written until Reload. Every request checks the viewer may
 * still manage plans.
 */
final class StudioPlanPostVotesEditor extends Component
{
    /** What this editor's writes and Reloads are said from. */
    public const SOURCE = 'post_votes';

    /** The moments the first vote may come after the post, in seconds, as the prototype offers them. */
    public const FIRST_CHOICES = [300 => '5 min', 1800 => '30 min', 3600 => '1 h', 21600 => '6 h', 86400 => '1 day'];

    /** How long the votes may be spread over, in seconds. */
    public const SPREAD_CHOICES = [3600 => '1 h', 21600 => '6 h', 86400 => '1 day', 172800 => '2 days', 604800 => '1 week'];

    /** When the votes on the post, and the answers, come by default. */
    private const POST_TIMING = [300, 86400];

    private const RATING_TIMING = [600, 172800];

    /** How many voters a random draw starts with, at most. */
    private const DEFAULT_VOTERS = 20;

    /** The most Personas listed to choose from at once. */
    private const PERSONA_LIST = 50;

    #[Locked]
    public int $planId = 0;

    /** The plan's lock_version as this page last read or wrote it. */
    #[Locked]
    public int $lockVersion = 0;

    /** The votes on the post and its questions as this page last read or wrote them, in one value. */
    #[Locked]
    public string $votesFingerprint = '';

    /** Whether the plan moved to a version this page did not write, so nothing here is written until Reload. */
    #[Locked]
    public bool $conflict = false;

    #[Locked]
    public bool $gone = false;

    #[Locked]
    public bool $closed = false;

    /** The post's author as the plan has it saved: who never votes on it. */
    #[Locked]
    public ?int $authorId = null;

    /** The card's tab on screen: the votes and answers, the voters, or their timing. */
    public string $tab = 'votes';

    /** Whether the post is to get up and down votes at all. */
    public bool $postOn = false;

    public int $up = 0;

    public int $down = 0;

    /** When the first vote on the post comes, and how long they are spread over, in seconds. */
    #[Locked]
    public int $postFirst = 300;

    #[Locked]
    public int $postSpread = 86400;

    /**
     * Each rating question asked about: whether it is to get answers, how
     * many to each option, and when.
     *
     * @var array<int, array{on: bool, counts: array<int, int>, first: int, spread: int}>
     */
    #[Locked]
    public array $ratings = [];

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

    /** What every draw is made from: the same seed draws the same votes. */
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

    /** What the preview was made from (previewKey()): once the settings move on, it is out of date. */
    #[Locked]
    public string $previewFor = '';

    /** The settings as drawn from the saved votes (settingsKey()). */
    #[Locked]
    public string $restoredFor = '';

    /** Whether Remove is asking first. */
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

    public function updatedUp(): void
    {
        $this->up = self::count($this->up);
    }

    public function updatedDown(): void
    {
        $this->down = self::count($this->down);
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
        $this->votersFilter = in_array($this->votersFilter, ['all', 'voting', 'idle'], true) ? $this->votersFilter : 'all';
    }

    /** Switches a rating question's answers on or off; its counts stay for when it is on again. */
    public function toggleRating(mixed $group): void
    {
        $group = (int) $group;

        if (isset($this->ratings[$group])) {
            $this->ratings[$group]['on'] = ! $this->ratings[$group]['on'];
        }
    }

    /**
     * Answers: a question's total, the new one spread over its open options
     * in proportion to what each has — evenly when none has any. An option no
     * longer open keeps what it has, as far as the total allows.
     */
    public function setRatingTotal(mixed $group, mixed $total): void
    {
        $group = (int) $group;

        if (! isset($this->ratings[$group]) || ! is_numeric($total)) {
            return;
        }

        $total = self::count((int) $total);
        $open = $this->openOptions($group);
        $counts = $this->ratings[$group]['counts'];
        $closed = array_diff_key($counts, array_flip($open));
        arsort($closed);

        foreach ($closed as $option => $count) {
            $closed[$option] = min($count, max(0, $total));
            $total -= $closed[$option];
        }

        $weights = array_intersect_key($counts, array_flip($open));
        $this->ratings[$group]['counts'] = $closed + self::split($open, array_sum($weights) > 0 ? $weights : array_fill_keys($open, 1), $total);
    }

    /** Even split: the question's total spread over its open options as evenly as whole answers go, the rest to the first ones. */
    public function evenSplit(mixed $group): void
    {
        $group = (int) $group;

        if (! isset($this->ratings[$group])) {
            return;
        }

        $open = $this->openOptions($group);
        $total = array_sum($this->ratings[$group]['counts']);
        $even = [];

        foreach ($open as $index => $option) {
            $even[$option] = intdiv($total, max(1, count($open))) + ($index < $total % max(1, count($open)) ? 1 : 0);
        }

        $this->ratings[$group]['counts'] = $even + array_map(fn (): int => 0, $this->ratings[$group]['counts']);
    }

    /**
     * + and − on an option: one answer moved to it from the most-answered
     * other one, or from it to the most-answered other open one, so the total
     * stays. Nothing new goes to an option no longer open.
     */
    public function moveAnswer(mixed $group, mixed $option, mixed $direction): void
    {
        [$group, $option] = [(int) $group, (int) $option];

        if (! isset($this->ratings[$group]['counts'][$option])) {
            return;
        }

        $counts = $this->ratings[$group]['counts'];
        $open = $this->openOptions($group);
        $others = array_diff_key($counts, [$option => 0]);

        if ($direction === 'up' && in_array($option, $open, true)) {
            $donors = array_filter($others, fn (int $count): bool => $count > 0);
            arsort($donors);
            $from = array_key_first($donors);

            if ($from !== null) {
                $counts[$from]--;
                $counts[$option]++;
            }
        } elseif ($direction === 'down' && $counts[$option] > 0) {
            $takers = array_intersect_key($others, array_flip($open));
            arsort($takers);
            $to = array_key_first($takers);

            if ($to !== null) {
                $counts[$option]--;
                $counts[$to]++;
            }
        }

        $this->ratings[$group]['counts'] = $counts;
    }

    /** First vote after, and Spread over: one of the moments offered, or the one it has. */
    public function setTiming(mixed $block, mixed $field, mixed $seconds): void
    {
        if (! is_numeric($seconds) || ! in_array($field, ['first', 'spread'], true)) {
            return;
        }

        $seconds = (int) $seconds;
        $offered = array_keys($field === 'first' ? self::FIRST_CHOICES : self::SPREAD_CHOICES);

        if ($block === 'post') {
            $current = $field === 'first' ? $this->postFirst : $this->postSpread;

            if (in_array($seconds, [...$offered, $current], true)) {
                $field === 'first' ? $this->postFirst = $seconds : $this->postSpread = $seconds;
            }

            return;
        }

        $group = (int) $block;

        if (isset($this->ratings[$group]) && in_array($seconds, [...$offered, $this->ratings[$group][$field]], true)) {
            $this->ratings[$group][$field] = $seconds;
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

        if ($id !== $this->authorId && app(PersonasQuery::class)->active($id) !== null) {
            $this->voterIds[] = $id;
        }
    }

    /** Chooses every Persona the search shows that may vote, or takes them away when all are chosen. */
    public function selectShown(): void
    {
        $shown = $this->personaList()->map(fn (User $persona): int => (int) $persona->id)->reject(fn (int $id): bool => $id === $this->authorId)->values()->all();

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

    /**
     * Build preview, or Rebuild: the votes the settings ask for, drawn once
     * and shown as they will be saved. A Persona keeps the vote it has saved
     * as far as the settings allow. Nothing is built while the post's author
     * has unsaved changes: the votes are made for the author the plan has.
     */
    public function build(mixed $authorChanged = false): void
    {
        if ($this->ready($authorChanged)) {
            $this->seed ??= random_int(1, 2_147_483_647);
            $this->makePreview();
        }
    }

    /** Pick others, or Reshuffle: a new draw, with the same numbers, the saved votes no longer preferred. */
    public function redraw(mixed $authorChanged = false): void
    {
        if ($this->ready($authorChanged)) {
            $this->seed = random_int(1, 2_147_483_647);
            $this->keepSaved = false;
            $this->makePreview();
        }
    }

    /**
     * Save votes: exactly the preview, with the discussion's votes as the
     * plan has them now, through the one action that writes a plan's votes.
     */
    public function save(mixed $authorChanged = false): void
    {
        if (! $this->ready($authorChanged)) {
            return;
        }

        if ($this->preview === null || $this->previewFor !== $this->previewKey()) {
            $this->addError('preview', 'Build the preview first: the votes saved are the ones it shows.');

            return;
        }

        $this->write(array_map(fn (array $vote): StudioVoteData => new StudioVoteData(...$vote), $this->preview), 'Votes saved');
    }

    /** Remove post votes asks first; the discussion's votes stay. */
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
            $this->write([], 'Post votes removed');
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

    /** Reload votes: the votes as saved now, said to the other editors on the page. */
    #[On('reload-studio-post-votes')]
    public function reload(): void
    {
        $this->load();

        if (! $this->gone) {
            $this->dispatch(StudioPlanDiscussionEditor::RELOAD_EVENT, planId: $this->planId, current: $this->lockVersion, source: self::SOURCE);
        }
    }

    /**
     * Another editor on the page drew the plan again at the version it says:
     * taken when this page's post votes are still the saved ones, the preview
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

        if ($this->holdsSavedAt((int) $current)) {
            $this->take((int) $current);

            return;
        }

        $this->load();
    }

    /**
     * Another editor on the page moved the plan's version with a write of its
     * own: taken when it was written from the version this page holds, the
     * plan is at the version it says now, and its post votes are still the
     * ones this page last read or wrote. Any other move is somebody else's.
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

        if ($previous === $this->lockVersion && $current === $previous + 1 && $this->holdsSavedAt($current)) {
            $this->take($current);

            return;
        }

        $this->conflict = true;
    }

    public function render(): View
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;
        $questions = $this->questions();
        $saved = $plan !== null ? self::postVotesOf($plan) : [];
        $preview = $this->preview ?? [];
        $voterIds = match (true) {
            $this->voterMode === 'manual' => $this->voterIds,
            $this->preview !== null => $this->drawn,
            default => array_values(array_unique(array_column($saved, 'personaUserId'))),
        };
        $people = User::query()
            ->whereKey(array_values(array_unique([...$voterIds, ...array_column($saved, 'personaUserId'), ...array_column($preview, 'personaUserId'), ...($this->authorId !== null ? [$this->authorId] : [])])))
            ->with('avatarAsset.variants')
            ->get()
            ->keyBy('id');
        $cap = $this->cap();
        $settings = $this->settings();
        $postTotal = $settings->up + $settings->down;
        $need = max([$postTotal, ...array_map(fn (StudioRatingVotesSettings $rating): int => $rating->total(), $settings->ratings), 0]);
        $voters = $this->voterMode === 'manual' ? $this->eligibleChosen() : min($this->voterCount, $cap);

        return view('livewire.admin.studio-plan-post-votes-editor', [
            'editable' => $this->writable(),
            'author' => $this->authorId !== null ? StudioPlanFormat::author($people->get($this->authorId)) : null,
            'cap' => $cap,
            'voters' => $voters,
            'need' => $need,
            'postTotal' => $postTotal,
            'questions' => $questions,
            'stale' => $this->preview !== null && $this->previewFor !== $this->previewKey(),
            'changed' => $this->settingsKey() !== $this->restoredFor,
            'listed' => $this->voterMode === 'manual' ? $this->personaList()->map(fn (User $persona): array => [
                ...(StudioPlanFormat::author($persona) ?? []),
                'short' => Str::limit((string) ($persona->personaProfile->short_description ?? ''), 90),
                'on' => in_array((int) $persona->id, $this->voterIds, true),
                'author' => (int) $persona->id === $this->authorId,
            ])->values()->all() : [],
            'chosen' => array_map(fn (int $id): array => StudioPlanFormat::author($people->get($id)) ?? ['id' => $id, 'name' => 'Deleted user', 'username' => null, 'avatar' => null, 'initials' => '', 'status' => null, 'problem' => 'deleted'], $this->voterMode === 'manual' ? $this->voterIds : []),
            'savedRows' => $this->rows($saved, $people, $questions, $plan !== null ? $this->readiness($plan) : []),
            'previewRows' => $this->preview !== null ? $this->rows($preview, $people, $questions, []) : null,
            'voterTable' => $this->voterTable($preview !== [] ? $preview : $saved, $voterIds, $people, $questions),
            'timing' => $this->timingBlocks($settings, $questions),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Reads the plan as it is now — its version, its author and whether it
     * can be written — and the settings from its saved votes: how many vote
     * the post up and down, how many answer each question with which option,
     * their times, and their Personas, as chosen by hand. A plan with none
     * starts from a random draw.
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
        $this->authorId = $plan->author_user_id;
        $saved = self::postVotesOf($plan);
        $this->votesFingerprint = self::fingerprintOf($saved);

        $post = array_values(array_filter($saved, fn (array $vote): bool => $vote['type'] === StudioActionType::VotePost->value));
        $this->up = count(array_filter($post, fn (array $vote): bool => $vote['voteType'] === 'up'));
        $this->down = count($post) - $this->up;
        $this->postOn = $post !== [];
        [$this->postFirst, $this->postSpread] = self::timingOf($post, self::POST_TIMING);

        $this->ratings = [];

        foreach ($this->questions($saved) as $question) {
            $answers = array_values(array_filter($saved, fn (array $vote): bool => $vote['ratingGroupId'] === $question['id']));
            $counts = [];

            foreach ($question['options'] as $option) {
                $counts[$option['id']] = count(array_filter($answers, fn (array $vote): bool => $vote['ratingOptionId'] === $option['id']));
            }

            [$first, $spread] = self::timingOf($answers, self::RATING_TIMING);
            $this->ratings[$question['id']] = ['on' => $answers !== [], 'counts' => $counts, 'first' => $first, 'spread' => $spread];
        }

        $voters = array_values(array_unique(array_column($saved, 'personaUserId')));
        $this->voterMode = $voters !== [] ? 'manual' : 'random';
        $this->voterIds = $voters;
        $this->voterCount = $voters !== [] ? count($voters) : min(self::DEFAULT_VOTERS, $this->cap());
        $this->restoredFor = $this->settingsKey();
    }

    /** Takes a version the other editors' writes left, with this page's post votes as they are. */
    private function take(int $version): void
    {
        $this->lockVersion = $version;
        $this->conflict = false;
        // A save of the post may have given it another author: never a voter, and a preview made for the other one is out of date.
        $this->authorId = StudioPlan::query()->whereKey($this->planId)->value('author_user_id');
        $this->authorId = $this->authorId !== null ? (int) $this->authorId : null;
    }

    /** Whether the plan is at this version now, its post votes as this page last read or wrote them. */
    private function holdsSavedAt(int $version): bool
    {
        $plan = StudioPlan::query()->find($this->planId);

        return $plan !== null && $plan->lock_version === $version && hash_equals($this->votesFingerprint, self::fingerprintOf(self::postVotesOf($plan)));
    }

    /**
     * Writes the votes on the post and its questions as given, the
     * discussion's votes as the plan has them now, and takes the version the
     * save left. What the action refuses is said, nothing kept of it.
     *
     * @param  list<StudioVoteData>  $postVotes
     */
    private function write(array $postVotes, string $done): void
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return;
        }

        // Read at the version the save is checked against: a comment deleted since, with its votes, is not put back.
        $comments = array_values(array_filter(
            StudioVotesData::of($plan)->votes,
            fn (StudioVoteData $vote): bool => $vote->type === StudioActionType::VoteComment->value,
        ));

        try {
            $saved = app(SaveStudioVotesAction::class)->handle($this->admin(), $plan, new StudioVotesData([...$comments, ...$postVotes]), $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception);

            return;
        } catch (ValidationException $exception) {
            $this->toast((string) (collect($exception->errors())->flatten()->first() ?? 'These votes can’t be saved.'));

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

        try {
            $built = app(BuildStudioPostVotesData::class)->handle($plan, $this->settings());
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

    /** Whether something can be built or saved now: the plan writable, and the author the plan has the one the post shows. */
    private function ready(mixed $authorChanged): bool
    {
        if (! $this->writable()) {
            return false;
        }

        if ($authorChanged === true || $authorChanged === 1 || $authorChanged === '1' || $authorChanged === 'true') {
            $this->addError('author', 'The post’s author has unsaved changes. Save the draft, then prepare the votes: they are made for the author the draft has saved.');

            return false;
        }

        return true;
    }

    /** The settings as BuildStudioPostVotesData takes them. */
    private function settings(): StudioPostVotesSettings
    {
        $ratings = [];

        foreach ($this->ratings as $group => $rating) {
            if ($rating['on']) {
                $ratings[$group] = new StudioRatingVotesSettings((int) $group, $rating['counts'], $rating['first'], $rating['spread']);
            }
        }

        return new StudioPostVotesSettings(
            voterMode: $this->voterMode,
            voterCount: $this->voterCount,
            voterIds: $this->voterIds,
            up: $this->postOn ? $this->up : 0,
            down: $this->postOn ? $this->down : 0,
            postFirstSeconds: $this->postFirst,
            postSpreadSeconds: $this->postSpread,
            ratings: $ratings,
            seed: $this->seed ?? 1,
            keepSaved: $this->keepSaved,
        );
    }

    /** What the settings ask for, in one value: what the votes saved were drawn as, or a preview was made from. */
    private function settingsKey(): string
    {
        return hash('xxh3', json_encode([
            $this->voterMode, $this->voterMode === 'manual' ? $this->voterIds : $this->voterCount,
            $this->postOn ? [$this->up, $this->down, $this->postFirst, $this->postSpread] : null,
            array_map(fn (array $rating): ?array => $rating['on'] ? $rating : null, $this->ratings),
        ], JSON_THROW_ON_ERROR));
    }

    /** What a preview is made from: the settings, the draw, and the author it was made for. */
    private function previewKey(): string
    {
        return $this->settingsKey().'|'.($this->seed ?? 0).'|'.($this->keepSaved ? 1 : 0).'|'.($this->authorId ?? 0);
    }

    /**
     * The rating questions to answer: the active ones with their open
     * options, and — shown as no longer open — a question or an option a
     * saved answer names that is no longer one to choose. Nothing replaces
     * them: only fewer answers, or moving them, changes them.
     *
     * @param  list<array<string, mixed>>|null  $saved
     * @return list<array{id: int, label: string, prompt: string, open: bool, options: list<array{id: int, label: string, open: bool}>}>
     */
    private function questions(?array $saved = null): array
    {
        $saved ??= array_map(fn (int $group): array => ['ratingGroupId' => $group, 'ratingOptionId' => null], array_keys($this->ratings));
        $savedOptions = [];

        foreach ($saved as $vote) {
            if (($vote['ratingGroupId'] ?? null) !== null) {
                $savedOptions[(int) $vote['ratingGroupId']] ??= [];

                if (($vote['ratingOptionId'] ?? null) !== null) {
                    $savedOptions[(int) $vote['ratingGroupId']][] = (int) $vote['ratingOptionId'];
                }
            }
        }

        foreach ($this->ratings as $group => $rating) {
            $savedOptions[(int) $group] = [...($savedOptions[(int) $group] ?? []), ...array_map(intval(...), array_keys($rating['counts']))];
        }

        $questions = [];

        foreach (app(RatingConfigurationManager::class)->activeGroups() as $group) {
            $open = $group->options->map(fn (RatingOption $option): array => ['id' => (int) $option->id, 'label' => $option->translatedLabel(), 'open' => true])->all();
            $openIds = array_column($open, 'id');
            $closed = array_values(array_diff(array_unique($savedOptions[(int) $group->id] ?? []), $openIds));
            $questions[] = [
                'id' => (int) $group->id,
                'label' => $group->translatedLabel(),
                'prompt' => (string) ($group->description ?? ''),
                'open' => true,
                'options' => [...$open, ...self::closedOptions($closed)],
            ];
            unset($savedOptions[(int) $group->id]);
        }

        // A question no longer active, or deleted, still named by a saved answer.
        foreach ($savedOptions as $groupId => $options) {
            $group = RatingGroup::query()->find($groupId);
            $questions[] = [
                'id' => $groupId,
                'label' => $group?->translatedLabel() ?? 'Deleted rating question',
                'prompt' => 'No longer open: its answers can only be taken away.',
                'open' => false,
                'options' => self::closedOptions(array_values(array_unique($options))),
            ];
        }

        return $questions;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, label: string, open: bool}>
     */
    private static function closedOptions(array $ids): array
    {
        $options = RatingOption::query()->whereKey($ids)->get()->keyBy('id');

        return array_map(fn (int $id): array => [
            'id' => $id,
            'label' => $options->get($id)?->translatedLabel() ?? 'Deleted option',
            'open' => false,
        ], $ids);
    }

    /** @return list<int> the options of a question a new answer may go to, in their order */
    private function openOptions(int $group): array
    {
        foreach ($this->questions() as $question) {
            if ($question['id'] === $group) {
                return array_values(array_map(fn (array $option): int => $option['id'], array_filter($question['options'], fn (array $option): bool => $option['open'])));
            }
        }

        return [];
    }

    /** How many Personas can vote on the post: the active ones, but its author. */
    private function cap(): int
    {
        return count(array_filter(app(PersonasQuery::class)->activeIds(), fn (int $id): bool => $id !== $this->authorId));
    }

    /** How many of the Personas chosen may take a new vote: the active ones, but the post's author. */
    private function eligibleChosen(): int
    {
        return count(array_diff(array_intersect($this->voterIds, app(PersonasQuery::class)->activeIds()), $this->authorId !== null ? [$this->authorId] : []));
    }

    /** @return Collection<int, User> the active Personas listed to choose, matching the search */
    private function personaList(): Collection
    {
        return app(PersonasQuery::class)->activeChoices(mb_substr(trim($this->voterSearch), 0, 100), self::PERSONA_LIST)->load('personaProfile');
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
     * Votes as the summary lists them: who, on what, which way or which
     * answer, when — and what keeps a saved one from being cast.
     *
     * @param  list<array<string, mixed>>  $votes
     * @param  Collection<int, User>  $people
     * @param  list<array<string, mixed>>  $questions
     * @param  array<int, string>  $problems
     * @return list<array<string, mixed>>
     */
    private function rows(array $votes, Collection $people, array $questions, array $problems): array
    {
        $labels = self::labelsOf($questions);

        return array_map(fn (array $vote): array => [
            'persona' => StudioPlanFormat::author($people->get($vote['personaUserId'])),
            'target' => $vote['type'] === StudioActionType::VotePost->value ? 'Post' : ($labels['groups'][$vote['ratingGroupId']] ?? 'Rating question'),
            'vote' => $vote['type'] === StudioActionType::VotePost->value ? $vote['voteType'] : null,
            'answer' => $vote['type'] === StudioActionType::VoteRating->value ? ($labels['options'][$vote['ratingOptionId']] ?? 'Deleted option') : null,
            'when' => '+'.StudioPlanDiscussionEditor::relative((int) $vote['offsetSeconds']),
            'offset' => (int) $vote['offsetSeconds'],
            'problem' => $vote['id'] !== null ? ($problems[(int) $vote['id']] ?? null) : null,
        ], $votes);
    }

    /**
     * The Voters tab: each voter with its post vote and its answer to each
     * question, from the preview, or the saved votes when there is none.
     *
     * @param  list<array<string, mixed>>  $votes
     * @param  list<int>  $voterIds
     * @param  Collection<int, User>  $people
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function voterTable(array $votes, array $voterIds, Collection $people, array $questions): array
    {
        $labels = self::labelsOf($questions);
        $by = [];

        foreach ($votes as $vote) {
            $by[$vote['personaUserId']] ??= ['post' => null, 'answers' => []];

            if ($vote['type'] === StudioActionType::VotePost->value) {
                $by[$vote['personaUserId']]['post'] = $vote['voteType'];
            } else {
                $by[$vote['personaUserId']]['answers'][$vote['ratingGroupId']] = $labels['options'][$vote['ratingOptionId']] ?? 'Deleted option';
            }
        }

        $search = mb_strtolower(ltrim(trim($this->votersSearch), '@'));
        $rows = [];

        foreach (array_values(array_unique([...$voterIds, ...array_keys($by)])) as $id) {
            $persona = StudioPlanFormat::author($people->get($id));
            $voting = isset($by[$id]);

            if (($this->votersFilter === 'voting' && ! $voting) || ($this->votersFilter === 'idle' && $voting)
                || ($search !== '' && ! str_contains(mb_strtolower(($persona['name'] ?? '').' '.($persona['username'] ?? '')), $search))) {
                continue;
            }

            $rows[] = ['persona' => $persona, 'voting' => $voting, 'post' => $by[$id]['post'] ?? null, 'answers' => $by[$id]['answers'] ?? []];
        }

        usort($rows, fn (array $a, array $b): int => [$b['voting'], $a['persona']['name'] ?? ''] <=> [$a['voting'], $b['persona']['name'] ?? '']);

        return [
            'rows' => $rows,
            'questions' => array_values(array_filter($questions, fn (array $question): bool => ($this->ratings[$question['id']]['on'] ?? false) || in_array($question['id'], array_column($votes, 'ratingGroupId'), true))),
            'pool' => count(array_unique([...$voterIds, ...array_keys($by)])),
            'voting' => count($by),
        ];
    }

    /**
     * The Timing tab's blocks: the votes on the post, then each question
     * asked about, each with its moments offered and the one it has.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return list<array<string, mixed>>
     */
    private function timingBlocks(StudioPostVotesSettings $settings, array $questions): array
    {
        $chips = function (array $offered, int $value): array {
            $offered[$value] ??= StudioPlanDiscussionEditor::relative($value);
            ksort($offered);

            return array_map(fn (int $seconds, string $label): array => ['seconds' => $seconds, 'label' => $label, 'on' => $seconds === $value], array_keys($offered), $offered);
        };
        $meta = fn (int $count, int $first, int $spread, string $noun): string => $count === 0
            ? 'Off'
            : $count.' '.$noun.' · first '.StudioPlanDiscussionEditor::relative($first).' after, last '.StudioPlanDiscussionEditor::relative($first + ($count > 1 ? $spread : 0)).' after';

        $blocks = [[
            'key' => 'post', 'label' => 'Up and down votes', 'firstLabel' => 'First vote after', 'on' => $this->postOn,
            'meta' => $meta($settings->up + $settings->down, $this->postFirst, $this->postSpread, 'votes'),
            'first' => $chips(self::FIRST_CHOICES, $this->postFirst), 'spread' => $chips(self::SPREAD_CHOICES, $this->postSpread),
        ]];

        foreach ($questions as $question) {
            $rating = $this->ratings[$question['id']] ?? null;

            if ($rating !== null) {
                $blocks[] = [
                    'key' => (string) $question['id'], 'label' => $question['label'], 'firstLabel' => 'First answer after', 'on' => $rating['on'],
                    'meta' => $meta($rating['on'] ? array_sum($rating['counts']) : 0, $rating['first'], $rating['spread'], 'answers'),
                    'first' => $chips(self::FIRST_CHOICES, $rating['first']), 'spread' => $chips(self::SPREAD_CHOICES, $rating['spread']),
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array{groups: array<int, string>, options: array<int, string>}
     */
    private static function labelsOf(array $questions): array
    {
        $labels = ['groups' => [], 'options' => []];

        foreach ($questions as $question) {
            $labels['groups'][$question['id']] = $question['label'];

            foreach ($question['options'] as $option) {
                $labels['options'][$option['id']] = $option['label'];
            }
        }

        return $labels;
    }

    /**
     * The votes on the post and its questions as the plan has them saved, in
     * the order they are cast.
     *
     * @return list<array<string, mixed>>
     */
    private static function postVotesOf(StudioPlan $plan): array
    {
        return array_values(array_map(
            fn (StudioVoteData $vote): array => $vote->toArray(),
            array_filter(StudioVotesData::of($plan)->votes, fn (StudioVoteData $vote): bool => $vote->type !== StudioActionType::VoteComment->value),
        ));
    }

    /** @param  list<array<string, mixed>>  $votes */
    private static function fingerprintOf(array $votes): string
    {
        return hash('xxh3', json_encode($votes, JSON_THROW_ON_ERROR));
    }

    /**
     * When saved votes come: the first, and how long after it the last.
     *
     * @param  list<array<string, mixed>>  $votes
     * @param  array{0: int, 1: int}  $default
     * @return array{0: int, 1: int}
     */
    private static function timingOf(array $votes, array $default): array
    {
        if ($votes === []) {
            return $default;
        }

        $offsets = array_map(fn (array $vote): int => (int) $vote['offsetSeconds'], $votes);

        return [min($offsets), count($offsets) > 1 ? max($offsets) - min($offsets) : $default[1]];
    }

    /**
     * A whole spread over options in proportion to their weights, the
     * remainders to the largest fractions, then to the first.
     *
     * @param  list<int>  $options
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private static function split(array $options, array $weights, int $total): array
    {
        $sum = array_sum(array_intersect_key($weights, array_flip($options)));
        $shares = [];
        $rests = [];

        foreach ($options as $index => $option) {
            $exact = $sum > 0 ? $total * ($weights[$option] ?? 0) / $sum : 0;
            $shares[$option] = (int) floor($exact);
            $rests[] = [$exact - floor($exact), -$index, $option];
        }

        rsort($rests);

        for ($left = $total - array_sum($shares), $at = 0; $left > 0 && $rests !== []; $left--, $at++) {
            $shares[$rests[$at % count($rests)][2]]++;
        }

        return $shares;
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
