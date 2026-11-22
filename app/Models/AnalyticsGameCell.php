<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The people of a game's UTC day for one audience and version: each step's
 * people as a roaring bitmap of their numbers — a guest's browser by its
 * number (AnalyticsGamePid), a player by their account's id — and the
 * attempts' sums. A day counted whole has no moment; a day still open is
 * counted up to one (`moment`). Written by RefreshAnalyticsGameCellsAction,
 * read by AnalyticsGameCellsQuery; a bitmap is bytes to every engine but
 * PostgreSQL with the roaringbitmap extension, the only one that writes or
 * reads them.
 *
 * @property int $id
 * @property int $game_id
 * @property string $day
 * @property string $audience
 * @property int|null $version_id
 * @property CarbonImmutable|null $moment
 */
class AnalyticsGameCell extends Model
{
    protected $table = 'analytics_game_cells';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'version_id' => 'integer',
            'moment' => 'immutable_datetime',
            'attempts' => 'integer',
            'completed' => 'integer',
            'goal_reached' => 'integer',
            'registered' => 'integer',
            'unnamed' => 'integer',
        ];
    }
}
