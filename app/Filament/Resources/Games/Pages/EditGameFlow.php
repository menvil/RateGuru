<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\SaveGameFlowAction;
use App\Data\Games\GameFlowDraft;
use App\Enums\GameAdvanceMode;
use App\Enums\GameAudience;
use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnward;
use App\Enums\GamePageDestination;
use App\Enums\GamePostTarget;
use App\Enums\GameSignupMode;
use App\Enums\GameStepAudience;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\Concerns\PicksPosts;
use App\Filament\Resources\Games\GameResource;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameVersion;
use App\Models\Post;
use App\Queries\Games\GameFlowFactsQuery;
use App\Queries\Games\GamePostPickerQuery;
use App\Queries\Games\GameUnpublishedChangesQuery;
use App\Queries\Traffic\GameWorthQuery;
use App\Services\Games\GamePreviewDrafts;
use App\Support\Games\Flow\DraftFlow;
use App\Support\Games\Flow\FlowGoalRules;
use App\Support\Games\Flow\FlowIssue;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\FlowVisitor;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Flow\GameFlowStepSettings;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Games\Flow\GameFlowValidator;
use App\Support\Games\Flow\PostLink;
use App\Support\Media\PostImagePresenter;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Worth\StepLabels;
use App\Support\Traffic\Worth\TrafficStep;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * A game's Flow tab: the screens a visitor moves through around the game, as
 * named flows — made from a template or as a copy, renamed, archived,
 * restored, deleted while no version used them. The flow on screen is one
 * draft until Save — in the top bar, or again at the foot of the selected
 * step's settings (SaveGameFlowAction): steps added where
 * they fit, moved, removed, set and worded. The flow's problems are listed as
 * it stands (GameFlowValidator); a step can be added only where it adds none.
 * The selected step's Preview plays it as a phone shows it, for the visitor
 * chosen, from the flow as it stands: each draw hands the draft to
 * GamePreviewDrafts, and the preview frame plays the steps the stamp names.
 */
final class EditGameFlow extends Page
{
    use InteractsWithGameEditor;
    use PicksPosts;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-flow';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    #[Url(as: 'flow', except: 0)]
    public int $flowId = 0;

    #[Url(as: 'step', except: 0)]
    public int $selected = 0;

    #[Locked]
    public int $lockVersion = 1;

    #[Locked]
    public bool $stale = false;

    /** @var array<int, array<string, mixed>> */
    public array $steps = [];

    /** @var array<int, array<string, mixed>> */
    public array $saved = [];

    /** The flow's goal as chosen; '' follows the steps (FlowGoalRules::defaultFor). */
    public string $goal = '';

    public string $savedGoal = '';

    /** What was pasted or typed to link a post to the selected step. */
    public string $postLink = '';

    /** @var list<array{id: int, title: string, author: string}> */
    public array $postMatches = [];

    /** Whether the post picker is open for the selected step. */
    public bool $picking = false;

    public string $postLinkError = '';

    /** Where the step chooser on screen would add a step; null when it is closed. */
    public ?int $insertAt = null;

    public string $flowName = '';

    public string $newFlowTemplate = 'result_first';

    /** What the step panel shows: `settings`, or `preview` — the step played as a phone shows it. */
    public string $panel = 'settings';

    /** Whom the step preview plays the step for: anonymous, known (email left before) or authenticated. */
    public string $previewVisitor = 'anonymous';

    #[Locked]
    public ?string $confirmingFlow = null;

    public function mount(int|string $record): void
    {
        $game = $this->mountGame($record);
        $this->drawFlow($this->chosenFlow($game));
    }

    #[On('discard-game-flow')]
    public function discard(): void
    {
        $game = $this->game();

        if ($game !== null) {
            $this->drawFlow($this->chosenFlow($game));
            $this->resetErrorBag();
        }
    }

    public function reload(): void
    {
        $this->discard();
    }

