<?php

namespace App\Livewire\Games;

use App\Actions\Games\AssignGameVersionAction;
use App\Actions\Games\CaptureGameEmailAction;
use App\Actions\Games\MoveGameAttemptAction;
use App\Actions\Games\RecordGameAnswerAction;
use App\Actions\Games\RecordGameInteractionAction;
use App\Actions\Games\StartGameAttemptAction;
use App\Enums\AuthModalMode;
use App\Enums\GameAttemptStatus;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameStatus;
use App\Enums\SocialProvider;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Games\CannotPlayGameException;
use App\Livewire\Concerns\OpensAuthModal;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use App\Queries\Games\GameFlowVisitorQuery;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Flow\FlowVisitor;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GamePlayView;
use App\Support\Games\Play\GameSessionAttempts;
use App\Support\Games\Play\GameVisitor;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * A live game, played: the version the visitor is assigned, through its flow,
 * step by step. Everything that counts is the server's — which step comes
 * next, what a pick earned, the result — through the game actions; the page
 * only draws it, and marks a pick the moment it is made while the server
 * judges it.
 *
 * Every request re-reads the attempt and asks that it is this visitor's: in
 * this browser session's list, or the signed-in person's own. A refresh
 * resumes the attempt where it stands; signing in or registering in between
 * keeps it, and the steps for anonymous visitors are skipped from then on.
 */
final class PlayGame extends Component
{
    use OpensAuthModal;

    #[Locked]
    public int $gameId = 0;

    #[Locked]
    public int $versionId = 0;

    #[Locked]
    public ?string $attemptId = null;

    /** The step on screen, by its key in the version; empty for the end card. */
    #[Locked]
    public string $stepKey = '';

    /** Why the game cannot be played now, when it cannot. */
    #[Locked]
    public ?string $unavailable = null;

    /** The pick just made and what it earned, while its reveal is on screen. */
    #[Locked]
    public ?array $reveal = null;

    public string $email = '';

    public bool $consent = false;

    private ?GameVersionSnapshot $snapshotRead = null;

    public function mount(string $slug): void
    {
        $game = Game::query()->where('slug', $slug)->first();
        abort_if($game === null || $game->status === GameStatus::Draft, 404);

        $this->gameId = (int) $game->id;
        $visitor = $this->visitor();
        $attempt = $this->resumable($game, $visitor);

        if ($attempt !== null) {
            $this->versionId = (int) $attempt->version_id;
            $this->attemptId = $attempt->public_id;
            $this->stepKey = (string) $attempt->current_step_key;
            $this->skipInvisibleStep($attempt, $visitor);

            return;
        }

        $this->unavailable = match ($game->status) {
            GameStatus::Paused => 'paused',
            GameStatus::Archived => 'ended',
            default => null,
        };

        if ($this->unavailable !== null) {
            return;
        }

        $versionId = app(AssignGameVersionAction::class)->preview($game, $visitor);

        if ($versionId === null || app(GameTrafficQuery::class)->unplayableVersionIds([$versionId]) !== []) {
            $this->unavailable = 'unavailable';

            return;
        }

        $this->versionId = $versionId;
        $this->stepKey = (string) GameFlowRunner::first($this->snapshot()->flowSteps(), $this->flowVisitor($visitor))?->key;
    }

    /** Once the browser runs the page: the visitor is assigned and the view counted. */
    public function init(): void
    {
        $game = $this->game();

        if ($game === null || $this->unavailable !== null || $this->attemptId !== null) {
            return;
        }

        $assignment = app(AssignGameVersionAction::class)->handle($game, $this->visitor(), viewed: true);

        // The shares changed between the page and now: start from the version given.
        if ($assignment !== null && (int) $assignment->version_id !== $this->versionId) {
            $this->versionId = (int) $assignment->version_id;
            $this->snapshotRead = null;
            $this->stepKey = (string) GameFlowRunner::first($this->snapshot()->flowSteps(), $this->flowVisitor($this->visitor()))?->key;
        }
    }

    /** On from the step on screen: before the game by the flow alone, after it through the attempt. */
    public function continue(): void
    {
        // The game is left only by its last reveal (next()), never stepped past.
        if ($this->currentType() === GameFlowStepType::Game) {
            return;
        }

        $visitor = $this->visitor();
        $attempt = $this->attempt();

        if ($attempt !== null && $attempt->isCompleted()) {
            $this->moveAttempt($attempt, $visitor);

            return;
        }

        $steps = $this->snapshot()->flowSteps();
        $next = GameFlowRunner::after($steps, $this->flowVisitor($visitor), $this->stepKey);

        if ($next?->type === GameFlowStepType::Game) {
            if ($this->startAttempt(false) !== null) {
                $this->stepKey = $next->key;
            }

            return;
        }

        $this->stepKey = (string) $next?->key;
    }

