<?php

namespace App\Models;

use App\Support\Traffic\Worth\WorthPrices;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficPriceSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * What the outcomes of a visit are worth — a confirmed account, its two
 * bonuses, a sign-in to an account the visitor already had — with the country
 * groups. Never changed once written: saving prices or groups adds a set, the
 * newest is in force, and every visit keeps the set in force when it came.
 *
 * @property int $id
 * @property string $account_price
 * @property string $post_bonus
 * @property string $returned_bonus
 * @property string $login_price
 * @property list<array{key: string, name: string, percent: int, countries: list<string>}> $country_groups
 * @property string|null $summary
 * @property int|null $created_by_user_id
 * @property CarbonImmutable $created_at
 */
class TrafficPriceSet extends Model
{
    /** @use HasFactory<TrafficPriceSetFactory> */
    use HasFactory;

    protected $table = 'traffic_price_sets';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_price' => 'decimal:4',
            'post_bonus' => 'decimal:4',
            'returned_bonus' => 'decimal:4',
            'login_price' => 'decimal:4',
            'country_groups' => 'array',
            'created_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A price set is never changed; saving prices adds a new set.');
        });
    }

    /** The set in force: the newest one. */
    public static function current(): self
    {
        return self::query()->orderByDesc('id')->firstOrFail();
    }

    /** The set's prices, as the worth of a visit and its steps is worked out with them. */
    public function worth(): WorthPrices
    {
        return new WorthPrices((string) $this->account_price, (string) $this->post_bonus, (string) $this->returned_bonus, (string) $this->login_price);
    }

    /**
     * The group a country belongs to — the last group holds every country the
     * others do not name — with the share of the price an account from it is
     * worth.
     *
     * @return array{key: string, percent: int}
     */
    public function groupOf(?string $country): array
    {
        $groups = $this->country_groups;
        $last = $groups[array_key_last($groups)];

        foreach ($groups as $group) {
            if ($country !== null && in_array($country, $group['countries'], true)) {
                return ['key' => $group['key'], 'percent' => (int) $group['percent']];
            }
        }

        return ['key' => $last['key'], 'percent' => (int) $last['percent']];
    }
}
