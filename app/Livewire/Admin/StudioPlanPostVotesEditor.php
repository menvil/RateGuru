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
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
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
use Livewire\Attributes\Renderless;
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
 * setting — once for a request, however many settings it carries: the votes
 * the settings ask for are made (BuildStudioPostVotesData) and saved through
 * SaveStudioVotesAction, a Persona keeping its saved vote and its moment as
 * far as the settings allow, so a change moves only what it must. Drawn at
 * random, the voters grow with the votes and answers asked for. The votes on
 * the discussion's comments are kept as the plan has them at that moment;
 * only the votes on the post and its rating questions are replaced. A change
 * that can't be made — too few Personas, a moment past thirty days — is said
 * where it is, and nothing is saved until it is fixed, or Discard goes back
 * to the votes saved. Each vote's moment is set by hand on the Timing tab.
 * Opened again, the editor draws the votes as they are saved: their counts,
 * their Personas, their options and their times, and the voters chosen as
 * this session left them (VOTERS_SESSION) — or, with nothing kept, drawn at
 * random, the Personas voting kept in the draw. Nothing is cast — prepared
 * votes are no votes on the site.
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

    /** When the voters come by default: the first after, and how long they are spread over, in seconds. */
    private const TIMING = [300, 86400];

    /**
     * Said to itself when a save of the post gave it another author: the votes
     * are made again on a request of their own, once every editor on the page
     * has taken the version that save left.
     */
    public const REMAKE_EVENT = 'studio-post-votes-remake';

    /** The page asking, before it leaves the Post step, whether a change not saved may go. */
    public const LEAVE_EVENT = 'studio-post-votes-leave';

    /** How many voters a random draw starts with, at most. */
    private const DEFAULT_VOTERS = 20;

    /**
     * Where how the voters are chosen — the mode, the Personas chosen, how
     * many drawn — is kept in the session, by plan, so the editor opened
     * again chooses them as it was left.
     */
    public const VOTERS_SESSION = 'rg-admin-studio-post-voters';

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

    /**
     * The author picked on the page and not saved yet, as the page says
     * (followAuthor()): the post's author to be, who never votes on it either.
     */
    #[Locked]
    public ?int $authorToBe = null;

    /** Whether a change here is not saved, for the page to ask about before it leaves the step. */
    #[Locked]
    public bool $unsaved = false;

    /** Where the page was asked to go while a change was not saved: asked first. */
    #[Locked]
    public ?string $leaving = null;

    /** The card's tab on screen: the votes and answers, the voters, or their timing. */
    public string $tab = 'votes';

    /** Whether the post is to get up and down votes at all. */
    public bool $postOn = false;

    public int $up = 0;

    public int $down = 0;

    /**
     * When the first voter comes, and how long they are spread over, in
     * seconds: each votes on the post and answers the questions in one visit.
     */
    #[Locked]
    public int $firstAfter = 300;

    #[Locked]
    public int $spreadOver = 86400;

    /**
     * The votes on the post given a moment of their own, by Persona: seconds
     * after the post.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $postMoments = [];

    /**
     * Each rating question: how many answers each option gets, and the
     * answers given a moment of their own, by Persona. An active question is
     * always one to answer, as it is under every post on the site.
     *
     * @var array<int, array{counts: array<int, int>, moments: array<int, int>}>
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
     * Whether a setting changed on this request: the votes are saved once,
     * as the request leaves them, before the editor is drawn — never once
     * for each setting a request carries.
     */
    private bool $changed = false;

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
        $this->growVoters();
        $this->change();
    }

    public function updatedUp(): void
    {
        $this->up = self::count($this->up);
        $this->growVoters();
        $this->change();
    }

    public function updatedDown(): void
    {
        $this->down = self::count($this->down);
        $this->growVoters();
        $this->change();
    }

    public function updatedVoterCount(): void
    {
        $this->voterCount = self::count($this->voterCount);
        $this->change();
    }

    /** Choose starts from the voters drawn, so switching to it changes no vote. */
    public function updatedVoterMode(): void
    {
        $this->voterMode = $this->voterMode === 'manual' ? 'manual' : 'random';

        if ($this->voterMode === 'manual' && $this->voterIds === []) {
            $this->voterIds = $this->drawn !== [] ? $this->drawn : $this->savedVoters();
        }

        $this->growVoters();
        $this->change();
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
        $this->growVoters();
        $this->change();
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
        $this->change();
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
            $this->change();
        }
    }

    /**
     * An option's answers, typed: that many go to it, the others keep theirs
     * and the question's total follows. An option no longer open only gives
     * answers up, never takes new ones.
     */
    public function setAnswerCount(mixed $group, mixed $option, mixed $count): void
    {
        [$group, $option] = [(int) $group, (int) $option];

        if (! isset($this->ratings[$group]['counts'][$option]) || ! is_numeric($count)) {
            return;
        }

        $current = $this->ratings[$group]['counts'][$option];
        $count = self::count((int) $count);

        if (! in_array($option, $this->openOptions($group), true)) {
            $count = min($count, $current);
        }

        if ($count !== $current) {
            $this->ratings[$group]['counts'][$option] = $count;
            $this->growVoters();
            $this->change();
        }
    }

    /**
     * First vote after, and Spread over: one of the moments offered, or the
     * one it has — the voters spread evenly again, no vote at a moment of its
     * own.
     */
    public function setTiming(mixed $field, mixed $seconds): void
    {
        if (! is_numeric($seconds) || ! in_array($field, ['first', 'spread'], true)) {
            return;
        }

        $seconds = (int) $seconds;
        $current = $field === 'first' ? $this->firstAfter : $this->spreadOver;

        if (! in_array($seconds, [...array_keys($field === 'first' ? self::FIRST_CHOICES : self::SPREAD_CHOICES), $current], true)) {
            return;
        }

        $field === 'first' ? $this->firstAfter = $seconds : $this->spreadOver = $seconds;
        $this->clearMoments();
        $this->change();
    }

    /**
     * A vote's moment, set by hand on the Timing tab: in seconds after the
     * post, never before it nor more than thirty days after. Every other vote
     * keeps the moment it has; a vote added later comes in its voter's visit,
     * and First vote after or Spread over lays them all out again.
     */
    public function setMoment(mixed $target, mixed $persona, mixed $seconds): void
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;
        $group = $target === 'post' ? null : (is_numeric($target) ? (int) $target : -1);

        if ($plan === null || ! $this->writable() || ! is_numeric($persona) || ! is_numeric($seconds) || ($group !== null && ! isset($this->ratings[$group]))) {
            return;
        }

        $saved = self::postVotesOf($plan);
        [$persona, $seconds] = [(int) $persona, (int) $seconds];

        if (! isset(self::offsetsOn($saved, $group)[$persona])) {
            return;
        }

        if ($seconds < 0 || $seconds > StudioVoteValidationRules::LATEST_OFFSET_SECONDS) {
            $this->toast($seconds < 0 ? 'Nothing can arrive before the post.' : 'A vote comes at most 30 days after the post.');

            return;
        }

        $this->postMoments = self::offsetsOn($saved, null);

        foreach (array_keys($this->ratings) as $question) {
            $this->ratings[$question]['moments'] = self::offsetsOn($saved, $question);
        }

        $group === null ? $this->postMoments[$persona] = $seconds : $this->ratings[$group]['moments'][$persona] = $seconds;
        ksort($this->postMoments);

        foreach (array_keys($this->ratings) as $question) {
            ksort($this->ratings[$question]['moments']);
        }

        $this->change();
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
            $this->change();

            return;
        }

        if (! in_array($id, $this->authors(), true) && app(PersonasQuery::class)->active($id) !== null) {
            $this->voterIds[] = $id;
            $this->change();
        }
    }

    /** Chooses every Persona the search shows that may vote, or takes them away when all are chosen. */
    public function selectShown(): void
    {
        $shown = $this->personaList()->map(fn (User $persona): int => (int) $persona->id)->reject(fn (int $id): bool => in_array($id, $this->authors(), true))->values()->all();

        $this->voterIds = array_diff($shown, $this->voterIds) === []
            ? array_values(array_diff($this->voterIds, $shown))
            : array_values(array_unique([...$this->voterIds, ...$shown]));
        $this->change();
    }

    public function clearVoters(): void
    {
        $this->voterIds = [];
        $this->change();
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
        $this->clearMoments();
        $this->change();
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
        $this->rememberVoters();
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

    /** The votes made again for the author a save of the post gave it. */
    #[On(self::REMAKE_EVENT)]
    public function remake(): void
    {
        $this->commit();
    }

    /**
     * The page's author changed, saved or not (`$store.adminEditor`): one
     * picked and not saved yet is the post's author to be, left out of the
     * voters at once — its votes, if it has any, go to others — as the saved
     * one is.
     */
    public function followAuthor(mixed $id): void
    {
        $id = is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        $toBe = $id !== $this->authorId ? $id : null;

        if ($toBe === $this->authorToBe) {
            return;
        }

        $this->authorToBe = $toBe;
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan !== null) {
            $this->leaveOutAuthor(self::postVotesOf($plan));
            $this->commit();
        }
    }

    /**
     * The step's Back, Continue or one of its tabs, asked while a change here
     * is not saved: it is tried once more, and when it still can't be saved —
     * what keeps it said again — whether to discard it or stay. Asked with
     * nothing unsaved, it goes on at once.
     */
    #[On(self::LEAVE_EVENT)]
    public function askLeave(mixed $step): void
    {
        if (! is_string($step) || ! in_array($step, ['next', 'previous', ...array_keys(EditStudioPlan::STEPS)], true)) {
            return;
        }

        if ($this->settingsKey() !== $this->savedFor) {
            $this->commit();
        }

        if ($this->settingsKey() !== $this->savedFor) {
            $this->leaving = $step;

            return;
        }

        $this->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: $step);
    }

    public function stay(): void
    {
        $this->leaving = null;
    }

    /** Discard changes: the votes as saved, and on to where it was asked. */
    public function discardAndLeave(): void
    {
        $to = $this->leaving;
        $this->leaving = null;

        if ($to !== null) {
            $this->discard();
            $this->dispatch(StudioPlanDiscussionVotesEditor::STEP_EVENT, step: $to);
        }
    }

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
     * Drawn again only for the post's own save — its author, its date — or a
     * conflict: the discussion's writes change nothing drawn here.
     */
    #[On(StudioPlanDiscussionEditor::VERSION_EVENT)]
    #[Renderless]
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
            $author = $this->authorId;
            $this->take($current);
            $this->drawnAgainIf($source === 'editor' || $this->authorId !== $author);

            return;
        }

        $this->conflict = true;
        $this->forceRender();
    }

    /**
     * Draws the editor again only when something it shows changed. The
     * listener is renderless (#[Renderless]), which Livewire honours only when
     * a request carries nothing else for this editor: a click, or a setting,
     * on the same request is drawn — and saved — as ever.
     */
    private function drawnAgainIf(bool $changed): void
    {
        if ($changed) {
            $this->forceRender();
        }
    }

    /** A field set on a request that also carries another editor's write is drawn too. */
    public function updated(): void
    {
        $this->forceRender();
    }

    public function render(): View
    {
        if ($this->changed) {
            $this->commit();
        }

        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;
        $questions = $this->questions();
        $saved = $plan !== null ? self::postVotesOf($plan) : [];
        $voterIds = match (true) {
            $this->voterMode === 'manual' => $this->voterIds,
            $this->drawn !== [] => $this->drawn,
            default => array_values(array_unique(array_column($saved, 'personaUserId'))),
        };
        $people = User::query()
            ->whereKey(array_values(array_unique([...$voterIds, ...array_column($saved, 'personaUserId'), ...$this->authors()])))
            ->with('avatarAsset.variants')
            ->get()
            ->keyBy('id');
        $rows = $this->rows($saved, $people, $questions, $plan !== null ? $this->readiness($plan) : []);
        $cap = $this->cap();
        $settings = $this->settings();
        $postTotal = $settings->up + $settings->down;
        $need = max([$postTotal, ...array_map(fn (StudioRatingVotesSettings $rating): int => $rating->total(), $settings->ratings), 0]);
        $voters = $this->voterMode === 'manual' ? $this->eligibleChosen() : min($this->voterCount, $cap);
        $this->unsaved = $this->settingsKey() !== $this->savedFor;
        $author = $this->authorToBe ?? $this->authorId;

        return view('livewire.admin.studio-plan-post-votes-editor', [
            'editable' => $this->writable(),
            'author' => $author !== null ? StudioPlanFormat::author($people->get($author)) : null,
            'authorSaved' => $this->authorToBe === null,
            'authors' => $this->authors(),
            'cap' => $cap,
            'voters' => $voters,
            'need' => $need,
            'postTotal' => $postTotal,
            'questions' => $questions,
            'unsaved' => $this->unsaved,
            'listed' => $this->voterMode === 'manual' ? $this->personaList()->map(fn (User $persona): array => [
                ...(StudioPlanFormat::author($persona) ?? []),
                'short' => Str::limit((string) ($persona->personaProfile->short_description ?? ''), 90),
                'on' => in_array((int) $persona->id, $this->voterIds, true),
                'author' => in_array((int) $persona->id, $this->authors(), true),
            ])->values()->all() : [],
            'chosen' => array_map(fn (int $id): array => StudioPlanFormat::author($people->get($id)) ?? ['id' => $id, 'name' => 'Deleted user', 'username' => null, 'avatar' => null, 'initials' => '', 'status' => null, 'problem' => 'deleted'], $this->voterMode === 'manual' ? $this->voterIds : []),
            'rows' => $rows,
            'voterTable' => $this->voterTable($saved, $voterIds, $people, $questions),
            'timing' => $this->timing($rows),
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
        $this->authorToBe = $this->authorToBe === $this->authorId ? null : $this->authorToBe;
        $saved = self::postVotesOf($plan);
        $this->votesFingerprint = self::fingerprintOf($saved);

        $post = array_values(array_filter($saved, fn (array $vote): bool => $vote['type'] === StudioActionType::VotePost->value));
        $this->up = count(array_filter($post, fn (array $vote): bool => $vote['voteType'] === 'up'));
        $this->down = count($post) - $this->up;
        $this->postOn = $post !== [];
        $questions = $this->questions($saved);
        [$this->firstAfter, $this->spreadOver, $moments] = self::timingOf($saved, [null, ...array_column($questions, 'id')]);
        $this->postMoments = $moments[0];
        $this->ratings = [];

        foreach ($questions as $place => $question) {
            $answers = array_values(array_filter($saved, fn (array $vote): bool => $vote['ratingGroupId'] === $question['id']));
            $counts = [];

            foreach ($question['options'] as $option) {
                $counts[$option['id']] = count(array_filter($answers, fn (array $vote): bool => $vote['ratingOptionId'] === $option['id']));
            }

            $this->ratings[$question['id']] = ['counts' => $counts, 'moments' => $moments[$place + 1]];
        }

        $this->restoreVoters($this->savedVoters($saved));
        $this->savedFor = $this->settingsKey();

        if ($this->leaveOutAuthor($saved)) {
            $this->dispatch(self::REMAKE_EVENT)->self();
        }
    }

    /**
     * Takes a version the other editors' writes left, with this page's post
     * votes as they are. A save of the post that gave it another author has
     * the votes made again right after (`remake()`), the new author left out
     * (`leaveOutAuthor()`). Not here: the other editors take the same version
     * on the same request, and a write now would move it under them.
     */
    private function take(int $version): void
    {
        $this->lockVersion = $version;
        $this->conflict = false;
        $plan = StudioPlan::query()->find($this->planId);
        $author = $plan?->author_user_id;

        if ($plan !== null && $author !== $this->authorId) {
            $this->authorId = $author;
            $this->authorToBe = $this->authorToBe === $author ? null : $this->authorToBe;
            $this->leaveOutAuthor(self::postVotesOf($plan));
            $this->dispatch(self::REMAKE_EVENT)->self();
        }
    }

    /**
     * Leaves the post's author, saved or to be, out of its voters — it never
     * votes on its own post: chosen, its place goes to another active
     * Persona, drawn; drawn at random, more voters asked for than are left
     * take all there are. Whether an author was a voter, so the votes must be
     * made again.
     *
     * @param  list<array<string, mixed>>  $saved  the post votes as saved
     */
    private function leaveOutAuthor(array $saved): bool
    {
        if ($this->authors() === [] || $this->closed) {
            return false;
        }

        $this->voterCount = min($this->voterCount, $this->cap());
        $voting = false;

        foreach ($this->authors() as $author) {
            if (in_array($author, $this->voterIds, true)) {
                $spare = array_values(array_diff(app(PersonasQuery::class)->activeIds(), $this->voterIds, $this->authors()));
                usort($spare, fn (int $a, int $b): int => hash('xxh3', ($this->seed ?? $this->planId).'|spare|'.$a) <=> hash('xxh3', ($this->seed ?? $this->planId).'|spare|'.$b));
                $this->voterIds = [...array_values(array_diff($this->voterIds, [$author])), ...array_slice($spare, 0, 1)];
                $voting = true;
            }

            $voting = $voting || in_array($author, array_column($saved, 'personaUserId'), true);
        }

        return $voting;
    }

    /** Whether the plan is at this version now, its post votes as this page last read or wrote them. */
    private function holdsSavedAt(int $version): bool
    {
        $plan = StudioPlan::query()->find($this->planId);

        return $plan !== null && $plan->lock_version === $version && hash_equals($this->votesFingerprint, self::fingerprintOf(self::postVotesOf($plan)));
    }

    /** A setting changed: the votes are saved as the request leaves them (render()). */
    private function change(): void
    {
        $this->changed = true;
    }

    /**
     * Drawn at random, the voters are as many as the votes and answers asked
     * for need, as far as there are Personas to draw: asking for more votes
     * draws more voters rather than being refused. Chosen by hand, they are
     * the ones chosen.
     */
    private function growVoters(): void
    {
        if ($this->voterMode !== 'random') {
            return;
        }

        $need = max([$this->postOn ? $this->up + $this->down : 0, ...array_map(fn (array $rating): int => array_sum($rating['counts']), $this->ratings), 0]);
        $this->voterCount = max($this->voterCount, min($need, $this->cap()));
    }

    /**
     * Saves the votes the settings ask for now: made by
     * BuildStudioPostVotesData — a Persona keeping its saved vote and moment
     * as far as the settings allow — and written, unless they are the votes
     * saved already. What can't be made is said where it is, and nothing is
     * saved until it is fixed. The votes are made for the author the draft
     * has saved, never a voter.
     */
    private function commit(): void
    {
        $this->changed = false;

        if (! $this->writable()) {
            return;
        }

        $this->resetErrorBag();
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
        $this->rememberVoters();
    }

    /**
     * How the voters are chosen, as the session kept it for this plan
     * (VOTERS_SESSION) — chosen by hand, the Personas voting now always among
     * them — or else drawn at random: as many as a draw starts with, or as
     * vote now if they are more, the Personas voting now kept in the draw
     * (BuildStudioPostVotesData). Never the hand-chosen list of exactly those
     * voting, which one vote more would be short of.
     *
     * @param  list<int>  $voting  the Personas the saved votes are given to
     */
    private function restoreVoters(array $voting): void
    {
        $kept = session(self::VOTERS_SESSION.'.'.$this->planId);
        $kept = is_array($kept) ? $kept : [];

        if (($kept['mode'] ?? null) === 'manual') {
            $chosen = array_values(array_filter(array_map(intval(...), (array) ($kept['ids'] ?? [])), fn (int $id): bool => $id > 0));
            $this->voterMode = 'manual';
            $this->voterIds = array_values(array_unique([...$chosen, ...$voting]));
            $this->voterCount = is_int($kept['count'] ?? null) ? self::count($kept['count']) : count($this->voterIds);

            return;
        }

        $this->voterMode = 'random';
        $this->voterIds = [];
        $this->voterCount = min($this->cap(), max(count($voting), is_int($kept['count'] ?? null) ? self::count($kept['count']) : self::DEFAULT_VOTERS));
    }

    /** How the voters are chosen, kept in the session for this plan (VOTERS_SESSION) as the votes saved were made. */
    private function rememberVoters(): void
    {
        if ($this->planId !== 0) {
            session()->put(self::VOTERS_SESSION.'.'.$this->planId, ['mode' => $this->voterMode, 'ids' => $this->voterIds, 'count' => $this->voterCount]);
        }
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
            $ratings[$group] = new StudioRatingVotesSettings((int) $group, $rating['counts'], $rating['moments']);
        }

        return new StudioPostVotesSettings(
            voterMode: $this->voterMode,
            voterCount: $this->voterCount,
            voterIds: $this->voterIds,
            up: $this->postOn ? $this->up : 0,
            down: $this->postOn ? $this->down : 0,
            firstSeconds: $this->firstAfter,
            spreadSeconds: $this->spreadOver,
            postMoments: $this->postMoments,
            ratings: $ratings,
            seed: $this->seed ?? 1,
            keepSaved: $this->keepSaved,
            authorToBe: $this->authorToBe,
        );
    }

    /** What the settings ask for, in one value, with the draw: what the saved votes were made from. */
    private function settingsKey(): string
    {
        return hash('xxh3', json_encode([
            $this->voterMode, $this->voterMode === 'manual' ? $this->voterIds : $this->voterCount,
            $this->postOn ? [$this->up, $this->down, $this->postMoments] : null,
            $this->ratings, $this->firstAfter, $this->spreadOver, $this->seed,
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

    /** How many Personas can vote on the post: the active ones, but its author, saved or to be. */
    private function cap(): int
    {
        return count(array_diff(app(PersonasQuery::class)->activeIds(), $this->authors()));
    }

    /** How many of the Personas chosen may take a new vote: the active ones, but the post's author, saved or to be. */
    private function eligibleChosen(): int
    {
        return count(array_diff(array_intersect($this->voterIds, app(PersonasQuery::class)->activeIds()), $this->authors()));
    }

    /**
     * Who never votes on the post: its author as saved, and the one picked on
     * the page and not saved yet.
     *
     * @return list<int>
     */
    private function authors(): array
    {
        return array_values(array_unique(array_filter([$this->authorId, $this->authorToBe], fn (?int $id): bool => $id !== null)));
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
     * The Timing tab's head: when the voters come as saved, the moments
     * offered and the ones chosen, and whether a vote has a moment of its own.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function timing(array $rows): array
    {
        $chips = function (array $offered, int $value): array {
            $offered[$value] ??= StudioPlanDiscussionEditor::relative($value);
            ksort($offered);

            return array_map(fn (int $seconds, string $label): array => ['seconds' => $seconds, 'label' => $label, 'on' => $seconds === $value], array_keys($offered), $offered);
        };
        $offsets = array_column($rows, 'offset');
        $voters = count(array_unique(array_column($rows, 'personaId')));

        return [
            'meta' => $offsets === [] ? 'None saved' : $voters.' '.($voters === 1 ? 'voter' : 'voters').' · first '.StudioPlanDiscussionEditor::relative(min($offsets)).' after, last '.StudioPlanDiscussionEditor::relative(max($offsets)).' after',
            'first' => $chips(self::FIRST_CHOICES, $this->firstAfter),
            'spread' => $chips(self::SPREAD_CHOICES, $this->spreadOver),
            'byHand' => $this->postMoments !== [] || array_filter(array_column($this->ratings, 'moments')) !== [],
        ];
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
     * When the saved votes come: the first voter's visit, how long after it
     * the last's, and — when any vote comes away from its place in that
     * layout (BuildStudioPostVotesData) — the moment of every vote, by
     * target, so one set by hand keeps its own and the rest keep theirs.
     *
     * @param  list<array<string, mixed>>  $saved
     * @param  list<int|null>  $targets  the post (null), then the questions, in the order a visit takes them
     * @return array{0: int, 1: int, 2: list<array<int, int>>} the moments by target, as `$targets` lists them
     */
    private static function timingOf(array $saved, array $targets): array
    {
        $byTarget = array_map(fn (?int $group): array => self::offsetsOn($saved, $group), $targets);
        $visits = [];

        foreach ($byTarget as $offsets) {
            foreach ($offsets as $persona => $offset) {
                $visits[$persona][] = $offset;
            }
        }

        if ($visits === []) {
            return [...self::TIMING, array_map(fn (): array => [], $targets)];
        }

        $came = array_map(fn (array $offsets): int => $offsets[0], $visits);
        $order = array_keys($came);
        usort($order, fn (int $a, int $b): int => [$came[$a], $a] <=> [$came[$b], $b]);
        $first = min($came);
        $spread = count($came) > 1 ? max($came) - $first : self::TIMING[1];

        foreach ($order as $place => $persona) {
            $visit = $first + (count($order) > 1 ? intdiv($place * $spread, count($order) - 1) : 0);

            foreach (BuildStudioPostVotesData::visitSteps($persona, count($visits[$persona])) as $step => $after) {
                if ($visits[$persona][$step] !== $visit + $after) {
                    return [$first, $spread, array_map(function (array $offsets): array {
                        ksort($offsets);

                        return $offsets;
                    }, $byTarget)];
                }
            }
        }

        return [$first, $spread, array_map(fn (): array => [], $targets)];
    }

    /** No vote at a moment of its own: each in its voter's visit again. */
    private function clearMoments(): void
    {
        $this->postMoments = [];

        foreach (array_keys($this->ratings) as $group) {
            $this->ratings[$group]['moments'] = [];
        }
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
