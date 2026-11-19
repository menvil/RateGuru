<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\AnalyticsDimension;
use App\Enums\GeoEdition;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\TrafficSetting;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Services\Geo\GeoAttribution;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\VisitorFigures;
use App\Support\Geo\Countries;

/**
 * Analytics → Geography, as the reference draws it: where the people of the
 * filter were as they came — the countries the most people came from, each
 * with its share of everyone, its start rate and the rates of new visitors
 * making an account and activating one, "—" below the denominator a row
 * needs — then the people of every other country together, and those no IP
 * database could place. One country is open at a time, the one the most
 * people came from unless another is chosen: its cities with enough people
 * to name, each a share of everyone too, and the rest of the country
 * together, so no single person can be picked out. Sorted by the people, or
 * by either rate of new visitors; every filter of the page applies.
 *
 * Above it, how a place is known — from the IP address at each visit,
 * approximately, the address itself never kept — with the credit the IP
 * database's licence asks for (GeoAttribution); and, while no database
 * serves, that visits get no place at all until one is fetched. Nobody
 * matching the filters is said once, with no table drawn. Reads only.
 */
final class GeographyTab implements AnalyticsTab
{
    /** How many countries the table lists, the most people first; every other country is counted together after them. */
    public const int COUNTRIES = 10;

    /** How many cities of the open country the table lists at most, the most people first; the rest of the country is counted together after them. */
    public const int CITIES = 25;

    /** @var array<string, string> how the countries and cities can be sorted */
    public const array SORTS = ['visitors' => 'Visitors', 'registration' => 'Registration rate', 'activation' => 'Activation rate'];

    /** The open country's value while none is open; an empty one opens the country the most people came from. */
    public const string NONE_OPEN = 'none';

    public const string FOOT = 'Start rate uses visitors; registration and activation use new visitors. Click a country to see its cities. A visitor seen in two places counts in each, and a rate shows “—” until the number it divides by reaches %d.';

    public function __construct(
        private readonly AnalyticsVisitsQuery $visits,
        private readonly AnalyticsView $view,
        private readonly GeoAttribution $geo,
    ) {}

    /** The open country as the address may name it: a country's code, none at all, or — anything else — the one the most people came from. */
    public static function readOpen(mixed $value): string
    {
        return match (true) {
            $value === self::NONE_OPEN => self::NONE_OPEN,
            is_string($value) && preg_match('/^[A-Z]{2}$/', $value) === 1 && Countries::has($value) => $value,
            default => '',
        };
    }

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $place = $this->place();
        $everyone = $this->visits->figures($filter);

        if ($everyone->isEmpty()) {
            return ['empty' => true, 'range' => $filter->period->range(), 'filtered' => $filter->device !== null || $filter->narrowsSource(), 'place' => $place];
        }

        $sort = array_key_exists((string) $page->gsort, self::SORTS) ? (string) $page->gsort : 'visitors';
        $minimum = (int) config('analytics.row_min_denominator');
        $countries = $this->visits->by($filter, AnalyticsDimension::Country, self::COUNTRIES, unnamed: AnalyticsVisitsQuery::UNNAMED_APART);
        $listed = [];

        foreach ($countries['rows'] as $row) {
            $code = (string) $row['keys']['country'];
            $listed[$code] = ['code' => $code, 'name' => Countries::name($code), 'figures' => $row['figures']];
        }

        // The country the most people came from opens unless another of those listed is chosen, or none.
        $asked = self::readOpen($page->open);
        $open = match (true) {
            $asked === self::NONE_OPEN => null,
            array_key_exists($asked, $listed) => $asked,
            default => array_key_first($listed),
        };
        // Every bar is measured against the row of the most people, as long as the track.
        $widest = max(1, ...array_map(fn (array $group): int => $group['figures']->visitors, [
            ...array_values($listed),
            ...array_filter([$countries['rest'], $countries['unnamed']]),
        ]));
        $line = fn (string $kind, string $name, VisitorFigures $figures, array $more = []): array => [
            'kind' => $kind,
            'name' => $name,
            'flag' => null,
            'open' => false,
            'toggle' => null,
            ...self::figures($figures, $everyone->visitors, $widest, $minimum),
            ...$more,
        ];
        $rows = [];

        foreach (self::sorted(array_values($listed), $sort, $minimum) as $country) {
            $isOpen = $country['code'] === $open;
            $rows[] = $line('country', $country['name'], $country['figures'], [
                'key' => $country['code'],
                'flag' => Countries::flag($country['code']),
                'open' => $isOpen,
                'toggle' => $isOpen ? self::NONE_OPEN : $country['code'],
            ]);

            if ($isOpen) {
                foreach ($this->cities($filter, $country['code'], $country['figures'], $sort, $minimum) as [$kind, $name, $figures]) {
                    $rows[] = $line($kind, $name, $figures, ['key' => $country['code'].':'.$name]);
                }
            }
        }

        if ($countries['rest'] !== null) {
            $rows[] = $line('other', 'Other countries', $countries['rest']['figures'], ['key' => 'other', 'flag' => Countries::GLOBE]);
        }

        if ($countries['unnamed'] !== null) {
            $rows[] = $line('unknown', 'Location unknown', $countries['unnamed']['figures'], ['key' => 'unknown', 'flag' => '—']);
        }

