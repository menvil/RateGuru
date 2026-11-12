<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\SaveGameFlowAction;
use App\Data\Games\GameFlowDraft;
use App\Enums\GameAdvanceMode;
use App\Enums\GameAudience;
use App\Enums\GameFlowStepType;
use App\Enums\GameSignupMode;
use App\Enums\GameStepAudience;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameVersion;
use App\Queries\Games\GameUnpublishedChangesQuery;
use App\Services\Games\GamePreviewDrafts;
use App\Support\Games\Flow\DraftFlow;
use App\Support\Games\Flow\FlowIssue;
use App\Support\Games\Flow\FlowVisitor;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Flow\GameFlowStepSettings;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Games\Flow\GameFlowValidator;
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
 * draft until Save in the top bar (SaveGameFlowAction): steps added where
 * they fit, moved, removed, set and worded. The flow's problems are listed as
 * it stands (GameFlowValidator); a step can be added only where it adds none.
 * The selected step's Preview plays it as a phone shows it, for the visitor
 * chosen, from the flow as it stands: each draw hands the draft to
 * GamePreviewDrafts, and the preview frame plays the steps the stamp names.
 */
final class EditGameFlow extends Page
{
    use InteractsWithGameEditor;

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
            app(SaveGameFlowAction::class)->handle($this->admin(), $flow, new GameFlowDraft(array_values($this->steps)), $this->lockVersion);
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
        }
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
            app(ChangeGameFlowAction::class)->rename($this->admin(), $flow, $this->flowName, $this->lockVersion);
        } catch (CannotManageGameException $exception) {
            // A name refused is not shown as the flow's.
            $this->flowName = $flow->name;
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        // The rename moved the flow on from the version on screen, and nothing else did.
        $this->lockVersion++;
        // The name as stored: trimmed, and cut to its limit.
        $this->flowName = (string) $flow->fresh()?->name;
        $this->toast('Flow renamed');
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

        $draft = new GameFlowDraft(array_values($this->steps));
        $drafts = $draft->drafts();
        $issues = GameFlowValidator::issues($drafts);
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

        return [
            'header' => $this->editorHeader($game, 'flow', ['flow' => $issues !== [] ? (string) count($issues) : null], $this->flowId > 0 ? app(GameUnpublishedChangesQuery::class)->forFlow($game, $this->flowId) : null),
            'flows' => $flows->map(fn (GameFlow $item): array => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'chain' => $item->steps->map(fn ($s) => $s->type->label())->implode(' → '),
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
            'cards' => array_map(fn (array $item, int $index): array => $this->card($item, $index), $this->steps, array_keys($this->steps)),
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
                    $type === GameFlowStepType::Registration && $fullResult !== null && ($fullResult['settings']['signup'] ?? 'inline') !== GameSignupMode::Step->value => 'Sign-up is on Full Result. Set its sign-up to a separate step first.',
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
     * @return array<string, mixed>
     */
    private function card(array $step, int $index): array
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
                GameFlowStepType::FullResult => collect(['show_score' => 'Score', 'show_profile' => 'Profile', 'share_enabled' => 'Share'])->filter(fn ($label, $key) => (bool) ($settings[$key] ?? true))->values()->implode(' · ').' · '.(GameSignupMode::tryFrom((string) ($settings['signup'] ?? 'inline')) ?? GameSignupMode::Inline)->label(),
                default => $line !== '' ? '“'.Str::limit($line, 60).'”' : ($type?->description() ?? ''),
            },
        ];
    }
}
