<?php

namespace App\Models;

use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Support\Games\Flow\FlowStepDraft;
use Database\Factories\GameFlowStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One screen of a flow. Its behaviour is `settings`, as GameFlowStepSettings
 * keeps it; its texts are the fixed columns, each meaning what its type says
 * (GameFlowStepType::texts()), each with its translations beside it.
 *
 * @property int $id
 * @property int $flow_id
 * @property int $position
 * @property GameFlowStepType $type
 * @property GameStepAudience $audience
 * @property array<string, bool|int|float|string|null> $settings
 * @property string|null $title
 * @property string|null $body
 * @property string|null $button_label
 * @property string|null $secondary_label
 * @property string|null $field_label
 * @property string|null $notice
 * @property string|null $consent_label
 */
class GameFlowStep extends Model
{
    /** @use HasFactory<GameFlowStepFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id', 'flow_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        $casts = [
            'flow_id' => 'integer',
            'position' => 'integer',
            'type' => GameFlowStepType::class,
            'audience' => GameStepAudience::class,
            'settings' => 'array',
        ];

        foreach (GameFlowStepType::TEXT_COLUMNS as $column) {
            $casts[$column.'_translations'] = 'array';
        }

        return $casts;
    }

    /** The key a published version and a player's place in the flow name the step by. */
    public function key(): string
    {
        return 's'.$this->id;
    }

    /** The step as the flow rules read it. */
    public function draft(): FlowStepDraft
    {
        $texts = [];

        foreach (GameFlowStepType::TEXT_COLUMNS as $column) {
            $texts[$column] = $this->getAttribute($column);
        }

        return new FlowStepDraft($this->key(), $this->type, $this->audience, $this->settings ?? [], $texts);
    }

    /** @return BelongsTo<GameFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(GameFlow::class, 'flow_id');
    }
}
