<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficSourceType;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\User;
use App\Support\Geo\Countries;
use App\Support\Traffic\Money\TrafficMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sets what an account is worth to the site — the account, its first post,
 * its return on another day — and what a sign-in to an account the visitor
 * already had is worth, with the account price of each paid source that has
 * one of its own; or the country groups and the share of those prices each
 * is worth. Either way a new set of prices, carrying over whatever the save
 * leaves alone: a set is never changed, every visit keeps the set it arrived
 * under, and postbacks already sent keep what they said. The editor names the
 * set it was drawn from, and a set saved since refuses the change.
 */
final class SaveTrafficPricesAction
{
    use LocksTrafficRecords;

    public const int MOST_GROUPS = 8;

    /**
     * @param  array{account_price: mixed, post_bonus: mixed, returned_bonus: mixed, login_price: mixed}  $prices
     * @param  array<int|string, mixed>  $sourcePrices  the account price typed for a paid source, by source id: blank gives it the site price again, and a source not named keeps what it had
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function prices(User $admin, array $prices, int $drawnFrom, array $sourcePrices = []): TrafficPriceSet
    {
        $typed = [];

        foreach ($sourcePrices as $sourceId => $price) {
            $price = is_string($price) ? trim($price) : $price;
            $typed[(string) $sourceId] = $price === '' ? null : $price;
        }

        $sources = TrafficSource::query()->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])
            ->whereIn('id', array_map('intval', array_filter(array_map('strval', array_keys($typed)), 'ctype_digit')))
            ->pluck('name', 'id');
        $money = ['numeric', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'min:0', 'max:1000'];
        $rules = ['account_price' => ['required', ...$money], 'post_bonus' => ['required', ...$money], 'returned_bonus' => ['required', ...$money], 'login_price' => ['required', ...$money]];
        $names = ['account_price' => 'price of an account', 'post_bonus' => 'first post bonus', 'returned_bonus' => 'return bonus', 'login_price' => 'price of a sign-in'];

        foreach (array_keys($typed) as $sourceId) {
            $rules['source_account_prices.'.$sourceId] = ['nullable', ...$money];
            $names['source_account_prices.'.$sourceId] = 'account price of '.($sources[(int) $sourceId] ?? 'the source');
        }

        $validator = Validator::make([...$prices, 'source_account_prices' => $typed], $rules, [], $names);
        $validator->after(function ($validator) use ($typed, $sources): void {
            foreach (array_keys($typed) as $sourceId) {
                if (! ctype_digit((string) $sourceId) || ! $sources->has((int) $sourceId)) {
                    $validator->errors()->add('source_account_prices.'.$sourceId, 'Only a paid source has an account price of its own.');
                }
            }
        });
        $validator->validate();

        return $this->save($admin, $drawnFrom, function (TrafficPriceSet $current) use ($prices, $typed, $sources): array {
            $was = $current->sourceAccountPrices();
            $now = $was;

            foreach ($typed as $sourceId => $price) {
                if ($price === null) {
                    unset($now[(int) $sourceId]);
                } else {
                    $now[(int) $sourceId] = TrafficMoney::stored($price, 4);
                }
            }

            ksort($now);

            return [
                'account_price' => TrafficMoney::stored($prices['account_price'], 4),
                'post_bonus' => TrafficMoney::stored($prices['post_bonus'], 4),
                'returned_bonus' => TrafficMoney::stored($prices['returned_bonus'], 4),
                'login_price' => TrafficMoney::stored($prices['login_price'], 4),
                'country_groups' => $current->country_groups,
                'source_account_prices' => $now === [] ? null : $now,
                'summary' => Str::limit(implode('; ', array_filter([
                    'Prices: account '.TrafficMoney::format($prices['account_price']).', first post '.TrafficMoney::format($prices['post_bonus']).', return '.TrafficMoney::format($prices['returned_bonus']).', existing account signed in '.TrafficMoney::format($prices['login_price']),
                    self::sourceChanges($was, $now, $sources->all()),
                ])), 299, '…'),
            ];
        });
    }

    /**
     * The account prices of sources a save changed, by the sources' names:
     * “ExoClick account $0.80, PopAds account at the site price”.
     *
     * @param  array<int, string>  $was
     * @param  array<int, string>  $now
     * @param  array<int|string, mixed>  $names
     */
    private static function sourceChanges(array $was, array $now, array $names): string
    {
        $changes = [];

        foreach ($now as $sourceId => $price) {
            if (! isset($was[$sourceId]) || ! TrafficMoney::of($was[$sourceId])->isEqualTo(TrafficMoney::of($price))) {
                $changes[] = ($names[$sourceId] ?? 'Source #'.$sourceId).' account '.TrafficMoney::format($price);
            }
        }

        foreach (array_diff_key($was, $now) as $sourceId => $price) {
            $changes[] = ($names[$sourceId] ?? 'Source #'.$sourceId).' account at the site price';
        }

        return implode(', ', $changes);
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
            'login_price' => $current->login_price,
            'country_groups' => $clean,
            'source_account_prices' => $current->source_account_prices,
            'summary' => Str::limit('Country groups: '.implode(', ', array_map(fn (array $group): string => $group['name'].' '.$group['percent'].'%', $clean)), 299, '…'),
        ]);
    }

    /** @param  callable(TrafficPriceSet): array<string, mixed>  $fields */
    private function save(User $admin, int $drawnFrom, callable $fields): TrafficPriceSet
    {
        return DB::transaction(function () use ($admin, $drawnFrom, $fields): TrafficPriceSet {
            $locked = $this->lockAdministrator($admin);
            // Two administrators' saves queue up on the oldest set, which is never removed; only then is the newest set read, in a
            // statement of its own, so a save that waited sees the set the other one added rather than the one it waited on.
            TrafficPriceSet::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $current = TrafficPriceSet::query()->orderByDesc('id')->lockForUpdate()->firstOrFail();

            if ($current->id !== $drawnFrom) {
                throw CannotManageTrafficException::becauseItChanged('the prices');
            }

            return TrafficPriceSet::query()->create([...$fields($current), 'created_by_user_id' => $locked->id, 'created_at' => now()]);
        });
    }
}
