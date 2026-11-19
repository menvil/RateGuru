<?php

namespace App\Support\Traffic\Worth;

/**
 * The country groups of a set of prices: each names its countries, and the
 * last holds every country the others leave out. An account is worth its
 * group's share of the price.
 */
final class CountryGroups
{
    /**
     * @param  list<array{key: string, name: string, percent: int, countries: list<string>}>  $groups
     * @return array{key: string, percent: int}
     */
    public static function of(array $groups, ?string $country): array
    {
        foreach ($groups as $group) {
            if ($country !== null && in_array($country, $group['countries'], true)) {
                return ['key' => $group['key'], 'percent' => (int) $group['percent']];
            }
        }

        $last = $groups[array_key_last($groups)] ?? ['key' => 'rest', 'percent' => 100];

        return ['key' => $last['key'], 'percent' => (int) $last['percent']];
    }
}
