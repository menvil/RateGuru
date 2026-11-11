<?php

namespace App\Livewire\Games;

use App\Enums\GameAnswerOutcome;
use App\Enums\GameAudience;
use App\Enums\GameFlowStepType;
use App\Enums\ImageOrientation;
use App\Models\Game;
use App\Models\GameVersion;
use App\Queries\Games\GameDraftQuery;
use App\Queries\Games\GameFlowVisitorQuery;
use App\Services\Games\GamePreviewDrafts;
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\FlowVisitor;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GamePlayView;
use App\Support\Games\Play\GameVisitor;
use App\Support\Games\Play\PreviewAttempt;
use App\Support\Games\Publishing\VersionSnapshotBuilder;
use App\Support\Games\Scoring\GameScorer;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\Locale\LocaleManager;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A game played as an administrator checks it, before or after publishing:
 * a draft's content set through one of its flows — as saved, or as the
 * Content tab holds it unsaved (`draft`, the stamp GamePreviewDrafts handed
 * it over under), through a flow as saved or as the Flow tab holds it
 * (`flow_draft`) — or a published version, as an anonymous visitor or a
 * signed-in player, in any installed language —
 * from the landing, from a round, or straight to the result for a number of
 * matches.
 *
 * Nothing is recorded: no assignment, attempt, answer, email or event. The
 * picks live in the page's own locked state and are judged the way the game
 * judges them; sign-up and sharing only say what they would do.
 */
final class PreviewGame extends Component
{
    #[Locked]
    public int $gameId = 0;

    #[Locked]
    public int $versionId = 0;

    #[Locked]
    public int $setId = 0;

    #[Locked]
    public int $flowId = 0;

    /** The stamp of the unsaved draft played instead of the saved set; empty plays the set as saved. */
    #[Locked]
    public string $draft = '';

    /** The stamp of the unsaved flow played instead of the saved one; empty plays the flow as saved. */
    #[Locked]
    public string $flowDraft = '';

    /** Drawn inside an editor's phone frame, which says itself that it is a preview: the page leaves out its banner. */
    #[Locked]
    public bool $framed = false;

    /** Every pair's frames in one shape — landscape, square or portrait — to see how such pictures sit; empty frames each pair as its pictures are. */
    #[Locked]
    public string $shape = '';

    #[Locked]
    public string $visitorMode = 'anonymous';

    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public string $stepKey = '';

    /** @var array<int, array{position: int, outcome: string, points: int}> the picks so far, by round */
    #[Locked]
    public array $answers = [];

    #[Locked]
    public bool $emailLeft = false;

    #[Locked]
    public ?array $reveal = null;

    public string $email = '';

    public bool $consent = false;

    private ?GameVersionSnapshot $snapshotRead = null;

    public function mount(int $game): void
    {
        $model = Game::query()->find($game);
        abort_if($model === null, 404);

        $this->gameId = (int) $model->id;
        $request = request();
        $version = $request->integer('version');

        if ($version > 0 && GameVersion::query()->where('game_id', $model->id)->whereKey($version)->exists()) {
            $this->versionId = $version;
        } else {
            $this->setId = $request->integer('set') ?: (int) $model->contentSets()->value('id');
            $this->flowId = $request->integer('flow') ?: (int) $model->flows()->value('id');
            $this->draft = (string) $request->query('draft', '');
            $this->flowDraft = (string) $request->query('flow_draft', '');
        }

        $this->framed = $request->boolean('framed');
        $this->shape = ImageOrientation::tryFrom((string) $request->query('shape', ''))->value ?? '';
        $this->visitorMode = $request->query('visitor') === 'authenticated' ? 'authenticated' : 'anonymous';
        $locale = (string) $request->query('locale', 'en');
        $this->locale = app(LocaleManager::class)->isSupported($locale) ? $locale : 'en';
        app()->setLocale($this->locale);

        $this->start((string) $request->query('start', 'landing'));
    }

    public function hydrate(): void
    {
        app()->setLocale($this->locale);
    }

    /** The page asks once it runs; a preview assigns nobody and counts nothing. */
    public function init(): void {}

    public function continue(): void
    {
        $next = GameFlowRunner::after($this->snapshot()->flowSteps(), $this->flowVisitor(), $this->stepKey);
        $this->stepKey = (string) $next?->key;
    }

