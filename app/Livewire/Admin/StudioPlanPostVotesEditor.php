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
 * Every change is saved at once, as the prototype's draft changes with each
 * setting: the votes the settings ask for are made (BuildStudioPostVotesData)
 * and saved through SaveStudioVotesAction, a Persona keeping its saved vote
 * and its moment as far as the settings allow, so a change moves only what it
 * must. The votes on the discussion's comments are kept as the plan has them
 * at that moment; only the votes on the post and its rating questions are
 * replaced. A change that can't be made — too few Personas, a moment past
 * thirty days — is said where it is, and nothing is saved until it is fixed,
 * or Discard goes back to the votes saved. Each vote's moment is set by hand
 * on the Timing tab. Opened again, the editor draws the votes as they are
 * saved: their counts, their Personas, their options and their times, the
 * Personas as chosen by hand. Nothing is cast — prepared votes are no votes
 * on the site.
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
     * The votes on the post given a moment of their own, by Persona: seconds
     * after the post.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $postMoments = [];

    /**
     * Each rating question: how many answers each option gets, when, and the
     * answers given a moment of their own, by Persona. An active question is
     * always one to answer, as it is under every post on the site.
     *
     * @var array<int, array{counts: array<int, int>, first: int, spread: int, moments: array<int, int>}>
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
     * The Personas the last draw took part, voting or not.
     *
     * @var list<int>
     */
    #[Locked]
    public array $drawn = [];

    /** The settings the saved votes were made from (settingsKey()): any other are not saved. */
    #[Locked]
    public string $savedFor = '';

    /** Whether Remove is asking first. */
    #[Locked]
    public bool $confirmingRemove = false;

    /**
     * Whether the post's author has unsaved changes, as the page says: the
     * votes are made for the author the draft has saved, so none are saved
     * meanwhile.
     */
    public bool $authorChanged = false;

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

    public function updatedPostOn(): void
    {
        $this->commit();
    }

    public function updatedUp(): void
    {
        $this->up = self::count($this->up);
        $this->commit();
    }

    public function updatedDown(): void
    {
        $this->down = self::count($this->down);
        $this->commit();
    }

    public function updatedVoterCount(): void
    {
        $this->voterCount = self::count($this->voterCount);
        $this->commit();
    }

    /** Choose starts from the voters drawn, so switching to it changes no vote. */
    public function updatedVoterMode(): void
    {
        $this->voterMode = $this->voterMode === 'manual' ? 'manual' : 'random';

        if ($this->voterMode === 'manual' && $this->voterIds === []) {
            $this->voterIds = $this->drawn !== [] ? $this->drawn : $this->savedVoters();
        }

        $this->commit();
    }

    public function updatedVotersFilter(): void
    {
        $this->votersFilter = in_array($this->votersFilter, ['all', 'voting', 'idle'], true) ? $this->votersFilter : 'all';
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
        $this->commit();
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
        $this->commit();
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

        if ($counts !== $this->ratings[$group]['counts']) {
            $this->ratings[$group]['counts'] = $counts;
            $this->commit();
        }
    }

    /**
     * First vote after, and Spread over: one of the moments offered, or the
     * one it has — the target's votes spread evenly again, none at a moment of
     * its own.
     */
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
                $this->postMoments = [];
                $this->commit();
            }

            return;
        }

        $group = (int) $block;

        if (isset($this->ratings[$group]) && in_array($seconds, [...$offered, $this->ratings[$group][$field]], true)) {
            $this->ratings[$group][$field] = $seconds;
            $this->ratings[$group]['moments'] = [];
            $this->commit();
        }
    }

    /**
     * A vote's moment, set by hand on the Timing tab: in seconds after the
     * post, never before it nor more than thirty days after. The target's
     * other votes keep the moments they have; a vote added to it later comes
     * in the spread, and First vote after or Spread over spreads them all
     * again.
     */
    public function setMoment(mixed $target, mixed $persona, mixed $seconds): void
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;
        $group = $target === 'post' ? null : (is_numeric($target) ? (int) $target : -1);

        if ($plan === null || ! $this->writable() || ! is_numeric($persona) || ! is_numeric($seconds) || ($group !== null && ! isset($this->ratings[$group]))) {
            return;
        }

        $offsets = self::offsetsOn(self::postVotesOf($plan), $group);
        [$persona, $seconds] = [(int) $persona, (int) $seconds];

        if (! isset($offsets[$persona])) {
            return;
        }

        if ($seconds < 0 || $seconds > StudioVoteValidationRules::LATEST_OFFSET_SECONDS) {
            $this->toast($seconds < 0 ? 'Nothing can arrive before the post.' : 'A vote comes at most 30 days after the post.');

            return;
        }

        $offsets[$persona] = $seconds;
        ksort($offsets);
        $group === null ? $this->postMoments = $offsets : $this->ratings[$group]['moments'] = $offsets;
        $this->commit();
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
            $this->commit();

            return;
        }

        if ($id !== $this->authorId && app(PersonasQuery::class)->active($id) !== null) {
            $this->voterIds[] = $id;
            $this->commit();
        }
    }

    /** Chooses every Persona the search shows that may vote, or takes them away when all are chosen. */
    public function selectShown(): void
    {
        $shown = $this->personaList()->map(fn (User $persona): int => (int) $persona->id)->reject(fn (int $id): bool => $id === $this->authorId)->values()->all();

        $this->voterIds = array_diff($shown, $this->voterIds) === []
            ? array_values(array_diff($this->voterIds, $shown))
            : array_values(array_unique([...$this->voterIds, ...$shown]));
        $this->commit();
    }

    public function clearVoters(): void
    {
        $this->voterIds = [];
        $this->commit();
    }

    // ------------------------------------------------------------------
    // Reshuffle, Remove and Discard
    // ------------------------------------------------------------------

    /**
     * Pick others, or Reshuffle: a new draw, with the same numbers, the saved
     * votes no longer preferred and every vote spread evenly again — saved at
     * once.
     */
    public function redraw(): void
    {
        if (! $this->writable()) {
            return;
        }

        $this->seed = random_int(1, 2_147_483_647);
        $this->keepSaved = false;
        $this->postMoments = [];

        foreach (array_keys($this->ratings) as $group) {
            $this->ratings[$group]['moments'] = [];
        }

        $this->commit();
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

    /** Every vote on the post and its questions taken away, and the settings asking for none. */
    public function confirmRemove(): void
    {
        $this->confirmingRemove = false;
        $this->resetErrorBag();

        if (! $this->writable() || ! $this->write([])) {
            return;
        }

        $this->postOn = false;
        $this->postMoments = [];

        foreach ($this->ratings as $group => $rating) {
            $this->ratings[$group] = [...$rating, 'counts' => array_map(fn (): int => 0, $rating['counts']), 'moments' => []];
        }

        $this->savedFor = $this->settingsKey();
        $this->toast('Post votes removed', 'success');
    }

    /** Discard: the settings as the saved votes have them, what could not be saved gone. */
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
     * taken when this page's post votes are still the saved ones, the
     * settings kept; otherwise the votes are drawn again from what is saved.
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
        $voterIds = match (true) {
            $this->voterMode === 'manual' => $this->voterIds,
            $this->drawn !== [] => $this->drawn,
            default => array_values(array_unique(array_column($saved, 'personaUserId'))),
        };
        $people = User::query()
            ->whereKey(array_values(array_unique([...$voterIds, ...array_column($saved, 'personaUserId'), ...($this->authorId !== null ? [$this->authorId] : [])])))
            ->with('avatarAsset.variants')
            ->get()
            ->keyBy('id');
        $rows = $this->rows($saved, $people, $questions, $plan !== null ? $this->readiness($plan) : []);
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
            'unsaved' => $this->settingsKey() !== $this->savedFor,
            'listed' => $this->voterMode === 'manual' ? $this->personaList()->map(fn (User $persona): array => [
                ...(StudioPlanFormat::author($persona) ?? []),
                'short' => Str::limit((string) ($persona->personaProfile->short_description ?? ''), 90),
                'on' => in_array((int) $persona->id, $this->voterIds, true),
                'author' => (int) $persona->id === $this->authorId,
            ])->values()->all() : [],
            'chosen' => array_map(fn (int $id): array => StudioPlanFormat::author($people->get($id)) ?? ['id' => $id, 'name' => 'Deleted user', 'username' => null, 'avatar' => null, 'initials' => '', 'status' => null, 'problem' => 'deleted'], $this->voterMode === 'manual' ? $this->voterIds : []),
            'rows' => $rows,
            'voterTable' => $this->voterTable($saved, $voterIds, $people, $questions),
            'timing' => $this->timingBlocks($questions, $rows),
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
        $this->drawn = [];
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
        [$this->postFirst, $this->postSpread, $this->postMoments] = self::timingOf(self::offsetsOn($saved, null), self::POST_TIMING);

        $this->ratings = [];

        foreach ($this->questions($saved) as $question) {
            $answers = array_values(array_filter($saved, fn (array $vote): bool => $vote['ratingGroupId'] === $question['id']));
            $counts = [];

            foreach ($question['options'] as $option) {
                $counts[$option['id']] = count(array_filter($answers, fn (array $vote): bool => $vote['ratingOptionId'] === $option['id']));
            }

            [$first, $spread, $moments] = self::timingOf(self::offsetsOn($saved, $question['id']), self::RATING_TIMING);
            $this->ratings[$question['id']] = ['counts' => $counts, 'first' => $first, 'spread' => $spread, 'moments' => $moments];
        }

        $voters = $this->savedVoters($saved);
        $this->voterMode = $voters !== [] ? 'manual' : 'random';
        $this->voterIds = $voters;
        $this->voterCount = $voters !== [] ? count($voters) : min(self::DEFAULT_VOTERS, $this->cap());
        $this->savedFor = $this->settingsKey();
    }

    /** Takes a version the other editors' writes left, with this page's post votes as they are. */
    private function take(int $version): void
    {
        $this->lockVersion = $version;
        $this->conflict = false;
        // A save of the post may have given it another author: never a voter from the next change on.
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
     * Saves the votes the settings ask for now: made by
     * BuildStudioPostVotesData — a Persona keeping its saved vote and moment
     * as far as the settings allow — and written, unless they are the votes
     * saved already. What can't be made is said where it is, and nothing is
     * saved until it is fixed. Nothing is saved while the post's author has
     * unsaved changes: the votes are made for the author the plan has.
     */
    private function commit(): void
    {
        if (! $this->writable()) {
            return;
        }

        $this->resetErrorBag();

        if ($this->authorChanged) {
            $this->addError('author', 'The post’s author has unsaved changes. Save the draft, then prepare the votes: they are made for the author the draft has saved.');

            return;
        }

        $plan = $this->writablePlan();

        if ($plan === null) {
            return;
        }

        $this->seed ??= random_int(1, 2_147_483_647);

        try {
            $built = app(BuildStudioPostVotesData::class)->handle($plan, $this->settings());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, (string) ($messages[0] ?? ''));
            }

            return;
        }

        $this->drawn = $built->voters;
        $votes = $built->votes->votes;

        if (self::essence(array_map(fn (StudioVoteData $vote): array => $vote->toArray(), $votes)) === self::essence(self::postVotesOf($plan)) || $this->write($votes)) {
            $this->saved();
        }
    }

    /**
     * The settings are the saved votes' now: the saved ones preferred again,
     * and a moment kept only for a Persona voting there.
     */
    private function saved(): void
    {
        $plan = StudioPlan::query()->find($this->planId);
        $saved = $plan !== null ? self::postVotesOf($plan) : [];
        $this->keepSaved = true;
        $this->postMoments = array_intersect_key($this->postMoments, self::offsetsOn($saved, null));

        foreach (array_keys($this->ratings) as $group) {
            $this->ratings[$group]['moments'] = array_intersect_key($this->ratings[$group]['moments'], self::offsetsOn($saved, $group));
        }

        $this->savedFor = $this->settingsKey();
    }

    /**
     * Writes the votes on the post and its questions as given, the
     * discussion's votes as the plan has them now, and takes the version the
     * save left. What the action refuses is said, nothing kept of it.
     *
     * @param  list<StudioVoteData>  $postVotes
     */
    private function write(array $postVotes): bool
    {
        $plan = $this->writablePlan();

        if ($plan === null) {
            return false;
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

            return false;
        } catch (ValidationException $exception) {
            $this->toast((string) (collect($exception->errors())->flatten()->first() ?? 'These votes can’t be saved.'));

            return false;
        }

        $this->settle($saved);

        return true;
    }

    /** Takes the version a save left and the votes it saved, and says the version to the other editors on the page. */
    private function settle(SavedStudioVotes $saved): void
    {
        $previous = $this->lockVersion;
        $this->lockVersion = $saved->lockVersion;
        $plan = StudioPlan::query()->find($this->planId);
        $this->votesFingerprint = self::fingerprintOf($plan !== null ? self::postVotesOf($plan) : []);

        $this->dispatch(StudioPlanDiscussionEditor::VERSION_EVENT, planId: $this->planId, previous: $previous, current: $this->lockVersion, source: self::SOURCE);
    }

    /** The settings as BuildStudioPostVotesData takes them. */
    private function settings(): StudioPostVotesSettings
    {
        $ratings = [];

        foreach ($this->ratings as $group => $rating) {
            $ratings[$group] = new StudioRatingVotesSettings((int) $group, $rating['counts'], $rating['first'], $rating['spread'], $rating['moments']);
        }

        return new StudioPostVotesSettings(
            voterMode: $this->voterMode,
            voterCount: $this->voterCount,
            voterIds: $this->voterIds,
            up: $this->postOn ? $this->up : 0,
            down: $this->postOn ? $this->down : 0,
            postFirstSeconds: $this->postFirst,
            postSpreadSeconds: $this->postSpread,
            postMoments: $this->postMoments,
            ratings: $ratings,
            seed: $this->seed ?? 1,
            keepSaved: $this->keepSaved,
        );
    }

    /** What the settings ask for, in one value, with the draw: what the saved votes were made from. */
    private function settingsKey(): string
    {
        return hash('xxh3', json_encode([
            $this->voterMode, $this->voterMode === 'manual' ? $this->voterIds : $this->voterCount,
            $this->postOn ? [$this->up, $this->down, $this->postFirst, $this->postSpread, $this->postMoments] : null,
            $this->ratings, $this->seed,
        ], JSON_THROW_ON_ERROR));
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
     * The votes as the Timing tab lists them, in the order they come: who,
     * when, on what, which way or which answer — and what keeps one from
     * being cast.
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
            'personaId' => (int) $vote['personaUserId'],
            'block' => $vote['type'] === StudioActionType::VotePost->value ? 'post' : (string) $vote['ratingGroupId'],
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
     * question, as saved.
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
            'questions' => array_values(array_filter($questions, fn (array $question): bool => array_sum($this->ratings[$question['id']]['counts'] ?? []) > 0 || in_array($question['id'], array_column($votes, 'ratingGroupId'), true))),
            'pool' => count(array_unique([...$voterIds, ...array_keys($by)])),
            'voting' => count($by),
        ];
    }

    /**
     * The Timing tab's blocks: the votes on the post, then each question,
     * each with when its votes come as saved, the moments offered and the
     * one it has, and whether a vote of it has a moment of its own.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function timingBlocks(array $questions, array $rows): array
    {
        $chips = function (array $offered, int $value): array {
            $offered[$value] ??= StudioPlanDiscussionEditor::relative($value);
            ksort($offered);

            return array_map(fn (int $seconds, string $label): array => ['seconds' => $seconds, 'label' => $label, 'on' => $seconds === $value], array_keys($offered), $offered);
        };
        $block = function (string $key, string $label, string $first, string $noun, bool $on, int $firstSeconds, int $spreadSeconds, array $moments) use ($chips, $rows): array {
            $offsets = array_column(array_filter($rows, fn (array $row): bool => $row['block'] === $key), 'offset');

            return [
                'key' => $key, 'label' => $label, 'firstLabel' => $first, 'on' => $on,
                'meta' => $offsets === [] ? 'None saved' : count($offsets).' '.$noun.' · first '.StudioPlanDiscussionEditor::relative(min($offsets)).' after, last '.StudioPlanDiscussionEditor::relative(max($offsets)).' after',
                'first' => $chips(self::FIRST_CHOICES, $firstSeconds), 'spread' => $chips(self::SPREAD_CHOICES, $spreadSeconds),
                'byHand' => $moments !== [],
            ];
        };

        $blocks = [$block('post', 'Up and down votes', 'First vote after', 'votes', $this->postOn, $this->postFirst, $this->postSpread, $this->postMoments)];

        foreach ($questions as $question) {
            $rating = $this->ratings[$question['id']] ?? null;

            if ($rating !== null) {
                $blocks[] = $block((string) $question['id'], $question['label'], 'First answer after', 'answers', array_sum($rating['counts']) > 0, $rating['first'], $rating['spread'], $rating['moments']);
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
     * When a target's saved votes come: the first, how long after it the
     * last, and — when any comes away from its place in that spread — the
     * moment of each, so a vote set by hand keeps its own and the rest keep
     * theirs.
     *
     * @param  array<int, int>  $offsets  by Persona
     * @param  array{0: int, 1: int}  $default
     * @return array{0: int, 1: int, 2: array<int, int>}
     */
    private static function timingOf(array $offsets, array $default): array
    {
        if ($offsets === []) {
            return [...$default, []];
        }

        $order = array_keys($offsets);
        usort($order, fn (int $a, int $b): int => [$offsets[$a], $a] <=> [$offsets[$b], $b]);
        $first = min($offsets);
        $spread = count($offsets) > 1 ? max($offsets) - $first : $default[1];

        foreach ($order as $place => $persona) {
            if ($offsets[$persona] !== $first + (count($order) > 1 ? intdiv($place * $spread, count($order) - 1) : 0)) {
                ksort($offsets);

                return [$first, $spread, $offsets];
            }
        }

        return [$first, $spread, []];
    }

    /**
     * When each saved vote on the post (`$group` null), or each answer to a
     * question, comes, by Persona.
     *
     * @param  list<array<string, mixed>>  $votes
     * @return array<int, int>
     */
    private static function offsetsOn(array $votes, ?int $group): array
    {
        $offsets = [];

        foreach ($votes as $vote) {
            if ($group === null ? $vote['type'] === StudioActionType::VotePost->value : $vote['ratingGroupId'] === $group) {
                $offsets[(int) $vote['personaUserId']] = (int) $vote['offsetSeconds'];
            }
        }

        return $offsets;
    }

    /**
     * What votes are, apart from their ids and places: who, on what, which
     * way or answer, and when — so a draw that makes the saved votes again
     * writes nothing.
     *
     * @param  list<array<string, mixed>>  $votes
     * @return list<string>
     */
    private static function essence(array $votes): array
    {
        $essence = array_map(fn (array $vote): string => implode('|', [$vote['type'], $vote['personaUserId'], $vote['voteType'] ?? '', $vote['ratingGroupId'] ?? '', $vote['ratingOptionId'] ?? '', $vote['offsetSeconds']]), $votes);
        sort($essence);

        return $essence;
    }

    /**
     * The Personas the saved votes are given to, in the order they come.
     *
     * @param  list<array<string, mixed>>|null  $saved
     * @return list<int>
     */
    private function savedVoters(?array $saved = null): array
    {
        $plan = $saved === null && $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;
        $saved ??= $plan !== null ? self::postVotesOf($plan) : [];

        return array_values(array_unique(array_map(intval(...), array_column($saved, 'personaUserId'))));
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
