<?php

namespace App\Enums;

enum CuisineType: string
{
    case Italian = 'italian';
    case Asian = 'asian';
    case American = 'american';
    case Mexican = 'mexican';
    case Other = 'other';
    case Unknown = 'unknown';

    /**
     * Phase 43 exposes neutral labels while these legacy enum values remain.
     * TODO Phase 44: replace this mapping with configurable rating options.
     */
    public function label(): string
    {
        return match ($this) {
            self::Italian => 'Category A',
            self::Asian => 'Category B',
            self::American => 'Category C',
            self::Mexican => 'Category D',
            self::Other => 'Other',
            self::Unknown => 'Unknown',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Italian => 'A',
            self::Asian => 'B',
            self::American => 'C',
            self::Mexican => 'D',
            self::Other => 'OT',
            self::Unknown => 'UN',
        };
    }

    /**
     * Cuisines a user is allowed to vote for. Unknown is excluded — it is
     * only valid for posts.cuisine_truth, not for user votes.
     *
     * @return list<self>
     */
    public static function votable(): array
    {
        return [
            self::Italian,
            self::Asian,
            self::American,
            self::Mexican,
            self::Other,
        ];
    }
}