    #[On('save-game-flow')]
    public function save(): void
    {
        $flow = $this->currentFlow();

        if ($flow === null) {
            $this->toast('This flow no longer exists.', 'error');

            return;
        }

        try {
            app(SaveGameFlowAction::class)->handle($this->admin(), $flow, new GameFlowDraft(array_values($this->steps), $this->goal !== '' ? $this->goal : null), $this->lockVersion);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->toast('Some fields need fixing before the flow can be saved.', 'error');

            return;
        } catch (CannotManageGameException $exception) {
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->drawFlow($flow->fresh() ?? $flow);
        $this->toast('Flow saved');
    }

    public function selectStep(mixed $index): void
    {
        if (is_numeric($index) && isset($this->steps[(int) $index])) {
            $this->selected = (int) $index;
            $this->insertAt = null;
            $this->resetPostLink();
        }
    }

    /** Adds the step the chosen goal needs, where it fits best. */
    public function addGoalStep(): void
    {
        $fix = GameFlowGoal::tryFrom($this->goal)?->fix();

        if ($fix !== null) {
            $this->insertStep($fix->value);
        }
    }

    /** Looks the post up as its link or words are typed; an empty field clears what was found. */
    public function updatedPostLink(): void
    {
        if (trim($this->postLink) === '') {
            $this->postMatches = [];
            $this->postLinkError = '';

            return;
        }

        $this->linkPost();
    }

    /**
     * Links the post pasted — its address on this site, #id or id — to the
     * selected step; words look posts up by title or author instead.
     */
    public function linkPost(): void
    {
        $this->postLinkError = '';
        $this->postMatches = [];
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $link = PostLink::parse($this->postLink, $host);

        if ($link->isForeign()) {
            $this->postLinkError = 'No post with that link on '.$host.'. Only posts on this site can be linked.';

            return;
        }

        if ($link->isPost()) {
            $this->choosePost($link->postId);

            return;
        }

        if ($link->words === '') {
            $this->postLinkError = 'Paste a post’s link, its #id, or words from its title.';

            return;
        }

        $this->postMatches = app(GamePostPickerQuery::class)->linkable($link->words)
            ->map(fn (Post $post): array => [
                'id' => (int) $post->id,
                'title' => (string) $post->title,
                'author' => (string) $post->user?->username,
                'votes' => (int) $post->upvotes_count + (int) $post->downvotes_count,
                'published' => true,
            ])
            ->values()->all();

        if ($this->postMatches === []) {
            $this->postLinkError = 'No post title or author has these words.';
        }
    }

    public function choosePost(mixed $id): void
    {
        $step = $this->steps[$this->selected] ?? null;
        $post = is_numeric($id) ? Post::withTrashed()->find((int) $id) : null;

        if ($step === null || ! array_key_exists('post_target', (array) ($step['settings'] ?? []))) {
            return;
        }

        if ($post === null) {
            $this->postLinkError = 'No post #'.(is_numeric($id) ? (int) $id : '').' on this site.';

            return;
        }

        $this->steps[$this->selected]['settings']['post_id'] = (int) $post->id;
        $this->steps[$this->selected]['settings']['post_target'] = GamePostTarget::Chosen->value;
        $this->resetPostLink();
        $this->toast('Post linked: '.Str::limit((string) $post->title, 60));
    }

    /** Opens the post picker for the selected step, from its first page. */
    public function openPicker(): void
    {
        if (! array_key_exists('post_target', (array) ($this->steps[$this->selected]['settings'] ?? []))) {
            return;
        }

        $this->picking = true;
        $this->pickerSearch = '';
        $this->pickerPage = 1;
    }

    public function closePicker(): void
    {
        $this->picking = false;
    }

    /** The post picked in the picker, linked to the selected step. */
    public function pickPost(mixed $postId): void
    {
        $this->picking = false;
        $this->choosePost($postId);
    }

    public function unlinkPost(): void
    {
        if (isset($this->steps[$this->selected]['settings']['post_id'])) {
            $this->steps[$this->selected]['settings']['post_id'] = null;
        }
    }

    /** Asks, or stops asking, for a rating group on the post the selected step sends the visitor to. */
    public function toggleAsk(mixed $key): void
    {
        $settings = (array) ($this->steps[$this->selected]['settings'] ?? []);
        $type = (string) ($this->steps[$this->selected]['type'] ?? '');

        // Any step that can send to a post asks for groups there — a Full Result saved before it could has no list yet.
        if (! is_string($key) || ! in_array($type, [GameFlowStepType::GoToPost->value, GameFlowStepType::FullResult->value], true)) {
            return;
        }

        $asked = array_values(array_filter(explode(',', (string) ($settings['ask_groups'] ?? ''))));
        $asked = in_array($key, $asked, true) ? array_values(array_diff($asked, [$key])) : [...$asked, $key];
        $this->steps[$this->selected]['settings']['ask_groups'] = implode(',', array_slice($asked, 0, GameFlowStepSettings::MAX_ASK_GROUPS));
    }

    private function resetPostLink(): void
    {
        $this->postLink = '';
        $this->postMatches = [];
        $this->postLinkError = '';
    }

    public function moveStep(mixed $index, mixed $by): void
    {
        $from = is_numeric($index) ? (int) $index : -1;
        $to = $from + (is_numeric($by) ? (int) $by : 0);

        if (isset($this->steps[$from], $this->steps[$to]) && $from !== $to) {
            [$this->steps[$from], $this->steps[$to]] = [$this->steps[$to], $this->steps[$from]];
            $this->selected = $to;
        }
    }

    public function removeStep(mixed $index): void
    {
        if (! is_numeric($index) || ! isset($this->steps[(int) $index]) || $this->steps[(int) $index]['type'] === GameFlowStepType::Game->value) {
            return;
        }

        // The step on screen stays on screen when another one goes.
        $shown = $this->steps[$this->selected]['key'] ?? null;
        array_splice($this->steps, (int) $index, 1);
        $at = array_search($shown, array_column($this->steps, 'key'), true);
        $this->selected = is_int($at) ? $at : max(0, min($this->selected, count($this->steps) - 1));
    }

    /** The selected Game step's delay before the next pair, half a second shorter or longer, within its limits. */
    public function nudgeDelay(mixed $by): void
    {
        $step = $this->steps[$this->selected] ?? null;

        if ($step === null || ($step['type'] ?? null) !== GameFlowStepType::Game->value || ! is_numeric($by)) {
            return;
        }

        $seconds = (float) ($step['settings']['advance_seconds'] ?? 2.5) + ((int) $by <=> 0) * 0.5;
        $this->steps[$this->selected]['settings']['advance_seconds'] = max(GameFlowStepSettings::ADVANCE_SECONDS_MIN, min(GameFlowStepSettings::ADVANCE_SECONDS_MAX, $seconds));
    }

    public function openInsert(mixed $position): void
    {
        $this->insertAt = is_numeric($position) ? max(0, min((int) $position, count($this->steps))) : null;
    }

    public function closeInsert(): void
    {
        $this->insertAt = null;
    }

    /**
     * Adds a step of a type at the position given — the chooser names its
     * own — or, from the library, where it fits best: the position the
     * library offered it for, whether or not a chooser is open.
     */
    public function insertStep(mixed $type, mixed $position = null): void
    {
        $type = is_string($type) ? GameFlowStepType::tryFrom($type) : null;
        $drafts = (new GameFlowDraft(array_values($this->steps)))->drafts();
        $position = is_numeric($position) ? (int) $position : ($type !== null ? GameFlowValidator::defaultPosition($drafts, $type) : null);

        if ($type === null || $position === null || ! in_array($type, GameFlowValidator::insertableAt($drafts, $position), true) || count($this->steps) >= SaveGameFlowAction::MOST_STEPS) {
            $this->toast('That step doesn’t fit there.', 'error');

            return;
        }

        array_splice($this->steps, $position, 0, [GameFlowDraft::newStep($type, 'n'.Str::lower(Str::random(10)), $drafts)]);
        $this->selected = $position;
        $this->insertAt = null;
    }

    public function createFlow(mixed $copy = false): void
    {
        $game = $this->game();

        if ($game === null) {
            return;
        }

        $template = GameFlowTemplate::tryFrom($this->newFlowTemplate) ?? GameFlowTemplate::ResultFirst;
        $name = $copy ? $this->flowName.' copy' : $template->label();

        try {
            $flow = app(ChangeGameFlowAction::class)->create($this->admin(), $game, $name, $template, $copy ? $this->flowId : null);
        } catch (CannotManageGameException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->redirect(GameResource::getUrl('flow', ['record' => $game, 'flow' => $flow->id]));
    }

    public function renameFlow(): void
    {
        $flow = $this->currentFlow();

        if ($flow === null) {
            return;
        }

        try {
            $renamed = app(ChangeGameFlowAction::class)->rename($this->admin(), $flow, $this->flowName, $this->lockVersion);
        } catch (CannotManageGameException $exception) {
            // A name refused is not shown as the flow's.
            $this->flowName = $flow->name;
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        // The name as stored: trimmed, and cut to its limit.
        $this->flowName = (string) $flow->fresh()?->name;

        if ($renamed) {
            // The rename moved the flow on from the version on screen, and nothing else did.
            $this->lockVersion++;
            $this->toast('Flow renamed');
        }
    }

    public function askFlow(mixed $action): void
    {
        $this->confirmingFlow = in_array($action, ['archive', 'delete'], true) ? $action : null;
    }

    public function closeFlow(): void
    {
        $this->confirmingFlow = null;
    }

    /**
     * Archives or deletes the flow on screen, and opens the Flow tab again.
     * The confirm told the page it is leaving, so nothing unsaved asks first;
     * a change refused takes that back.
     */
    public function confirmFlow(): void
    {
        $action = $this->confirmingFlow;
        $this->confirmingFlow = null;
        $flow = $this->currentFlow();
        $game = $this->game();

        if ($flow === null || $game === null || $action === null) {
            $this->js('$store.adminEditor.leaving = false');

            return;
        }

        try {
            $action === 'delete'
                ? app(ChangeGameFlowAction::class)->delete($this->admin(), $flow)
                : app(ChangeGameFlowAction::class)->archive($this->admin(), $flow);
        } catch (CannotManageGameException $exception) {
            $this->js('$store.adminEditor.leaving = false');
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        session()->flash('rg-admin-toast', ['message' => $action === 'delete' ? 'Flow deleted' : 'Flow archived', 'tone' => 'success']);
        $this->redirect(GameResource::getUrl('flow', ['record' => $game]));
    }

    public function restoreFlow(): void
    {
        $flow = $this->currentFlow();

        if ($flow === null) {
            return;
        }

        try {
            app(ChangeGameFlowAction::class)->restore($this->admin(), $flow, $this->lockVersion);
        } catch (CannotManageGameException $exception) {
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        // Restoring changes no step: what is being edited stays, unsaved edits included.
        $this->lockVersion++;
        $this->toast('Flow restored');
    }

    private function chosenFlow(Game $game): GameFlow
    {
        $flows = GameFlow::query()->where('game_id', $game->id)->orderBy('position')->orderBy('id')->get();
        $flow = $flows->firstWhere('id', $this->flowId) ?? $flows->firstWhere('archived_at', null) ?? $flows->first();
        abort_if($flow === null, 404);

        return $flow;
    }

    private function currentFlow(): ?GameFlow
    {
        return GameFlow::query()->where('game_id', $this->gameId)->find($this->flowId);
    }

    private function drawFlow(GameFlow $flow): void
    {
        $flow->load('steps');
        $draft = GameFlowDraft::of($flow);

        $this->flowId = (int) $flow->id;
        $this->flowName = $flow->name;
        $this->goal = $flow->goal->value ?? '';
        $this->savedGoal = $this->goal;
        $this->lockVersion = $flow->lock_version;
        $this->stale = false;
        $this->steps = $draft->steps;
        $this->saved = $draft->steps;
        $this->selected = max(0, min($this->selected, count($this->steps) - 1));
        $this->insertAt = null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $draft = new GameFlowDraft(array_values($this->steps), $this->goal !== '' ? $this->goal : null);
        $drafts = $draft->drafts();
        $chosenGoal = GameFlowGoal::tryFrom($this->goal);
        $goal = $chosenGoal ?? FlowGoalRules::defaultFor($drafts);
        $goalStep = FlowGoalRules::stepFor($drafts, $goal);
        $issues = GameFlowValidator::issues($drafts, $chosenGoal, app(GameFlowFactsQuery::class)->for($drafts));
        $flows = GameFlow::query()->where('game_id', $game->id)->withCount('versions')->with(['steps', 'versions' => fn ($query) => $query->orderBy('number')])->orderBy('position')->orderBy('id')->get();
        $flow = $flows->firstWhere('id', $this->flowId);
        $present = array_map(fn ($step): string => $step->type->value, $drafts);
        $step = $this->steps[$this->selected] ?? null;
        $stepType = $step !== null ? GameFlowStepType::tryFrom((string) $step['type']) : null;

        $selectedKey = $step !== null ? DraftFlow::keyOf($step, $this->selected) : null;
        $visitorMode = $this->previewVisitor === 'authenticated' ? 'authenticated' : 'anonymous';
        $flowVisitor = new FlowVisitor($visitorMode === 'authenticated' ? GameAudience::Authenticated : GameAudience::Anonymous, $visitorMode === 'authenticated');
        $selectedDraft = $selectedKey !== null ? GameFlowRunner::find($drafts, (string) $step['key']) : null;
        $setId = (int) GameContentSet::query()->where('game_id', $game->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->value('id');
        $fullResult = collect($this->steps)->firstWhere('type', GameFlowStepType::FullResult->value);
        $postTitles = Post::withTrashed()->whereKey(collect($this->steps)->pluck('settings.post_id')->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->all())->pluck('title', 'id')->all();
        $gameWorth = app(GameWorthQuery::class);
        $rounds = $gameWorth->rounds($game);
        $mobile = $gameWorth->slice($game, $rounds, device: 'mobile');
        $worth = ['mobile' => $mobile['steps'], 'desktop' => $gameWorth->slice($game, $rounds, device: 'desktop')['steps']];

        return [
            'header' => $this->editorHeader($game, 'flow', ['flow' => $issues !== [] ? (string) count($issues) : null], $this->flowId > 0 ? app(GameUnpublishedChangesQuery::class)->forFlow($game, $this->flowId) : null),
            'flows' => $flows->map(fn (GameFlow $item): array => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'chain' => 'Goal: '.GameFlowDraft::of($item)->goal()->label().' · '.$item->steps->map(fn ($s) => $s->type->label())->implode(' → '),
                'usage' => $this->flowUsage($item),
                'archived' => $item->isArchived(),
                'url' => GameResource::getUrl('flow', ['record' => $game, 'flow' => $item->id]),
                'current' => (int) $item->id === $this->flowId,
            ])->all(),
            'flowUsage' => $flow === null ? '' : $this->flowUsage($flow),
            'flowLive' => $flow !== null && $flow->versions->contains('status', GameVersionStatus::Active),
            'flowArchived' => $flow?->isArchived() ?? false,
            'flowUsed' => $flow !== null && (int) $flow->versions_count > 0,
            'flowOnly' => $flows->whereNull('archived_at')->count() <= 1,
            'stepPosition' => $step === null ? '' : 'Step '.($this->selected + 1).' of '.count($this->steps).' · '.$this->flowName,
            'stepPreviewUrl' => $selectedKey === null ? null : route('games.preview', [
                'game' => $game->id,
                'set' => $setId,
                'flow' => $this->flowId,
                'start' => 'step:'.$selectedKey,
                'visitor' => $visitorMode,
                'framed' => 1,
                'flow_draft' => app(GamePreviewDrafts::class)->putFlow((int) auth()->id(), $this->flowId, $draft),
            ]),
            'stepSkipped' => $selectedDraft !== null && ! GameFlowRunner::isVisible($selectedDraft, $flowVisitor),
            'stepRequired' => $selectedDraft?->requiredTexts() ?? [],
            'fullPreviewUrl' => GameResource::getUrl('preview', ['record' => $game, 'flow' => $this->flowId, 'start' => $selectedKey !== null ? 'step:'.$selectedKey : 'landing']),
            'templates' => array_map(fn (GameFlowTemplate $template): array => ['value' => $template->value, 'label' => $template->label(), 'description' => $template->description()], GameFlowTemplate::cases()),
            'issues' => $issues,
            'flowIssues' => array_values(array_filter($issues, fn (FlowIssue $issue): bool => $issue->index < 0)),
            'stepIssues' => collect($issues)->filter(fn (FlowIssue $issue): bool => $issue->index >= 0)->groupBy(fn (FlowIssue $issue): int => $issue->index)->map(fn ($group) => $group->pluck('message')->all())->all(),
            'cards' => array_map(fn (array $item, int $index): array => [
                ...$this->card($item, $index, $postTitles),
                'goal' => $index === $goalStep && $goal !== GameFlowGoal::Completed,
                'worth' => $this->worthChip($this->worthRows($item, $rounds, $worth)),
            ], $this->steps, array_keys($this->steps)),
            'stepWorth' => $step === null ? [] : $this->worthRows($step, $rounds, $worth),
            'worthWorkedOut' => $mobile['worked'],
            'valueUrl' => GameResource::getUrl('value', ['record' => $game]),
            'goalCard' => $this->goalCard($drafts, $chosenGoal, $goal, $goalStep, $fullResult),
            'ratingGroups' => app(RatingConfigurationManager::class)->activeGroups()->map(fn ($group): array => ['key' => (string) $group->key, 'label' => $group->translatedLabel('en')])->values()->all(),
            'categories' => Category::query()->active()->ordered()->get()->mapWithKeys(fn (Category $category): array => [(string) $category->slug => $category->translatedName('en')])->all(),
            'linkedPost' => $this->linkedPost($step),
            'picker' => $this->picking ? [...$this->pickablePosts(), 'title' => 'Choose a post to send visitors to'] : null,
            'feedSorts' => ['' => 'The site’s default', 'newest' => 'Newest', 'top' => 'Top', 'hot' => 'Hot'],
            'library' => array_map(fn (GameFlowStepType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'icon' => $type->icon(),
                'present' => in_array($type->value, $present, true),
                'fits' => GameFlowValidator::defaultPosition($drafts, $type) !== null,
                'tip' => match (true) {
                    in_array($type->value, $present, true) => 'Already in this flow',
                    GameFlowValidator::defaultPosition($drafts, $type) !== null => 'Add '.$type->label(),
                    $type === GameFlowStepType::Registration && self::waitsForSignupStep($fullResult) => 'Sign-up is on Full Result. Set its sign-up to a separate step first.',
                    default => 'Doesn’t fit this flow',
                },
            ], GameFlowStepType::cases()),
            'insertable' => $this->insertAt !== null ? array_map(fn (GameFlowStepType $type): array => ['type' => $type->value, 'label' => $type->label(), 'icon' => $type->icon()], GameFlowValidator::insertableAt($drafts, $this->insertAt)) : [],
            'step' => $step,
            'stepType' => $stepType,
            'stepTexts' => $stepType?->texts() ?? [],
            'textLimits' => SaveGameFlowAction::TEXT_MAX,
            'audiences' => array_map(fn (GameStepAudience $audience): array => ['value' => $audience->value, 'label' => $audience->label()], GameStepAudience::cases()),
            'signups' => array_map(fn (GameSignupMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()], GameSignupMode::cases()),
            'advances' => [GameAdvanceMode::Auto->value => 'Automatic', GameAdvanceMode::Button->value => 'By button'],
            'delays' => array_map(fn (int $half): float => $half / 2, range((int) (GameFlowStepSettings::ADVANCE_SECONDS_MIN * 2), (int) (GameFlowStepSettings::ADVANCE_SECONDS_MAX * 2))),
            'nextGames' => Game::query()->whereKeyNot($game->id)->orderBy('title')->get(['id', 'title', 'status'])->map(fn (Game $other): array => ['id' => (int) $other->id, 'title' => $other->title, 'status' => $other->status->label()])->all(),
        ];
    }

    /**
     * The goal card over the canvas: the goals to choose from, each with
     * whether this flow reaches it, and what counts for the one in force.
     * The step a goal out of reach needs is offered only where it fits;
     * otherwise the card says what to change first.
     *
     * @param  list<FlowStepDraft>  $drafts
     * @param  array<string, mixed>|null  $fullResult  the flow's Full Result step, as edited
     * @return array<string, mixed>
     */
    private function goalCard(array $drafts, ?GameFlowGoal $chosen, GameFlowGoal $goal, ?int $step, ?array $fullResult): array
    {
        $default = FlowGoalRules::defaultFor($drafts);
        $status = function (GameFlowGoal $option) use ($drafts): string {
            $at = FlowGoalRules::stepFor($drafts, $option);

            return match (true) {
                $option->needs() === null => 'Always possible',
                $at !== null => 'In this flow · step '.($at + 1),
                default => 'Needs '.$option->needs(),
            };
        };
        $reachable = FlowGoalRules::reaches($drafts, $goal);
        $label = $step !== null ? 'step '.($step + 1).' · '.$drafts[$step]->type->label() : '';
        $fix = $reachable ? null : $goal->fix();
        $fits = $fix !== null && GameFlowValidator::defaultPosition($drafts, $fix) !== null;

        return [
            'choices' => [
                ['value' => '', 'label' => 'Follow the steps', 'short' => 'Whatever the steps lead to', 'icon' => 'workflow', 'status' => 'Now '.$default->label(), 'reachable' => true],
                ...array_map(fn (GameFlowGoal $option): array => [
                    'value' => $option->value,
                    'label' => $option->label(),
                    'short' => $option->short(),
                    'icon' => $option->icon(),
                    'status' => $status($option),
                    'reachable' => FlowGoalRules::reaches($drafts, $option),
                ], GameFlowGoal::cases()),
            ],
            'goal' => $goal,
            'chosen' => $chosen !== null,
            'reachable' => $reachable,
            'where' => match (true) {
                ! $reachable => 'Nothing in this flow can reach this goal. Add '.$goal->needs().', or choose another goal.',
                $goal === GameFlowGoal::Completed => 'Counted on the Game step, after the last round.',
                FlowGoalRules::viaSite($drafts, $goal) => 'Reached on the site after '.$label.': voting or commenting there asks for an account, and a sign-up within 7 days counts for this play.',
                $step !== null => 'Counted on '.$label.'.',
                default => '',
            },
            'fix' => $fix !== null && $fits ? ['type' => $fix->value, 'label' => $fix->label()] : null,
            'fixHint' => match (true) {
                $fix === null || $fits => null,
                $fix === GameFlowStepType::Registration && self::waitsForSignupStep($fullResult) => 'Set Full Result’s sign-up to a separate step first, then add '.$fix->label().'.',
                default => $fix->label().' doesn’t fit this flow as it stands.',
            },
        ];
    }

    /**
     * Whether a Registration step waits on Full Result: Full Result goes on
     * to the next step but keeps sign-up on itself or asks for none, so a
     * Registration step fits only once its sign-up is set to a separate step.
     *
     * @param  array<string, mixed>|null  $fullResult
     */
    private static function waitsForSignupStep(?array $fullResult): bool
    {
        return $fullResult !== null
            && ($fullResult['settings']['onward'] ?? GameOnward::Next->value) === GameOnward::Next->value
            && ($fullResult['settings']['signup'] ?? GameSignupMode::Inline->value) !== GameSignupMode::Step->value;
    }

    /**
     * The post the selected step links to, as its panel shows it.
     *
     * @param  array<string, mixed>|null  $step
     * @return array{id: int, title: string, author: string, votes: int, up: int|null, published: bool, url: string}|null
     */
    private function linkedPost(?array $step): ?array
    {
        $id = $step['settings']['post_id'] ?? null;
        $post = is_numeric($id) ? Post::withTrashed()->with(['user:id,username', 'imageAsset.variants'])->find((int) $id) : null;

        if ($post === null) {
            return null;
        }

        $votes = (int) $post->upvotes_count + (int) $post->downvotes_count;

        return [
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'thumbnail' => app(PostImagePresenter::class)->assetThumbnailUrl($post->imageAsset),
            'author' => (string) ($post->user->username ?? 'someone'),
            'votes' => $votes,
            'up' => $votes > 0 ? (int) round((int) $post->upvotes_count / $votes * 100) : null,
            'published' => ! $post->trashed() && Post::query()->published()->whereKey($post->id)->exists(),
            'url' => route('posts.show', $post->id),
        ];
    }

    /**
     * What reaching a step of the flow is worth, mobile and desktop, every
     * source and country: a row for each step of a visit's worth it lets the
     * visitor reach — the game a row for every round — marked where the value
     * was set by hand.
     *
     * @param  array<string, mixed>  $step
     * @param  array{mobile: array<string, array<string, mixed>>, desktop: array<string, array<string, mixed>>}  $worth
     * @return list<array{step: string, name: string, mobile: string, desktop: string, manual: bool}>
     */
    private function worthRows(array $step, int $rounds, array $worth): array
    {
        $type = GameFlowStepType::tryFrom((string) ($step['type'] ?? ''));

        if ($type === null) {
            return [];
        }

        return array_map(fn (string $key): array => [
            'step' => $key,
            'name' => StepLabels::name($key, $rounds),
            'mobile' => (string) $worth['mobile'][$key]['value'],
            'desktop' => (string) $worth['desktop'][$key]['value'],
            'manual' => $worth['mobile'][$key]['manual'] !== null,
        ], TrafficStep::ofFlowStep($type, (array) ($step['settings'] ?? []), $rounds));
    }

    /**
     * The price on a step's card: its best row — a visitor is worth their
     * best step — “up to” when it has more than one, mobile then desktop
     * when they differ; none while nothing it reaches is worth anything.
     *
     * @param  list<array{step: string, name: string, mobile: string, desktop: string, manual: bool}>  $rows
     * @return array{text: string, tip: string}|null
     */
    private function worthChip(array $rows): ?array
    {
        $mobile = collect($rows)->max(fn (array $row): float => (float) $row['mobile']) ?? 0.0;
        $desktop = collect($rows)->max(fn (array $row): float => (float) $row['desktop']) ?? 0.0;

        if ($mobile <= 0 && $desktop <= 0) {
            return null;
        }

        $pair = fn (string|float $m, string|float $d): string => TrafficMoney::brief($m).(abs((float) $d - (float) $m) > 1e-9 ? ' / '.TrafficMoney::brief($d) : '');

        return [
            'text' => (count($rows) > 1 ? 'up to ' : '').$pair($mobile, $desktop),
            'tip' => 'Worth when reached, mobile / desktop · '.collect($rows)->map(fn (array $row): string => $row['name'].' '.$pair($row['mobile'], $row['desktop']))->implode(' · ').' · worked out from the traffic on the Value tab',
        ];
    }

    /**
     * The post a step sends the visitor to, as its card names it: the linked
     * post's title, a fresh post, or their last pick.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $postTitles
     */
    private function postTarget(array $settings, array $postTitles): string
    {
        return match ($settings['post_target'] ?? '') {
            GamePostTarget::Chosen->value => is_numeric($settings['post_id'] ?? null) && isset($postTitles[(int) $settings['post_id']])
                ? '“'.Str::limit($postTitles[(int) $settings['post_id']], 40).'”'
                : 'a post not linked yet',
            GamePostTarget::Fresh->value => 'a fresh post',
            default => 'their last pick',
        };
    }

    /** A flow's versions: “Used in V1, V3”, “Only in retired V1” or “Not in a version yet”. */
    private function flowUsage(GameFlow $flow): string
    {
        $numbers = fn (GameVersionStatus $status): string => $flow->versions->where('status', $status)->map(fn (GameVersion $version): string => 'V'.$version->number)->implode(', ');
        $active = $numbers(GameVersionStatus::Active);
        $retired = $numbers(GameVersionStatus::Retired);

        return match (true) {
            $flow->isArchived() => 'Archived',
            $active !== '' => 'Used in '.$active,
            $retired !== '' => 'Only in retired '.$retired,
            default => 'Not in a version yet',
        };
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<int, string>  $postTitles  of the posts the steps link to, by id
     * @return array<string, mixed>
     */
    private function card(array $step, int $index, array $postTitles): array
    {
        $type = GameFlowStepType::tryFrom((string) $step['type']);
        $settings = (array) ($step['settings'] ?? []);
        $texts = (array) ($step['texts'] ?? []);
        $audience = GameStepAudience::tryFrom((string) ($step['audience'] ?? '')) ?? GameStepAudience::All;
        // Every step carries every text column, empty where unused: an empty title falls back to the button.
        $line = trim((string) ($texts['title'] ?? '')) ?: trim((string) ($texts['button_label'] ?? ''));

        return [
            'index' => $index,
            'key' => $step['key'],
            'label' => $type?->label() ?? 'Unknown step',
            'icon' => $type?->icon() ?? 'circle',
            'game' => $type === GameFlowStepType::Game,
            'audience' => $audience === GameStepAudience::All ? null : ($audience === GameStepAudience::Anonymous ? 'Anonymous only' : 'Logged-in only'),
            'summary' => match ($type) {
                GameFlowStepType::Game => ($settings['advance'] ?? 'auto') === 'auto' ? 'Next pair after '.($settings['advance_seconds'] ?? 2.5).' s' : 'Next pair by button',
                GameFlowStepType::EmailCapture => ($settings['required'] ?? false) ? 'Required to continue' : 'Optional · '.($texts['secondary_label'] ?? 'Skip'),
                GameFlowStepType::FullResult => match ($settings['onward'] ?? GameOnward::Next->value) {
                    GameOnward::Post->value => (($settings['share_enabled'] ?? true) ? 'Share · ' : '').'Go to a post: '.$this->postTarget($settings, $postTitles).(($settings['auto_open'] ?? false) ? ', opens by itself' : ''),
                    GameOnward::Feed->value => (($settings['share_enabled'] ?? true) ? 'Share · ' : '').'Go to the feed',
                    GameOnward::Page->value => (($settings['share_enabled'] ?? true) ? 'Share · ' : '').'Go to a page: '.self::pageTarget($settings),
                    default => collect(['show_score' => 'Score', 'show_profile' => 'Profile', 'share_enabled' => 'Share'])->filter(fn ($label, $key) => (bool) ($settings[$key] ?? true))->values()->implode(' · ').' · '.(GameSignupMode::tryFrom((string) ($settings['signup'] ?? 'inline')) ?? GameSignupMode::Inline)->label(),
                },
                GameFlowStepType::Feed => trim((string) ($texts['button_label'] ?? '')).' → '.mb_strtolower(['newest' => 'Newest', 'top' => 'Top', 'hot' => 'Hot'][$settings['feed_sort'] ?? ''] ?? 'the feed'),
                GameFlowStepType::GoToPost => trim((string) ($texts['button_label'] ?? '')).' → '.$this->postTarget($settings, $postTitles).(($settings['auto_open'] ?? false) ? ' · opens by itself' : ''),
                GameFlowStepType::GoToPage => trim((string) ($texts['button_label'] ?? '')).' → '.self::pageTarget($settings),
                default => $line !== '' ? '“'.Str::limit($line, 60).'”' : ($type?->description() ?? ''),
            },
        ];
    }

    /**
     * Where a step's page way on leads, as its card names it.
     *
     * @param  array<string, mixed>  $settings
     */
    private static function pageTarget(array $settings): string
    {
        return match ($settings['destination'] ?? '') {
            GamePageDestination::Tag->value => '#'.($settings['tag'] ?? '').' tag',
            GamePageDestination::Page->value => (string) (($settings['path'] ?? '') ?: 'a page'),
            GamePageDestination::Url->value => Str::limit((string) ($settings['url'] ?? ''), 50),
            default => (($settings['category'] ?? '') ?: 'a').' category',
        };
    }
}