    /**
     * A pick, for the round on screen as the page sends it along. A page that
     * sends no round is taken to show the round the attempt stands on.
     */
    public function choose(mixed $position, mixed $round = null): void
    {
        if (! is_numeric($position) || $this->currentType() !== GameFlowStepType::Game || $this->reveal !== null) {
            return;
        }

        // A flow that opens on the game starts the attempt with its first pick;
        // an attempt this visitor cannot go on with is never replaced unseen.
        $attempt = $this->attemptId === null ? $this->startAttempt(false) : $this->attempt();

        if ($attempt === null) {
            return;
        }

        // Another tab finished the game meanwhile: on to where the attempt stands.
        if ($attempt->status !== GameAttemptStatus::Started) {
            $this->stepKey = (string) $attempt->current_step_key;

            return;
        }

        $roundIndex = $attempt->rounds_answered;

        // A tab still showing a round another tab already answered answers
        // nothing; the page draws the round the attempt stands on instead.
        if ($round !== null && (! is_numeric($round) || (int) $round !== $roundIndex)) {
            return;
        }

        try {
            $answer = app(RecordGameAnswerAction::class)->handle($attempt, $this->visitor(), $roundIndex, (int) $position);
        } catch (CannotPlayGameException) {
            $this->unavailable = 'unavailable';

            return;
        } catch (RateLimitExceededException $exception) {
            $this->dispatch('toast', message: $exception->getMessage());

            return;
        }

        $round = $this->snapshot()->round($roundIndex);
        $this->reveal = [
            'round' => $roundIndex,
            'position' => $answer->option_position,
            'answer' => $round?->answer,
            'outcome' => $answer->outcome->value,
            'last' => $roundIndex + 1 >= $this->snapshot()->roundCount(),
        ];
    }

    /** From a revealed pick to the next pair, or after the last one to the step after the game. */
    public function next(): void
    {
        if ($this->reveal === null) {
            return;
        }

        $this->reveal = null;
        $attempt = $this->attempt();

        if ($attempt !== null && $attempt->isCompleted()) {
            $this->stepKey = (string) $attempt->current_step_key;
        }
    }

    public function submitEmail(): void
    {
        $attempt = $this->attempt();

        if ($attempt === null || $this->currentType() !== GameFlowStepType::EmailCapture) {
            return;
        }

        try {
            app(CaptureGameEmailAction::class)->handle($attempt, $this->visitor(), $this->email, $this->consent);
        } catch (CannotPlayGameException $exception) {
            $this->addError('email', __($exception->reason === 'email_invalid' ? 'games.play.email_invalid' : 'games.play.unavailable'));

            return;
        } catch (RateLimitExceededException $exception) {
            $this->addError('email', $exception->getMessage());

            return;
        }

        $this->resetErrorBag();
        $this->moveAttempt($attempt, $this->visitor());
    }

    /** The site's sign-up or log-in dialog, opened from the game — counted, by which of the two. */
    public function signUp(mixed $mode = 'register'): void
    {
        $mode = $mode === 'login' ? AuthModalMode::Login : AuthModalMode::Register;
        $game = $this->game();

        if ($game !== null) {
            app(RecordGameInteractionAction::class)->handle(GameEventType::RegistrationCtaClicked, $game, $this->visitor(), $this->attempt(), $this->versionId, $mode === AuthModalMode::Login ? 'login' : 'register');
        }

        $this->openAuthModal($mode);
    }

    /** Signing up begun on the result itself — on with an email, or off to a provider — counted, by which. */
    #[Renderless]
    public function signUpStarted(mixed $method): void
    {
        $game = $this->game();
        $methods = ['email', ...array_map(fn (SocialProvider $provider): string => $provider->value, SocialProvider::cases())];

        if ($game !== null && is_string($method) && in_array($method, $methods, true)) {
            app(RecordGameInteractionAction::class)->handle(GameEventType::RegistrationCtaClicked, $game, $this->visitor(), $this->attempt(), $this->versionId, $method);
        }
    }

    /** A share made in the browser — the link copied, the device's own sheet — counted. */
    public function shared(mixed $provider): void
    {
        $game = $this->game();

        if ($game !== null && is_string($provider) && in_array($provider, ['copy_link', 'native'], true)) {
            app(RecordGameInteractionAction::class)->handle(GameEventType::ShareClicked, $game, $this->visitor(), $this->attempt(), $this->versionId, $provider);
        }
    }

    public function replay(): void
    {
        $attempt = $this->startAttempt(true);

        if ($attempt !== null) {
            $this->versionId = (int) $attempt->version_id;
            $this->snapshotRead = null;
            $this->stepKey = (string) $attempt->current_step_key;
            $this->reveal = null;
        }
    }

    /** Leaves the game for the feed or the next game, counted as an exit. */
    public function leave(mixed $to): void
    {
        $game = $this->game();
        $next = $to === 'next' ? $this->nextGame() : null;

        if ($game !== null) {
            // Where the visitor is actually sent: the feed, when the next game is no longer there for them.
            app(RecordGameInteractionAction::class)->handle(GameEventType::Exit, $game, $this->visitor(), $this->attempt(), $this->versionId, $next !== null ? 'next_game' : 'feed');
        }

        $this->redirect($next !== null ? route('games.play', ['slug' => $next->slug]) : route('feed'));
    }

