<?php

namespace App\Models;

use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\TrafficDevice;
use Carbon\CarbonImmutable;
use Database\Factories\AccountAttributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a new account is credited to — the last game the browser played and the
 * paid click holding it, or the unpaid visit — decided when it signed up, and
 * counted once, when it is confirmed.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $visitor_hash
 * @property AccountSignupMethod|null $signup_method
 * @property AccountSignupPlace|null $signup_place
 * @property CarbonImmutable $signed_up_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $credited_attempt_id
 * @property int|null $credited_visit_id
 * @property string|null $credited_source_key
 * @property TrafficDevice|null $credited_device
 * @property int|null $credited_game_id
 * @property int|null $expired_visit_id
 * @property bool $late
 * @property CarbonImmutable|null $first_post_at
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class AccountAttribution extends Model
{
    /** @use HasFactory<AccountAttributionFactory> */
    use HasFactory;

    protected $table = 'account_attributions';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'signup_method' => AccountSignupMethod::class,
            'signup_place' => AccountSignupPlace::class,
            'signed_up_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'credited_attempt_id' => 'integer',
            'credited_visit_id' => 'integer',
            'credited_device' => TrafficDevice::class,
            'credited_game_id' => 'integer',
            'expired_visit_id' => 'integer',
            'late' => 'boolean',
            'first_post_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * What Analytics → Retention groups and filters an account by, copied
     * from what it is credited to as it is credited: the source key of the
     * visit — the key of the session the visit belonged to — the visit's
     * device, and the game of the attempt; none of a visit or attempt not
     * given.
     *
     * @return array{credited_source_key: string|null, credited_device: TrafficDevice|null, credited_game_id: int|null}
     */
    public static function creditedKeys(?TrafficVisit $visit, ?GameAttempt $attempt): array
    {
        return [
            'credited_source_key' => $visit?->source_key,
            'credited_device' => $visit?->device,
            'credited_game_id' => $attempt === null ? null : (int) $attempt->game_id,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<GameAttempt, $this> */
    public function creditedAttempt(): BelongsTo
    {
        return $this->belongsTo(GameAttempt::class, 'credited_attempt_id');
    }

    /** @return BelongsTo<TrafficVisit, $this> */
    public function creditedVisit(): BelongsTo
    {
        return $this->belongsTo(TrafficVisit::class, 'credited_visit_id');
    }
}
