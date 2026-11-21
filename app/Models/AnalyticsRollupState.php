<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * How far the rows Growth → Analytics reads are (analytics_visits): the day
 * they were filled up to on the way to every day, when every day first was,
 * and when they were last brought up to date. One row, id 1.
 *
 * @property int $id
 * @property string|null $filled_through
 * @property CarbonImmutable|null $filled_at
 * @property CarbonImmutable|null $refreshed_at
 */
class AnalyticsRollupState extends Model
{
    public const int ID = 1;

    protected $table = 'analytics_rollup_states';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'filled_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
        ];
    }

    /** The row, as it stands; one not written yet is nothing filled and nothing refreshed. */
    public static function current(): self
    {
        return self::query()->find(self::ID) ?? new self(['id' => self::ID]);
    }
}
