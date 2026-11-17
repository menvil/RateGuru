<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficPriceSet;
use App\Models\User;
use App\Support\Geo\Countries;
use App\Support\Traffic\Money\TrafficMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sets what an account is worth to the site — the account, its first post,
 * its return on another day — or the country groups and the share of that
 * price each is worth. Either way a new set of prices: a set is never
 * changed, every visit keeps the set it arrived under, and postbacks already
 * sent keep what they said. The editor names the set it was drawn from, and
 * a set saved since refuses the change.
 */
final class SaveTrafficPricesAction
{
    use LocksTrafficRecords;

    public const int MOST_GROUPS = 8;

    /**
     * @param  array{account_price: mixed, post_bonus: mixed, returned_bonus: mixed}  $prices
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function prices(User $admin, array $prices, int $drawnFrom): TrafficPriceSet
    {
        $money = ['required', 'numeric', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'min:0', 'max:1000'];
        Validator::make($prices, ['account_price' => $money, 'post_bonus' => $money, 'returned_bonus' => $money], [], [
            'account_price' => 'price of an account', 'post_bonus' => 'first post bonus', 'returned_bonus' => 'return bonus',
        ])->validate();

        return $this->save($admin, $drawnFrom, fn (TrafficPriceSet $current): array => [
            'account_price' => TrafficMoney::stored($prices['account_price'], 4),
            'post_bonus' => TrafficMoney::stored($prices['post_bonus'], 4),
            'returned_bonus' => TrafficMoney::stored($prices['returned_bonus'], 4),
            'country_groups' => $current->country_groups,
            'summary' => 'Prices: account '.TrafficMoney::format($prices['account_price']).', first post '.TrafficMoney::format($prices['post_bonus']).', return '.TrafficMoney::format($prices['returned_bonus']),
        ]);
    }

    /**
     * @param  list<array{key: mixed, name: mixed, percent: mixed, countries: mixed}>  $groups  the last holds every country the others leave out
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function groups(User $admin, array $groups, int $drawnFrom): TrafficPriceSet
    {
        $validator = Validator::make(['groups' => $groups], [
            'groups' => ['required', 'array', 'min:1', 'max:'.self::MOST_GROUPS],
            'groups.*.key' => ['required', 'string', 'regex:/^[a-z0-9_]{1,24}$/', 'distinct'],
            'groups.*.name' => ['required', 'string', 'max:40'],
            'groups.*.percent' => ['required', 'integer', 'min:0', 'max:200'],
            'groups.*.countries' => ['array'],
            'groups.*.countries.*' => ['string', Rule::in(array_keys(Countries::all()))],
        ]);
        $validator->after(function ($validator) use ($groups): void {
            $seen = [];

            foreach (array_slice($groups, 0, -1) as $index => $group) {
                if (((array) ($group['countries'] ?? [])) === []) {
                    $validator->errors()->add("groups.{$index}.countries", 'Name the countries of this group. Only the last group holds the rest.');
                }

                foreach ((array) ($group['countries'] ?? []) as $country) {
                    if (isset($seen[$country])) {
                        $validator->errors()->add("groups.{$index}.countries", Countries::name((string) $country).' is already in another group.');
                    }

                    $seen[$country] = true;
                }
            }
        });
        $validator->validate();

        $clean = array_map(fn (array $group, int $index): array => [
            'key' => (string) $group['key'],
            'name' => trim((string) $group['name']),
            'percent' => (int) $group['percent'],
            // The last group holds every country the others leave out.
            'countries' => $index === count($groups) - 1 ? [] : array_values(array_unique(array_map('strval', (array) $group['countries']))),
        ], $groups, array_keys($groups));

        return $this->save($admin, $drawnFrom, fn (TrafficPriceSet $current): array => [
            'account_price' => $current->account_price,
            'post_bonus' => $current->post_bonus,
            'returned_bonus' => $current->returned_bonus,
            'country_groups' => $clean,
            'summary' => 'Country groups: '.implode(', ', array_map(fn (array $group): string => $group['name'].' '.$group['percent'].'%', $clean)),
        ]);
    }

    /** @param  callable(TrafficPriceSet): array<string, mixed>  $fields */
    private function save(User $admin, int $drawnFrom, callable $fields): TrafficPriceSet
    {
        return DB::transaction(function () use ($admin, $drawnFrom, $fields): TrafficPriceSet {
            $locked = $this->lockAdministrator($admin);
            $current = TrafficPriceSet::query()->orderByDesc('id')->lockForUpdate()->firstOrFail();

            if ($current->id !== $drawnFrom) {
                throw CannotManageTrafficException::becauseItChanged('the prices');
            }

            return TrafficPriceSet::query()->create([...$fields($current), 'created_by_user_id' => $locked->id, 'created_at' => now()]);
        });
    }
}
