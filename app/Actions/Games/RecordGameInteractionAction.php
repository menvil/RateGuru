<?php

namespace App\Actions\Games;

use App\Enums\GameEventType;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Support\Games\Play\GameVisitor;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Records what a visitor did that changes nothing else: a share clicked, the
 * registration invitation clicked, the game left by its exit. A burst of the
 * same from one visitor counts once a moment.
 */
final class RecordGameInteractionAction
{
    private const TYPES = [GameEventType::ShareClicked, GameEventType::RegistrationCtaClicked, GameEventType::Exit];

    public function __construct(private readonly GameEventRecorder $events) {}

    public function handle(GameEventType $type, Game $game, GameVisitor $visitor, ?GameAttempt $attempt, ?int $versionId = null, ?string $detail = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Only an interaction is recorded here.');
        }

        $key = 'game-interaction:'.$type->value.':'.$game->id.':'.$visitor->identityKey().':'.$detail;

        if (RateLimiter::hit($key, 10) > 3) {
            return;
        }

        $this->events->record($type, (int) $game->id, $visitor, versionId: $versionId, attempt: $attempt, detail: [
            'provider' => $type === GameEventType::ShareClicked ? $detail : null,
            'step_type' => $type === GameEventType::Exit ? $detail : null,
        ]);
    }
}
