<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email a guest left on an Email Capture step, with the consent wording
 * they saw and whether they ticked it. Stored only — nothing is ever sent to
 * it — and deleted after a fixed number of days (games:purge-expired)
 * or with the account its attempt was later claimed by.
 *
 * @property int $id
 * @property int $attempt_id
 * @property int $game_id
 * @property string $email
 * @property bool $marketing_consent
 * @property string|null $consent_label
 * @property CarbonImmutable $captured_at
 */
class GameEmailCapture extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_id' => 'integer',
            'game_id' => 'integer',
            'marketing_consent' => 'boolean',
            'captured_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<GameAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(GameAttempt::class, 'attempt_id');
    }
}
