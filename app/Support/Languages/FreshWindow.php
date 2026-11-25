<?php

namespace App\Support\Languages;

use Carbon\CarbonImmutable;

/**
 * Since when a post in the viewer's language counts as fresh, and so goes first in their feed: for a member, since
 * their previous visit — what came out while they were away — but never less than a day nor more than the window
 * set in Project settings; for a guest, the guests' window.
 */
final class FreshWindow
{
    public static function forMember(?CarbonImmutable $previousVisit, LanguageRankingSettings $settings, CarbonImmutable $now): CarbonImmutable
    {
        $longest = $now->subDays($settings->memberWindowDays);
        $shortest = $now->subDays(LanguageRankingSettings::SHORTEST_WINDOW_DAYS);

        if ($previousVisit === null || $previousVisit->lessThan($longest)) {
            return $longest;
        }

        return $previousVisit->greaterThan($shortest) ? $shortest : $previousVisit;
    }

    public static function forGuest(LanguageRankingSettings $settings, CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays($settings->guestWindowDays);
    }
}
