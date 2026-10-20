<?php

namespace App\Models;

use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use Carbon\CarbonImmutable;
use Database\Factories\StudioActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One scheduled domain write of an approved publication plan — its post, and
 * later its comments, replies and votes — as a row of Content Studio's
 * business queue (docs/architecture/studio-plans.md, "The action it
 * writes"). The row is the source of truth: a queue job will only ever carry
 * its id.
 *
 * Two moments: `occurs_at` is the date the record it writes takes as its own
 * — a post's publication date, past or future — and `run_after` the earliest
 * it may run. A past date runs as soon as it may, under that past date; a
 * future one waits for it. Being run late never changes `occurs_at`.
 *
 * Approval writes a plan's actions, Pending; the dispatcher and the executor
 * run them ("Running a plan" there). Nothing is mass assignable: every column
 * is the server's to write.
 *
 * @property int $id
 * @property int $studio_plan_id
 * @property int|null $parent_action_id
 * @property StudioActionType $type
 * @property int $actor_user_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $occurs_at
 * @property CarbonImmutable $run_after
 * @property StudioActionStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $result_type
 * @property int|null $result_id
 * @property string|null $failure_code
 * @property string|null $failure_reason
 */
class StudioAction extends Model
{
    /** @use HasFactory<StudioActionFactory> */
    use HasFactory;

    protected $table = 'studio_plan_actions';

    protected $guarded = ['*'];

    /**
     * A new action waits, untried.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
        'payload' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'studio_plan_id' => 'integer',
            'parent_action_id' => 'integer',
            'type' => StudioActionType::class,
            'actor_user_id' => 'integer',
            'payload' => 'array',
            'occurs_at' => 'immutable_datetime',
            'run_after' => 'immutable_datetime',
            'status' => StudioActionStatus::class,
            'attempts' => 'integer',
            'dispatched_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'result_id' => 'integer',
        ];
    }

    /** @return BelongsTo<StudioPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudioPlan::class, 'studio_plan_id');
    }

    /**
     * The Persona the write is done as.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * The action this one waits for, such as the post a comment is on.
     *
     * @return BelongsTo<StudioAction, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_action_id');
    }

    /** @return HasMany<StudioAction, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_action_id');
    }
}
