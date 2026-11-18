<?php

namespace App\Support\Traffic\Blocking;

use App\Support\Traffic\Money\TrafficMoney;

/**
 * The block rule's thresholds (docs/architecture/traffic.md, "Blocking"): the
 * general ones, on the tracker's settings, and a network source's own — the
 * columns of the same names on traffic_sources, any of which it may set in
 * place of the general one, null keeping it. What a source's sites and zones
 * are judged by is the two merged; the target price of an account a site's
 * money waits for is its campaign's, else its source's. Both editors refuse
 * a threshold alike.
 */
final class BlockRule
{
    /** @var list<string> every threshold, by its column */
    public const array KEYS = [
        'block_bot_clicks',
        'block_open_below',
        'block_open_below_average',
        'block_open_floor',
        'block_min_spend',
        'block_min_clicks',
        'block_roi_below',
        'block_grace_days',
        'block_target_multiple',
        'block_start_clicks',
        'block_start_percent',
    ];

    /** @var array<string, int> the thresholds that are amounts, by the decimals their columns keep */
    private const array DECIMALS = ['block_min_spend' => 2, 'block_target_multiple' => 1];

    /**
     * What a threshold may be, in either editor: the general one requires it,
     * a source's may be left empty.
     *
     * @return array<string, list<string>>
     */
    public static function validation(): array
    {
        return [
            'block_bot_clicks' => ['integer', 'min:1', 'max:100000'],
            'block_open_below' => ['integer', 'min:1', 'max:100'],
            'block_open_below_average' => ['integer', 'min:1', 'max:100'],
            // 0 turns the floor off: the two shares above are the line alone.
            'block_open_floor' => ['integer', 'min:0', 'max:100'],
            'block_min_spend' => ['numeric', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'min:0', 'max:10000'],
            'block_min_clicks' => ['integer', 'min:1', 'max:1000000'],
            'block_roi_below' => ['integer', 'min:-100', 'max:0'],
            'block_grace_days' => ['integer', 'min:0', 'max:30'],
            'block_target_multiple' => ['numeric', 'regex:/^\d{1,2}(\.\d)?$/', 'min:0.5', 'max:20'],
            'block_start_clicks' => ['integer', 'min:1', 'max:100000'],
            'block_start_percent' => ['integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The refusals a threshold's own words say better than the general ones:
     * a multiple keeps one decimal place, which "format is invalid" doesn't say.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['block_target_multiple.regex' => 'Use a number from 0.5 to 20 with at most one decimal place, such as 1.5.'];
    }

    /**
     * Each threshold as a refusal names it, and as its field is read out.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'block_bot_clicks' => 'clicks before bots show',
            'block_open_below' => 'opened below, percent',
            'block_open_below_average' => 'opened below, percent of the source’s average',
            'block_open_floor' => 'opened below, percent, whatever the source’s average',
            'block_min_spend' => 'spend before the money is judged',
            'block_min_clicks' => 'clicks before the money is judged',
            'block_roi_below' => 'ROI below, percent',
            'block_grace_days' => 'days after the first click',
            'block_target_multiple' => 'times the target price of an account',
            'block_start_clicks' => 'clicks before a weak start shows',
            'block_start_percent' => 'start below, percent of the source',
        ];
    }

    /**
     * The thresholds a source's sites and zones are judged by: its own where
     * it set one, else the general one.
     *
     * @param  array<string, mixed>  $general  every key, as the settings row holds it
     * @param  array<string, mixed>  $own  a source's, null where it keeps the general one
     * @return array<string, int|string>
     */
    public static function effective(array $general, array $own = []): array
    {
        $rule = [];

        foreach (self::KEYS as $key) {
            $value = self::filled($own[$key] ?? null) ? $own[$key] : ($general[$key] ?? null);
            $rule[$key] = isset(self::DECIMALS[$key]) ? TrafficMoney::stored($value, self::DECIMALS[$key]) : (int) $value;
        }

        return $rule;
    }

    /**
     * The thresholds a source set for itself, in the rule's order.
     *
     * @param  array<string, mixed>  $own
     * @return list<string>
     */
    public static function own(array $own): array
    {
        return array_values(array_filter(self::KEYS, fn (string $key): bool => self::filled($own[$key] ?? null)));
    }

    /**
     * A source's own threshold as it is stored: empty for none, an amount to
     * its column's decimals, else a whole number.
     */
    public static function stored(string $key, mixed $value): int|string|null
    {
        if (! self::filled($value)) {
            return null;
        }

        return isset(self::DECIMALS[$key]) ? TrafficMoney::stored($value, self::DECIMALS[$key]) : (int) $value;
    }

    /**
     * The target price of an account a site's money waits for: its campaign's
     * where it came through one campaign that has one, else its source's;
     * null when neither is set above zero.
     *
     * @return array{amount: string, from: 'campaign'|'source'}|null
     */
    public static function target(mixed $campaign, mixed $source): ?array
    {
        return match (true) {
            self::filled($campaign) && TrafficMoney::of($campaign)->isPositive() => ['amount' => TrafficMoney::stored($campaign), 'from' => 'campaign'],
            self::filled($source) && TrafficMoney::of($source)->isPositive() => ['amount' => TrafficMoney::stored($source), 'from' => 'source'],
            default => null,
        };
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && ! (is_string($value) && trim($value) === '');
    }
}
