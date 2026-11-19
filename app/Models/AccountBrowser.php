<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AccountBrowserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser an account signed up or signed in with, by the hash of its
 * rg_game_visitor token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $visitor_hash
 * @property CarbonImmutable $linked_at
 */
class AccountBrowser extends Model
{
    /** @use HasFactory<AccountBrowserFactory> */
    use HasFactory;

    protected $table = 'account_browsers';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'linked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
