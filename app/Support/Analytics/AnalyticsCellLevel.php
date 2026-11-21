<?php

namespace App\Support\Analytics;

use InvalidArgumentException;

/**
 * A level of detail Analytics keeps each day's people at (analytics_cells):
 * the visit columns a level's cells are split by. A read takes the coarsest
 * level that holds every column it filters or groups by — the fewer the
 * cells, the quicker the union of a period's — and none when no level holds
 * them all: then it reads the visits. Pure.
 */
enum AnalyticsCellLevel: string
{
    case Device = 'device';
    case SourceDevice = 'source_device';
    case SourceCampaignDevice = 'source_campaign_device';
    case CountryDevice = 'country_device';

    /** The columns every cell keeps, whatever its level. */
    public const array KEY_COLUMNS = ['device', 'source_key', 'campaign_key', 'country'];

    /** @return list<string> the visit columns a cell of the level is split by */
    public function columns(): array
    {
        return match ($this) {
            self::Device => ['device'],
            self::SourceDevice => ['source_key', 'device'],
            self::SourceCampaignDevice => ['source_key', 'campaign_key', 'device'],
            self::CountryDevice => ['country', 'device'],
        };
    }

    /**
     * The level a cell of a GROUPING SETS read belongs to, by the bits of
     * GROUPING(source_key, campaign_key, country): a bit set is a column the
     * cell is not split by.
     */
    public static function ofGrouping(int $bits): self
    {
        return match ($bits) {
            0b111 => self::Device,
            0b011 => self::SourceDevice,
            0b001 => self::SourceCampaignDevice,
            0b110 => self::CountryDevice,
            default => throw new InvalidArgumentException("No level of the cells is split by the columns of GROUPING bits [{$bits}]."),
        };
    }

    /**
     * The coarsest level holding every column given, from the fewest cells
     * a day to the most; none when no level does.
     *
     * @param  list<string>  $columns
     */
    public static function covering(array $columns): ?self
    {
        foreach ([self::Device, self::CountryDevice, self::SourceDevice, self::SourceCampaignDevice] as $level) {
            if (array_diff($columns, $level->columns()) === []) {
                return $level;
            }
        }

        return null;
    }
}
