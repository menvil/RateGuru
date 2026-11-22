<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A UTC day of the games' cells (AnalyticsGameCell) that something changed
 * since it was counted — an attempt of it completed, its goal reached or an
 * account credited to it confirmed once the day had closed, or an attempt or
 * a view of it deleted — with the token of the latest change and the moment
 * it was first marked (MarkAnalyticsGameCellsAction).
 *
 * @property string $day
 * @property string $token
 * @property CarbonImmutable $marked_at
 */
class AnalyticsDirtyGameDay extends Model
{
    protected $table = 'analytics_dirty_game_days';

    protected $primaryKey = 'day';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['marked_at' => 'immutable_datetime'];
    }
}
