<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The short number a guest's browser is counted by in a game's cells
 * (AnalyticsGameCell): the hash of its cookie, the key a game's figures tell
 * guests apart by, given a number once, in turn, and never another; kept
 * while any cell holds it.
 *
 * @property string $visitor_hash
 * @property int $pid
 */
class AnalyticsGamePid extends Model
{
    protected $table = 'analytics_game_pids';

    protected $primaryKey = 'visitor_hash';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pid' => 'integer'];
    }
}