    public function choose(mixed $position): void
    {
        $snapshot = $this->snapshot();
        $index = count($this->answers);
        $round = $snapshot->round($index);

        if (! is_numeric($position) || $this->reveal !== null || $round === null || $round->option((int) $position) === null) {
            return;
        }

        $judged = GameScorer::judge($round, (int) $position, $snapshot->pointsPerRound);
        $this->answers[$index] = ['position' => (int) $position, 'outcome' => $judged->outcome->value, 'points' => $judged->points];
        $this->reveal = [
            'round' => $index,
            'position' => (int) $position,
            'answer' => $round->answer,
            'outcome' => $judged->outcome->value,
            'last' => $index + 1 >= $snapshot->roundCount(),
        ];
    }

    public function next(): void
    {
        if ($this->reveal === null) {
            return;
        }

        $last = (bool) $this->reveal['last'];
        $this->reveal = null;

        if ($last) {
            $this->stepKey = (string) $this->afterGame()?->key;
        }
    }

    public function submitEmail(): void
    {
        if (filter_var(trim($this->email), FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('email', __('games.play.email_invalid'));

            return;
        }

        $this->resetErrorBag();
        $this->emailLeft = true;
        $this->continue();
    }

    public function signUp(mixed $mode = 'register'): void
    {
        $this->dispatch('toast', message: 'Preview: here the site’s sign-up would open. Nothing is created.');
    }

    public function signUpStarted(mixed $method): void {}

    public function shared(mixed $provider): void {}

    public function replay(): void
    {
        $this->answers = [];
        $this->reveal = null;
        $this->emailLeft = false;
        $this->stepKey = (string) GameFlowRunner::ofType($this->snapshot()->flowSteps(), GameFlowStepType::Game)?->key;
    }

    public function leave(mixed $to): void
    {
        $this->dispatch('toast', message: 'Preview: here the visitor would go to '.($to === 'next' ? 'the next game.' : 'the feed.'));
    }

    public function render(): View
    {
        $game = Game::query()->find($this->gameId);
        abort_if($game === null, 404);

        $snapshot = $this->snapshot();
        $attempt = PreviewAttempt::of((int) $game->id, $this->visitor()->audience, $snapshot, $this->answers);
        $hasNext = $this->stepKey !== '' && GameFlowRunner::after($snapshot->flowSteps(), $this->flowVisitor(), $this->stepKey) !== null;
        $view = GamePlayView::of($game, $this->versionId, $snapshot, $this->stepKey, $attempt, $this->reveal, $this->visitor(), $this->locale, null, $hasNext, preview: true, shape: ImageOrientation::tryFrom($this->shape), pictures: $this->chosenPictures());
        $source = $this->versionId > 0 ? 'V'.GameVersion::query()->whereKey($this->versionId)->value('number') : __('games.play.preview_draft');

        return view('livewire.games.play-game', ['play' => $view, 'preview' => $source, 'banner' => ! $this->framed])
            ->layout('layouts.app', [...app(AppLayoutData::class)->toArray(), 'focused' => true]);
    }

    /**
     * Where the preview starts: the landing, a round with the rounds before it
     * answered as the crowd did, the result for a number of matches, or any
     * step of the flow — one after the game with every round answered as the
     * crowd did.
     */
    private function start(string $start): void
    {
        $snapshot = $this->snapshot();
        $steps = $snapshot->flowSteps();
        $this->stepKey = (string) GameFlowRunner::first($steps, $this->flowVisitor())?->key;

        if (preg_match('/\Astep:(.+)\z/', $start, $match) === 1) {
            $this->startAtStep($match[1]);

            return;
        }

        if (preg_match('/\A(round|result):(\d+)\z/', $start, $match) !== 1) {
            return;
        }

        $number = (int) $match[2];

        if ($match[1] === 'round') {
            $index = max(0, min($number, $snapshot->roundCount() - 1));
            $this->answerFirst($index, $index);
            $this->stepKey = (string) GameFlowRunner::ofType($steps, GameFlowStepType::Game)?->key;

            return;
        }

        $this->answerFirst($snapshot->roundCount(), $number);
        $this->stepKey = (string) (GameFlowRunner::ofType($steps, GameFlowStepType::FullResult) ?? $this->afterGame())?->key;
    }

    private function startAtStep(string $key): void
    {
        $snapshot = $this->snapshot();
        $steps = $snapshot->flowSteps();
        $keys = array_map(fn (FlowStepDraft $step): string => $step->key, $steps);
        $at = array_search($key, $keys, true);
        $game = GameFlowRunner::ofType($steps, GameFlowStepType::Game);

        if (! is_int($at)) {
            return;
        }

        $gameAt = $game === null ? null : array_search($game->key, $keys, true);

        if (is_int($gameAt) && $at > $gameAt) {
            $this->answerFirst($snapshot->roundCount(), $snapshot->roundCount());
        }

        // A step this visitor never sees gives way to the next one they do.
        $this->stepKey = GameFlowRunner::isVisible($steps[$at], $this->flowVisitor())
            ? $key
            : (string) GameFlowRunner::after($steps, $this->flowVisitor(), $key)?->key;
    }

    /** Answers the first rounds, matching in as many scored rounds as asked. */
    private function answerFirst(int $rounds, int $matches): void
    {
        $snapshot = $this->snapshot();
        $this->answers = [];

        for ($index = 0; $index < $rounds; $index++) {
            $round = $snapshot->round($index);

            if ($round === null) {
                break;
            }

            $answer = $round->answer ?? 0;
            $hit = $round->scored && $matches > 0;
            $position = $hit ? $answer : 1 - $answer;
            $judged = GameScorer::judge($round, $position, $snapshot->pointsPerRound);
            $matches -= $judged->outcome === GameAnswerOutcome::Correct ? 1 : 0;
            $this->answers[$index] = ['position' => $position, 'outcome' => $judged->outcome->value, 'points' => $judged->points];
        }
    }

    private function afterGame(): ?FlowStepDraft
    {
        $steps = $this->snapshot()->flowSteps();
        $game = GameFlowRunner::ofType($steps, GameFlowStepType::Game);

        return $game === null ? null : GameFlowRunner::after($steps, $this->flowVisitor(), $game->key);
    }

    private function snapshot(): GameVersionSnapshot
    {
        if ($this->snapshotRead !== null) {
            return $this->snapshotRead;
        }

        if ($this->versionId > 0) {
            return $this->snapshotRead = GameVersion::query()->findOrFail($this->versionId)->read();
        }

        $game = Game::query()->findOrFail($this->gameId);
        $query = app(GameDraftQuery::class);
        $unsaved = $this->draft !== '' ? app(GamePreviewDrafts::class)->get((int) auth()->id(), $this->setId, $this->draft) : null;
        $set = $unsaved !== null ? $query->draftContentSet((int) $game->id, $this->setId, $unsaved) : $query->contentSet((int) $game->id, $this->setId);
        $unsavedFlow = $this->flowDraft !== '' ? app(GamePreviewDrafts::class)->getFlow((int) auth()->id(), $this->flowId, $this->flowDraft) : null;
        $flow = $unsavedFlow !== null ? $query->draftFlow((int) $game->id, $this->flowId, $unsavedFlow) : $query->flow((int) $game->id, $this->flowId);
        abort_if($set === null || $flow === null, 404);

        return $this->snapshotRead = VersionSnapshotBuilder::buildForPreview($game, ContentSetReport::of($set), $flow);
    }

    /**
     * The pictures the Content tab chose for options but has not stored yet,
     * shown in their places while the unsaved draft plays.
     *
     * @return array<string, array{src: string, width: int|null, height: int|null}>
     */
    private function chosenPictures(): array
    {
        return $this->draft !== '' && $this->versionId === 0
            ? app(GamePreviewDrafts::class)->pictures((int) auth()->id(), $this->setId, $this->draft)
            : [];
    }

    private function visitor(): GameVisitor
    {
        return $this->visitorMode === 'authenticated'
            ? new GameVisitor(GameAudience::Authenticated, (int) auth()->id(), null)
            : new GameVisitor(GameAudience::Anonymous, null, str_repeat('0', 64));
    }

    private function flowVisitor(): FlowVisitor
    {
        $visitor = $this->visitor();

        // Next Game as the game decides it: live, and giving this audience a version.
        $nextGame = app(GameFlowVisitorQuery::class)->nextGame($this->snapshot(), $visitor->audience);

        return new FlowVisitor($visitor->audience, $this->visitorMode === 'authenticated' || $this->emailLeft, $nextGame !== null);
    }
}