        return [
            'empty' => false,
            'place' => $place,
            'note' => $this->note($filter, $everyone->visitors),
            'sort' => $sort,
            'rows' => $rows,
            'foot' => sprintf(self::FOOT, $minimum),
            'journeys' => GrowthAnalyticsPage::getUrl(['tab' => 'journeys']),
        ];
    }

    /**
     * The open country's cities with enough people to name, sorted as the
     * table is, then everyone else of the country together — those of the
     * smaller cities, of cities past the first ones, and of none known. A
     * country with fewer people than a city needs is all the rest.
     *
     * @return list<array{0: string, 1: string, 2: VisitorFigures}>
     */
    private function cities(AnalyticsFilter $filter, string $code, VisitorFigures $country, string $sort, int $minimum): array
    {
        $least = (int) config('analytics.city_min_visitors');

        if ($country->visitors < $least) {
            return [['others', 'Other cities', $country]];
        }

        $cities = $this->visits->by($filter->within(AnalyticsDimension::Country, ['country' => $code]), AnalyticsDimension::City, self::CITIES, $least, AnalyticsVisitsQuery::UNNAMED_IN_REST);
        $named = array_map(fn (array $row): array => ['name' => (string) $row['keys']['city'], 'figures' => $row['figures']], $cities['rows']);
        $lines = array_map(fn (array $city): array => ['city', $city['name'], $city['figures']], self::sorted($named, $sort, $minimum));

        if ($cities['rest'] !== null) {
            $lines[] = ['others', 'Other cities', $cities['rest']['figures']];
        }

        return $lines;
    }

    /**
     * Places sorted as asked: by their people, or by a rate of new visitors
     * — those whose rate can't be shown after those whose can — the most
     * people first among equals, then by name.
     *
     * @template TPlace of array{name: string, figures: VisitorFigures}
     *
     * @param  list<TPlace>  $places
     * @return list<TPlace>
     */
    private static function sorted(array $places, string $sort, int $minimum): array
    {
        $rate = fn (VisitorFigures $figures): ?float => match ($sort) {
            'registration' => $figures->accountRate($minimum),
            'activation' => $figures->activationRate($minimum),
            default => null,
        };

        usort($places, fn (array $a, array $b): int => [$rate($b['figures']) !== null, $rate($b['figures']), $b['figures']->visitors, $a['name']]
            <=> [$rate($a['figures']) !== null, $rate($a['figures']), $a['figures']->visitors, $b['name']]);

        return $places;
    }

    /**
     * A row's figures as the table shows them: its people, their share of
     * everyone with the bar's length against the widest row, and the start,
     * registration and activation rates, each with how many of how many it
     * divides, or "—" where it can't be shown.
     *
     * @return array{visitors: string, share: string, bar: int, rates: list<array{value: string, title: string|null, quiet: bool}>}
     */
    private static function figures(VisitorFigures $figures, int $everyone, int $widest, int $minimum): array
    {
        return [
            'visitors' => number_format($figures->visitors),
            'share' => number_format($figures->visitors / max(1, $everyone) * 100, 1).'%',
            'bar' => (int) round($figures->visitors / max(1, $widest) * 100),
            'rates' => [
                self::rate($figures->starters, $figures->visitors, $figures->startRate($minimum)),
                self::rate($figures->accounts, $figures->newVisitors, $figures->accountRate($minimum)),
                self::rate($figures->activated, $figures->newVisitors, $figures->activationRate($minimum)),
            ],
        ];
    }

    /** @return array{value: string, title: string|null, quiet: bool} */
    private static function rate(int $count, int $of, ?float $rate): array
    {
        return $rate === null
            ? ['value' => '—', 'title' => null, 'quiet' => true]
            : ['value' => number_format($rate * 100, 1).'%', 'title' => number_format($count).' of '.number_format($of), 'quiet' => false];
    }

    /** What the card says it counts: the days, each narrowing chosen, and how many people in all. */
    private function note(AnalyticsFilter $filter, int $everyone): string
    {
        $period = $filter->period;

        return implode(' · ', array_filter([
            $period->isCustom() ? $period->range() : $period->label(),
            $filter->device?->label(),
            $filter->sourceKey === null ? null : AnalyticsSource::label($filter->sourceKey, $this->view->sourceNames([$filter->sourceKey])),
            $filter->campaignKey === null ? null : CampaignKey::label($filter->campaignKey, $this->view->campaignNames([$filter->campaignKey])),
            number_format($everyone).' unique '.str('visitor')->plural($everyone),
        ]));
    }

    /**
     * How a place is known, as the tab says above its table: whether an IP
     * database serves, the credit its licence asks for, and the way to the
     * setting that fetches one.
     *
     * @return array{serving: bool, credit: array{text: string, url: string|null}, least: int, tracking: string}
     */
    private function place(): array
    {
        $geo = $this->geo->read(TrafficSetting::current()->geo_edition ?? GeoEdition::GeoLite2City);

        return [
            'serving' => $geo['serving'] !== null,
            'credit' => $geo['credit'],
            'least' => (int) config('analytics.city_min_visitors'),
            'tracking' => TrafficSourcesPage::getUrl(['tab' => 'sources']),
        ];
    }
}
