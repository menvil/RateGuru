<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Models\AnalyticsGameDay;
use App\Models\AnalyticsVisit;
use App\Queries\Traffic\TrafficReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the sums of a game's days are written from (RefreshAnalyticsGameDaysAction,
 * docs/architecture/analytics.md, "The sums of a game's days"): a network
 * day's rows of games' visits that arrived on one UTC day, by every key a
 * game's figures read, each sum the very SQL TrafficReportQuery reads a
 * game's visits with (TrafficReportQuery::gameDaySums()); and those summed
 * again without the country. Portable SQL, every value bound.
 */
final class AnalyticsGameDaysQuery implements RawSqlPersistenceBoundary
{
    /** The keys a row of the sums is one set of, but for the level, the days and the moment of an unconfirmed sign-up. */
    public const array KEYS = ['game_id', 'channel', 'source_id', 'campaign_id', 'version_id', 'device', 'country', 'signed_in'];

    /** The sums, in the order TrafficReportQuery::gameDaySums() selects them. */
    public const array SUMS = [
        'visits', 'opened', 'started', 'completed', 'goal', 'accounts', 'posted', 'returned', 'known_opened',
        'spend', 'spend_estimated', 'known_spend', 'value_real', 'value_accounts', 'value_expected',
        'signed_up', 'confirmed', 'confirmed_10_minutes', 'confirmed_hour', 'confirmed_day',
    ];

    /** A sign-up not confirmed, as the moment it signed up: a key, so the moment its window closes can be read later. */
    private const string UNCONFIRMED = 'CASE WHEN signed_up AND NOT account THEN signup_at END';

    /** @return list<string> the columns byCountry() selects, in order */
    public static function columns(): array
    {
        return ['level', 'network_day', 'arrival_day', ...self::KEYS, 'unconfirmed_at', 'first_at', ...self::SUMS];
    }

    /** @return list<string> the columns everyCountry() selects, in order */
    public static function everyCountryColumns(): array
    {
        return array_values(array_diff(self::columns(), ['country']));
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} the first and the last arrival of a network day's games' visits */
    public function arrivals(string $networkDay): array
    {
        $row = (array) AnalyticsVisit::query()
            ->where('network_day', $networkDay)
            ->whereNotNull('game_id')
            ->selectRaw('MIN(arrived_at) AS first_at, MAX(arrived_at) AS last_at', [])
            ->toBase()
            ->first();

        return [
            ($row['first_at'] ?? null) === null ? null : CarbonImmutable::parse((string) $row['first_at'], 'UTC'),
            ($row['last_at'] ?? null) === null ? null : CarbonImmutable::parse((string) $row['last_at'], 'UTC'),
        ];
    }

    /** @return Builder<AnalyticsVisit> the sums of the network day's games' visits that arrived on the UTC day starting at the moment given, by country (columns()) */
    public function byCountry(string $networkDay, CarbonImmutable $arrivalDay): Builder
    {
        [$sums, $bindings] = TrafficReportQuery::gameDaySums();

        return AnalyticsVisit::query()
            ->where('network_day', $networkDay)
            ->whereNotNull('game_id')
            ->where('arrived_at', '>=', $arrivalDay)
            ->where('arrived_at', '<', $arrivalDay->addDay())
            ->selectRaw(
                '? AS level, ? AS network_day, ? AS arrival_day, '.implode(', ', self::KEYS).', '.self::UNCONFIRMED.' AS unconfirmed_at, MIN(arrived_at) AS first_at, '.$sums,
                [AnalyticsGameDay::BY_COUNTRY, $networkDay, $arrivalDay->toDateString(), ...$bindings],
            )
            ->groupBy(...self::KEYS)
            ->groupByRaw(self::UNCONFIRMED, []);
    }

    /** @return Builder<AnalyticsGameDay> the network day's sums by country summed again with every country together (everyCountryColumns()) */
    public function everyCountry(string $networkDay): Builder
    {
        $keys = array_values(array_diff(self::KEYS, ['country']));
        $sums = array_map(fn (string $sum): string => 'SUM('.$sum.') AS '.$sum, self::SUMS);

        return AnalyticsGameDay::query()
            ->where('network_day', $networkDay)
            ->where('level', AnalyticsGameDay::BY_COUNTRY)
            ->selectRaw(
                '? AS level, network_day, arrival_day, '.implode(', ', $keys).', unconfirmed_at, MIN(first_at) AS first_at, '.implode(', ', $sums),
                [AnalyticsGameDay::ALL],
            )
            ->groupBy('network_day', 'arrival_day', ...$keys)
            ->groupBy('unconfirmed_at');
    }
}
