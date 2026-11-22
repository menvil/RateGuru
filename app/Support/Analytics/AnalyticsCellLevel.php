<?php

namespace App\Support\Analytics;

use InvalidArgumentException;

/**
 * A level of detail Analytics keeps each day's people at (analytics_cells):
 * the visit columns a level's cells are split by, every one by device. A read
 * takes the coarsest level that holds every column it filters or groups by —
 * the fewer the cells, the quicker the union of a period's — and none when no
 * level holds them all: then it reads the visits. Pure.
 */
enum AnalyticsCellLevel: string
{
    case Device = 'device';
    case LandingDevice = 'landing_device';
    case TagsDevice = 'tags_device';
    case CountryDevice = 'country_device';
    case CityCountryDevice = 'city_country_device';
    case SourceDevice = 'source_device';
    case SourceCampaignDevice = 'source_campaign_device';
    case CreativeDevice = 'creative_device';
    case CreativeGameDevice = 'creative_game_device';

    /** The columns every cell keeps, whatever its level: device first, then those a level may leave out (GROUPING_COLUMNS). */
    public const array KEY_COLUMNS = ['device', ...self::GROUPING_COLUMNS];

    /**
     * The columns a level may leave out, in the order GROUPING() reads them:
     * the bit of the first is the highest.
     */
    public const array GROUPING_COLUMNS = [
        'source_key', 'campaign_key', 'country', 'landing_key', 'creative_key', 'game_id', 'city',
        'utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_term',
    ];

    /** @return list<string> the visit columns a cell of the level is split by */
    public function columns(): array
    {
        return match ($this) {
            self::Device => ['device'],
            self::LandingDevice => ['landing_key', 'device'],
            self::TagsDevice => ['utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_term', 'device'],
            self::CountryDevice => ['country', 'device'],
            self::CityCountryDevice => ['city', 'country', 'device'],
            self::SourceDevice => ['source_key', 'device'],
            self::SourceCampaignDevice => ['source_key', 'campaign_key', 'device'],
            self::CreativeDevice => ['source_key', 'campaign_key', 'creative_key', 'device'],
            self::CreativeGameDevice => ['source_key', 'campaign_key', 'creative_key', 'game_id', 'device'],
        };
    }

    /**
     * The bits GROUPING(GROUPING_COLUMNS) gives a cell of the level: a bit
     * set is a column the cell is not split by.
     */
    public function groupingBits(): int
    {
        $bits = 0;
        $last = count(self::GROUPING_COLUMNS) - 1;

        foreach (self::GROUPING_COLUMNS as $position => $column) {
            if (! in_array($column, $this->columns(), true)) {
                $bits |= 1 << ($last - $position);
            }
        }

        return $bits;
    }

    /** The level a cell of a GROUPING SETS read belongs to, by the bits of GROUPING(GROUPING_COLUMNS) (groupingBits()). */
    public static function ofGrouping(int $bits): self
    {
        foreach (self::cases() as $level) {
            if ($level->groupingBits() === $bits) {
                return $level;
            }
        }

        throw new InvalidArgumentException("No level of the cells is split by the columns of GROUPING bits [{$bits}].");
    }

    /**
     * The coarsest level holding every column given, from the fewest cells
     * a day to the most; none when no level does.
     *
     * @param  list<string>  $columns
     */
    public static function covering(array $columns): ?self
    {
        $fewestFirst = [
            self::Device, self::LandingDevice, self::TagsDevice, self::CountryDevice, self::CityCountryDevice,
            self::SourceDevice, self::SourceCampaignDevice, self::CreativeDevice, self::CreativeGameDevice,
        ];

        foreach ($fewestFirst as $level) {
            if (array_diff($columns, $level->columns()) === []) {
                return $level;
            }
        }

        return null;
    }
}
