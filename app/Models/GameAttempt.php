<?php

namespace App\Models;

use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use Carbon\CarbonImmutable;
use Database\Factories\GameAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One play of a version from Start to its last screen, pinned to that version
 * for good. Its score, matches and profile are the server's, worked out from
 * its answers against the version's snapshot when the last answer arrives.
 *
 * @property int $id
 * @property string $public_id
 * @property int $game_id
 * @property int $version_id
 * @property int|null $assignment_id
 * @property GameAudience $audience
 * @property int|null $user_id
 * @property string|null $visitor_hash
 * @property GameAttemptStatus $status
 * @property string|null $current_step_key
 * @property int $rounds_answered
 * @property int|null $matches
 * @property int|null $score
 * @property int $max_score
 * @property string|null $profile_key
 * @property string $locale
 * @property GameAccountOutcome|null $account_outcome
 * @property CarbonImmutable|null $account_outcome_at
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $last_activity_at
 */
class GameAttempt extends Model
{
    /** @use HasFactory<GameAttemptFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'version_id' => 'integer',
            'assignment_id' => 'integer',
            'audience' => GameAudience::class,
            'user_id' => 'integer',
            'status' => GameAttemptStatus::class,
            'rounds_answered' => 'integer',
            'matches' => 'integer',
            'score' => 'integer',
            'max_score' => 'integer',
            'account_outcome' => GameAccountOutcome::class,
            'account_outcome_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === GameAttemptStatus::Completed;
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<GameVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class);
    }

    /** @return BelongsTo<GameAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(GameAssignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<GameAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(GameAnswer::class, 'attempt_id')->orderBy('round_index');
    }

    /** @return HasOne<GameEmailCapture, $this> */
    public function emailCapture(): HasOne
    {
        return $this->hasOne(GameEmailCapture::class, 'attempt_id');
    }
}
