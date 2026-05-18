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
