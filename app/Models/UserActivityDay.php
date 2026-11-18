<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserActivityDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day a member came to the site and did something: which of the things
 * that count they did that UTC day, the day of the account's life it was,
 * and when the first of them happened (RecordUserActivityAction).
 *
 * @property int $id
 * @property int $user_id
 * @property string $day
 * @property int|null $day_number
 * @property bool $voted
 * @property bool $commented
 * @property bool $posted
 * @property bool $played
 * @property CarbonImmutable $first_at
 */
class UserActivityDay extends Model
{
    /** @use HasFactory<UserActivityDayFactory> */
    use HasFactory;

    protected $table = 'user_activity_days';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'day_number' => 'integer',
            'voted' => 'boolean',
            'commented' => 'boolean',
            'posted' => 'boolean',
            'played' => 'boolean',
            'first_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
