<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TrafficSegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An administrator's own slice of a paid source's traffic, made under
 * Analytics → Campaigns: a name and the conditions every click of it meets
 * (SegmentRules, as stored). Deleting it only marks it, so Undo brings it
 * back as it was; it is never removed. Saved through
 * SaveTrafficSegmentAction under its lock_version.
 *
 * @property int $id
 * @property int $source_id
 * @property string $name
 * @property array<int, mixed> $rules
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficSegment extends Model
{
    /** @use HasFactory<TrafficSegmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'traffic_segments';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'rules' => 'array',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'lock_version' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
