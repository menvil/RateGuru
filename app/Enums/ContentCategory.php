<?php

namespace App\Enums;

/**
 * The categories a classifier's scores are kept in, whatever its provider
 * calls them: the four that make a picture NSFW, and the near-misses that do
 * not but that general models often mistake for it — kept for tuning the
 * line, and for the finer decisions to come.
 */
enum ContentCategory: string
{
    case BreastExposed = 'breast_exposed';
    case GenitalsExposed = 'genitals_exposed';
    case SexualActivity = 'sexual_activity';
    case SexToy = 'sex_toy';
    case ButtocksExposed = 'buttocks_exposed';
    case Underwear = 'underwear';
    case Swimwear = 'swimwear';
    case SeeThrough = 'see_through';

    /** How the admin names it. */
    public function label(): string
    {
        return match ($this) {
            self::BreastExposed => 'Breast',
            self::GenitalsExposed => 'Genitals',
            self::SexualActivity => 'Sexual activity',
            self::SexToy => 'Sex toy',
            self::ButtocksExposed => 'Buttocks',
            self::Underwear => 'Underwear',
            self::Swimwear => 'Swimwear',
            self::SeeThrough => 'See-through',
        };
    }

    /** Whether the category makes a picture NSFW by the project's line. */
    public function isNsfw(): bool
    {
        return in_array($this, [self::BreastExposed, self::GenitalsExposed, self::SexualActivity, self::SexToy], true);
    }
}