    public function render(): View
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $view = $this->unavailable !== null || $this->versionId === 0
            ? GamePlayView::unavailable($game, $this->unavailable ?? 'unavailable')
            : GamePlayView::of($game, $this->versionId, $this->snapshot(), $this->stepKey, $this->attempt(), $this->reveal, $this->visitor(), app()->getLocale(), $this->nextGame(), $this->hasNextStep());

        return view('livewire.games.play-game', ['play' => $view])
            ->layout('layouts.app', [...app(AppLayoutData::class)->toArray(), 'focused' => true]);
    }

    /** Whether a step the visitor sees comes after the one on screen. */
    private function hasNextStep(): bool
    {
        return $this->stepKey !== '' && GameFlowRunner::after($this->snapshot()->flowSteps(), $this->flowVisitor($this->visitor()), $this->stepKey) !== null;
    }

    private function moveAttempt(GameAttempt $attempt, GameVisitor $visitor): void
    {
        try {
            $this->stepKey = (string) app(MoveGameAttemptAction::class)->handle($attempt, $visitor, $this->stepKey);
        } catch (CannotPlayGameException) {
            $this->addError('email', __('games.play.email_required'));
        }
    }

    /** A step the visitor no longer sees — Registration, once signed in — is passed over. */
    private function skipInvisibleStep(GameAttempt $attempt, GameVisitor $visitor): void
    {
        if (! $attempt->isCompleted() || $this->stepKey === '') {
            return;
        }

        $step = GameFlowRunner::find($this->snapshot()->flowSteps(), $this->stepKey);

        if ($step !== null && ! GameFlowRunner::isVisible($step, $this->flowVisitor($visitor))) {
            $this->moveAttempt($attempt, $visitor);
        }
    }

    private function startAttempt(bool $replay): ?GameAttempt
    {
        $game = $this->game();

        if ($game === null) {
            return null;
        }

        try {
            $attempt = app(StartGameAttemptAction::class)->handle($game, $this->visitor(), app()->getLocale(), $replay);
        } catch (CannotPlayGameException $exception) {
            $this->unavailable = $exception->reason === 'not_live' ? ($game->status === GameStatus::Archived ? 'ended' : 'paused') : 'unavailable';

            return null;
        } catch (RateLimitExceededException $exception) {
            $this->dispatch('toast', message: $exception->getMessage());

            return null;
        }

        GameSessionAttempts::remember(session()->driver(), $attempt->public_id);
        $this->attemptId = $attempt->public_id;
        $this->versionId = (int) $attempt->version_id;

        return $attempt;
    }

    /**
     * The attempt on screen, when it is this visitor's: one this browser
     * session started, or the signed-in person's own.
     */
    private function attempt(): ?GameAttempt
    {
        if ($this->attemptId === null) {
            return null;
        }

        $attempt = GameAttempt::query()->where('public_id', $this->attemptId)->where('game_id', $this->gameId)->first();
        $userId = auth()->id();

        if ($attempt === null || (! GameSessionAttempts::holds(session()->driver(), $attempt->public_id) && ($userId === null || $attempt->user_id !== (int) $userId))) {
            return null;
        }

        return $attempt;
    }

    /** An attempt of this game the visitor can go on with: started and not abandoned, or with steps left after the game. */
    private function resumable(Game $game, GameVisitor $visitor): ?GameAttempt
    {
        $ids = GameSessionAttempts::all(session()->driver());
        $since = now()->subHours((int) config('games.abandoned_after_hours', 24));

        $attempt = GameAttempt::query()
            ->where('game_id', $game->id)
            ->where('last_activity_at', '>=', $since)
            ->where(fn ($mine) => $mine->whereIn('public_id', $ids)->when($visitor->userId !== null, fn ($own) => $own->orWhere('user_id', $visitor->userId)))
            ->latest('id')
            ->first();

        if ($attempt === null || ! $game->status->letsStartedAttemptsFinish() && $attempt->status === GameAttemptStatus::Started) {
            return null;
        }

        if ($attempt->isCompleted() && $attempt->current_step_key === null) {
            return null;
        }

        if ($attempt->user_id !== null && ! in_array($attempt->public_id, $ids, true)) {
            GameSessionAttempts::remember(session()->driver(), $attempt->public_id);
        }

        return $attempt;
    }

    private function game(): ?Game
    {
        return Game::query()->find($this->gameId);
    }

    private function nextGame(): ?Game
    {
        if ($this->versionId === 0) {
            return null;
        }

        return app(GameFlowVisitorQuery::class)->nextGame($this->snapshot(), $this->visitor()->audience);
    }

    private function snapshot(): GameVersionSnapshot
    {
        return $this->snapshotRead ??= GameVersion::query()->findOrFail($this->versionId)->read();
    }

    private function currentType(): ?GameFlowStepType
    {
        return GameFlowRunner::find($this->snapshot()->flowSteps(), $this->stepKey)?->type;
    }

    private function visitor(): GameVisitor
    {
        return app(GameVisitorResolver::class)->resolve();
    }

    private function flowVisitor(GameVisitor $visitor): FlowVisitor
    {
        return app(GameFlowVisitorQuery::class)->for($visitor, $this->snapshot(), $visitor->audience);
    }
}
