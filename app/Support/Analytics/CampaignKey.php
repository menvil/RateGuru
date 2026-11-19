<?php

namespace App\Support\Analytics;

/**
 * The campaign a session came from, as one key written on each of its visits
 * (`traffic_visits.campaign_key`): `paid:{campaign_id}` for a paid source's
 * campaign, `tag:{utm_campaign}` for a campaign named by its tag — and the
 * creative under it, the visit's `creative_key`, or none. How each reads on
 * screen comes from here too. Pure; every key fits the column's 140
 * characters.
 */
final class CampaignKey
{
    public const int MAX_LENGTH = 140;

    /** What a campaign's visits that came by no creative are listed as. */
    public const string NO_CREATIVE = '(no creative)';

    public static function paid(?int $campaignId): ?string
    {
        return $campaignId === null ? null : 'paid:'.$campaignId;
    }

    public static function tag(?string $utmCampaign): ?string
    {
        $campaign = $utmCampaign === null ? '' : trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $utmCampaign));

        return $campaign === '' ? null : mb_substr('tag:'.$campaign, 0, self::MAX_LENGTH);
    }

    /** A creative as a campaign lists it: its key, or none. */
    public static function creative(?string $creativeKey): string
    {
        return $creativeKey === null || trim($creativeKey) === '' ? self::NO_CREATIVE : $creativeKey;
    }

    /**
     * How a key reads on screen: the paid campaign's name (from the names
     * given, by id), or the tag itself.
     *
     * @param  array<int, string>  $campaignNames
     */
    public static function label(string $key, array $campaignNames = []): string
    {
        $paid = self::paidCampaignId($key);

        if ($paid !== null) {
            return $campaignNames[$paid] ?? 'Campaign #'.$paid;
        }

        return str_starts_with($key, 'tag:') ? substr($key, 4) : $key;
    }

    /** The paid campaign a key names; null for a tag. */
    public static function paidCampaignId(string $key): ?int
    {
        return preg_match('/^paid:(\d+)$/', $key, $match) === 1 ? (int) $match[1] : null;
    }

    /** A key as an address names it, when it is one a visit could carry; null for anything else. */
    public static function read(mixed $value): ?string
    {
        if (! is_string($value) || mb_strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return null;
        }

        return self::paidCampaignId($value) !== null || (str_starts_with($value, 'tag:') && trim(substr($value, 4)) !== '') ? $value : null;
    }
}
