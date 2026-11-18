<?php

namespace App\Support\Analytics;

/**
 * The campaign a session came from, as one key written on each of its visits
 * (`traffic_visits.campaign_key`): `paid:{campaign_id}` for a paid source's
 * campaign, `tag:{utm_campaign}` for a campaign named by its tag. Pure; every
 * key fits the column's 140 characters.
 */
final class CampaignKey
{
    public const int MAX_LENGTH = 140;

    public static function paid(?int $campaignId): ?string
    {
        return $campaignId === null ? null : 'paid:'.$campaignId;
    }

    public static function tag(?string $utmCampaign): ?string
    {
        $campaign = $utmCampaign === null ? '' : trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $utmCampaign));

        return $campaign === '' ? null : mb_substr('tag:'.$campaign, 0, self::MAX_LENGTH);
    }
}
