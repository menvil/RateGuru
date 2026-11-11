<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Data\Games\GameFlowDraft;
use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\User;
use App\Support\Games\Flow\GameFlowStepSettings;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves a flow's steps as the Flow tab holds them: in order, each of a known
 * type with the audience its type allows, settings its type takes and texts
 * within their lengths. Steps the draft no longer has are removed. A flow may
 * be saved while it is not yet valid; the publish report says what it lacks.
 * A published version never changes: it keeps its own snapshot.
 */
final class SaveGameFlowAction
{
    use LocksGameDraft;

    /** The longest each text column holds. */
    public const TEXT_MAX = ['title' => 160, 'body' => 600, 'button_label' => 60, 'secondary_label' => 80, 'field_label' => 60, 'notice' => 300, 'consent_label' => 300];

    public const MOST_STEPS = 20;

    public function __construct(private readonly DomainLogger $logger) {}

    /**
     * @throws CannotManageGameException
     * @throws ValidationException keyed as the draft's paths
     */
    public function handle(User $admin, GameFlow $flow, GameFlowDraft $draft, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $rules = [
            'steps' => ['array', 'max:'.self::MOST_STEPS],
            'steps.*.id' => ['nullable', 'integer', Rule::exists('game_flow_steps', 'id')->where('flow_id', $flow->id)],
            'steps.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'steps.*.type' => ['required', Rule::enum(GameFlowStepType::class)],
            'steps.*.audience' => ['required', Rule::enum(GameStepAudience::class)],
            'steps.*.settings' => ['array'],
            'steps.*.texts' => ['array'],
        ];

        foreach (self::TEXT_MAX as $column => $max) {
            $rules["steps.*.texts.{$column}"] = ['nullable', 'string', 'max:'.$max];
        }

        Validator::make(['steps' => $draft->steps], $rules)->validate();

        DB::transaction(function () use ($admin, $flow, $draft, $expectedLockVersion): void {
            $lockedAdmin = $this->lockAdministrator($admin);
            $game = $this->lockGame((int) $flow->game_id);
            $locked = $this->lockFlow($game, (int) $flow->id, $expectedLockVersion);
            $existing = $locked->steps()->get()->keyBy('id');
            $kept = [];

            foreach ($draft->steps as $position => $data) {
                $type = GameFlowStepType::from((string) $data['type']);
                $step = isset($data['id']) ? $existing->get((int) $data['id']) : null;
                $step ??= new GameFlowStep;
                $texts = [];

                foreach (GameFlowStepType::TEXT_COLUMNS as $column) {
                    $value = trim((string) ($data['texts'][$column] ?? ''));
                    // Only the columns the type uses hold anything.
                    $texts[$column] = array_key_exists($column, $type->texts()) && $value !== '' ? $value : null;
                }

                $step->forceFill([
                    'flow_id' => $locked->id,
                    'position' => $position,
                    'type' => $type,
                    'audience' => $type->fixedAudience() ?? GameStepAudience::from((string) $data['audience']),
                    'settings' => GameFlowStepSettings::normalize($type, (array) ($data['settings'] ?? [])),
                    ...$texts,
                ])->save();
                $kept[] = (int) $step->id;
            }

            $existing->reject(fn (GameFlowStep $step): bool => in_array((int) $step->id, $kept, true))
                ->each(fn (GameFlowStep $step) => $step->delete());

            $locked->forceFill(['lock_version' => $locked->lock_version + 1])->save();
            $this->touchGame($game, $lockedAdmin);
            $flow->setRawAttributes($locked->getAttributes(), true);
        });

        $this->logger->info('game.flow.saved', ['game_id' => $flow->game_id, 'flow_id' => $flow->id, 'admin_id' => $admin->id]);
    }
}
