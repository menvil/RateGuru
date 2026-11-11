<?php

namespace App\Models;

use App\Enums\GameRoundMode;
use Database\Factories\GameRoundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question of a content set, with its options in their slots. Whether it
 * can be published, and how it scores, is VersusRoundCheck's to say.
 *
 * @property int $id
 * @property int $content_set_id
 * @property int $position
 * @property string $internal_name
 * @property string|null $question
 * @property array<string, string>|null $question_translations
 * @property string|null $description
 * @property array<string, string>|null $description_translations
 * @property string|null $reveal_message
 * @property array<string, string>|null $reveal_message_translations
 * @property GameRoundMode $mode
 * @property bool $unscored
 * @property bool $show_crowd
 */
class GameRound extends Model
{
    /** @use HasFactory<GameRoundFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id', 'content_set_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'mode' => 'crowd',
        'unscored' => false,
        'show_crowd' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content_set_id' => 'integer',
            'position' => 'integer',
            'question_translations' => 'array',
            'description_translations' => 'array',
            'reveal_message_translations' => 'array',
            'mode' => GameRoundMode::class,
            'unscored' => 'boolean',
            'show_crowd' => 'boolean',
        ];
    }

    /** @return BelongsTo<GameContentSet, $this> */
    public function contentSet(): BelongsTo
    {
        return $this->belongsTo(GameContentSet::class, 'content_set_id');
    }

    /** @return HasMany<GameRoundOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(GameRoundOption::class, 'round_id')->orderBy('position');
    }
}
