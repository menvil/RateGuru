<?php

namespace App\Support\Analytics;

use App\Enums\TrafficChannel;

/**
 * Where a session came from, as one key written on each of its visits
 * (`traffic_visits.source_key`), so a person is counted once per source
 * however many of its hosts they came through:
 *
 * - `paid:{source_id}` — a paid click, by its rg_src source;
 * - `{network}:{class}` — a network the dictionary below knows, by the
 *   referrer's host or the utm_source tag, and the class of its traffic by
 *   utm_medium (paid, organic, email, referral). Tagged and untagged traffic
 *   of one network and class share the key; a paid class is a key of its own.
 *   A tag the dictionary doesn't know is `tag.{utm_source}`, a host it
 *   doesn't know `host.{domain}`;
 * - `shared`, `direct`, `internal` — a shared result, nothing to name the
 *   arrival by, a move inside the site.
 *
 * The label, medium, group and network type each key reads as come from the
 * same dictionary, when it is shown: a key written once never needs
 * rewriting for a better label. A row of a key also says what it was
 * recognised by, from the share of its visits that carried tags. Pure; every
 * key fits the column's 140 characters.
 */
final class AnalyticsSource
{
    public const string SHARED = 'shared';

    public const string DIRECT = 'direct';

    public const string INTERNAL = 'internal';

    public const int MAX_LENGTH = 140;

    private const string PAID = 'paid';

    private const string ORGANIC = 'organic';

    private const string EMAIL = 'email';

    private const string REFERRAL = 'referral';

    /** @var array<string, array{label: string, type: 'search'|'social'|'email'}> */
    private const array NETWORKS = [
        'google' => ['label' => 'Google', 'type' => 'search'],
        'bing' => ['label' => 'Bing', 'type' => 'search'],
        'duckduckgo' => ['label' => 'DuckDuckGo', 'type' => 'search'],
        'yandex' => ['label' => 'Yandex', 'type' => 'search'],
        'reddit' => ['label' => 'Reddit', 'type' => 'social'],
        'facebook' => ['label' => 'Facebook', 'type' => 'social'],
        'instagram' => ['label' => 'Instagram', 'type' => 'social'],
        'x' => ['label' => 'X', 'type' => 'social'],
        'email' => ['label' => 'Email', 'type' => 'email'],
    ];

    /**
     * Hosts of the networks, each with its subdomains (old.reddit.com,
     * l.facebook.com …), and the app ids Android sends as the referrer of a
     * link opened from the network's app. Google and Yandex are known on
     * every country domain of theirs instead (google.co.uk, yandex.com.tr …).
     *
     * @var array<string, string>
     */
    private const array HOSTS = [
        'reddit.com' => 'reddit',
        'com.reddit.frontpage' => 'reddit',
        'facebook.com' => 'facebook',
        'com.facebook.katana' => 'facebook',
        'instagram.com' => 'instagram',
        'com.instagram.android' => 'instagram',
        't.co' => 'x',
        'x.com' => 'x',
        'twitter.com' => 'x',
        'com.twitter.android' => 'x',
        'bing.com' => 'bing',
        'duckduckgo.com' => 'duckduckgo',
        'com.google.android.googlequicksearchbox' => 'google',
        'com.google.android.gm' => 'google',
    ];

    /** @var list<string> */
    private const array COUNTRY_DOMAIN_NETWORKS = ['google', 'yandex'];

    /**
     * utm_source values naming a network; a bare network name given as a
     * host — the search engine an unpaid visit's origin names — reads the
     * same way.
     *
     * @var array<string, string>
     */
    private const array TAGS = [
        'google' => 'google',
        'bing' => 'bing',
        'duckduckgo' => 'duckduckgo',
        'yandex' => 'yandex',
        'reddit' => 'reddit',
        'facebook' => 'facebook',
        'fb' => 'facebook',
        'instagram' => 'instagram',
        'ig' => 'instagram',
        'x' => 'x',
        'twitter' => 'x',
        'email' => 'email',
        'newsletter' => 'email',
    ];

    /** @var list<string> utm_medium values of paid traffic, besides any starting with "paid" */
    private const array PAID_MEDIUMS = ['cpc', 'ppc', 'display', 'cpm'];

    /**
     * Second-level labels under which a country domain is registered: example.co.uk, example.com.au.
     *
     * @var list<string>
     */
    private const array COUNTRY_SECOND_LEVELS = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'or', 'ne', 'go'];

    /**
     * The key of the source an arrival came from.
     *
     * @param  int|null  $sourceId  the paid source, for a paid click
     * @param  string|null  $referrerHost  the referrer's host
     */
    public static function keyFor(TrafficChannel $channel, ?int $sourceId, ?string $referrerHost, ?string $utmSource = null, ?string $utmMedium = null): string
    {
        if ($channel->isPaid()) {
            return $sourceId === null ? self::DIRECT : self::PAID.':'.$sourceId;
        }

        if ($channel === TrafficChannel::Shared) {
            return self::SHARED;
        }

        if ($channel === TrafficChannel::Internal) {
            return self::INTERNAL;
        }

        $tag = self::clean($utmSource);
        $medium = self::clean($utmMedium);
        $host = self::host($referrerHost);
        $class = self::classOf($medium);

        $network = match (true) {
            $tag !== null => self::TAGS[$tag] ?? self::networkOfHost(self::host($tag)) ?? 'tag.'.$tag,
            $host !== null => self::networkOfHost($host) ?? 'host.'.self::registrable($host),
            $class === self::EMAIL => 'email',
            default => null,
        };

        if ($network === null) {
            return self::DIRECT;
        }

        // An email network sends email unless its tags say otherwise.
        if ($network === 'email' && $class === self::ORGANIC) {
            $class = self::EMAIL;
        }

        return mb_substr($network, 0, self::MAX_LENGTH - mb_strlen($class) - 1).':'.$class;
    }

    /**
     * How a key reads on screen: the paid source's name (from the names
     * given, by id), the network's, or the unknown tag or domain itself.
     *
     * @param  array<int, string>  $sourceNames
     */
    public static function label(string $key, array $sourceNames = []): string
    {
        $paid = self::paidSourceId($key);

        if ($paid !== null) {
            return $sourceNames[$paid] ?? 'Paid source #'.$paid;
        }

        return match ($key) {
            self::SHARED => 'Shared results',
            self::DIRECT => 'Direct / Unknown',
            self::INTERNAL => 'Inside the site',
            default => self::networkLabel(...self::split($key)),
        };
    }

    /** The medium a key stands for: paid_search, paid_social, paid_ads, organic_search, organic_social, email, referral or (none). */
    public static function medium(string $key): string
    {
        if (self::paidSourceId($key) !== null) {
            return 'paid_ads';
        }

        if (in_array($key, [self::DIRECT, self::INTERNAL], true)) {
            return '(none)';
        }

        if ($key === self::SHARED) {
            return 'referral';
        }

        [$network, $class] = self::split($key);
        $type = self::NETWORKS[$network]['type'] ?? null;

        return match (true) {
            $class === self::EMAIL, $type === 'email' && $class !== self::PAID && $class !== self::REFERRAL => 'email',
            $class === self::REFERRAL => 'referral',
            $class === self::PAID => $type === 'search' || $type === 'social' ? 'paid_'.$type : 'paid_ads',
            $type === 'search' || $type === 'social' => 'organic_'.$type,
            default => 'referral',
        };
    }

    /** The group a key falls in: Paid, Organic, Email, Referral, Unattributed or Inside the site. */
    public static function group(string $key): string
    {
        if (self::paidSourceId($key) !== null) {
            return 'Paid';
        }

        return match ($key) {
            self::SHARED => 'Referral',
            self::DIRECT => 'Unattributed',
            self::INTERNAL => 'Inside the site',
            default => match (self::medium($key)) {
                'paid_search', 'paid_social', 'paid_ads' => 'Paid',
                'email' => 'Email',
                default => self::split($key)[1] === self::REFERRAL ? 'Referral' : 'Organic',
            },
        };
    }

    /** The type of a known network a key names — search, social or email — or none for any other key. */
    public static function networkType(string $key): ?string
    {
        if (self::paidSourceId($key) !== null || in_array($key, [self::SHARED, self::DIRECT, self::INTERNAL], true)) {
            return null;
        }

        return self::NETWORKS[self::split($key)[0]]['type'] ?? null;
    }

    /**
     * How a row of the key was recognised: a paid click by its rg_src link,
     * the fixed keys by what defines them, a network by the share of its
     * visits that carried tags — “Tagged”, “Referrer”, or both as
     * “62% tagged · 38% referrer”. A visit is tagged when its session came
     * with utm_source or utm_medium; otherwise its referrer named the network.
     */
    public static function basis(string $key, int $visits, int $tagged): string
    {
        if (self::paidSourceId($key) !== null) {
            return 'rg_src link · click ID';
        }

        return match ($key) {
            self::SHARED => 'Shared result link',
            self::DIRECT => 'No tags or referrer',
            self::INTERNAL => 'Another page of the site',
            default => self::share($visits, $tagged),
        };
    }

    /**
     * What the row of every source past the top ones is called: other
     * referrals, or other sources once a tagged visit is among them.
     */
    public static function otherLabel(int $tagged): string
    {
        return $tagged > 0 ? 'Other sources' : 'Other referrals';
    }

    /**
     * The basis of a row by the share of its visits that carried tags.
     */
    public static function share(int $visits, int $tagged): string
    {
        $tagged = max(0, min($tagged, $visits));

        if ($tagged === 0) {
            return 'Referrer';
        }

        if ($tagged === $visits) {
            return 'Tagged';
        }

        // Both kinds are there: neither share reads as 0 % or 100 %.
        $percent = max(1, min(99, (int) round($tagged * 100 / $visits)));

        return $percent.'% tagged · '.(100 - $percent).'% referrer';
    }

    /**
     * A key as an address names it, when it is one a visit could carry: a
     * paid source by its id, a fixed key, or a network with one of the four
     * classes; null for anything else.
     */
    public static function read(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return null;
        }

        if (self::paidSourceId($value) !== null || in_array($value, [self::SHARED, self::DIRECT, self::INTERNAL], true)) {
            return $value;
        }

        [$network, $class] = self::split($value);

        return $network !== '' && in_array($class, [self::PAID, self::ORGANIC, self::EMAIL, self::REFERRAL], true) ? $value : null;
    }

    /** The class of traffic utm_medium names; organic without one. */
    private static function classOf(?string $medium): string
    {
        return match (true) {
            $medium === null => self::ORGANIC,
            in_array($medium, self::PAID_MEDIUMS, true), str_starts_with($medium, 'paid') => self::PAID,
            $medium === self::EMAIL => self::EMAIL,
            $medium === self::REFERRAL => self::REFERRAL,
            default => self::ORGANIC,
        };
    }

    private static function networkOfHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        if (! str_contains($host, '.')) {
            return self::TAGS[$host] ?? null;
        }

        foreach (self::HOSTS as $domain => $network) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $network;
            }
        }

        foreach (self::COUNTRY_DOMAIN_NETWORKS as $network) {
            if (preg_match('/(^|\.)'.$network.'\.(com?\.)?[a-z]{2,3}$/', $host) === 1) {
                return $network;
            }
        }

        return null;
    }

    /** A host as the key names it: lower-case, without "www."; null when it is no host at all. */
    private static function host(?string $host): ?string
    {
        $host = $host === null ? '' : strtolower(trim($host, " \t\n\r\0\x0B."));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return $host !== '' && preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : null;
    }

    /** The domain a host is registered under, near enough: news.example.com → example.com, m.example.co.uk → example.co.uk. */
    private static function registrable(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $labels = explode('.', $host);
        $count = count($labels);

        if ($count <= 2) {
            return $host;
        }

        $keep = strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], self::COUNTRY_SECOND_LEVELS, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$keep));
    }

    /** A tag as the key keeps it: trimmed, lower-case, without control characters; null when nothing is left. */
    private static function clean(?string $value): ?string
    {
        $value = $value === null ? '' : strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value)));

        return $value === '' ? null : $value;
    }

    /** The paid source a key names; null for any other key. */
    public static function paidSourceId(string $key): ?int
    {
        return preg_match('/^paid:(\d+)$/', $key, $match) === 1 ? (int) $match[1] : null;
    }

    /** @return array{0: string, 1: string} the network and the class of a `{network}:{class}` key */
    private static function split(string $key): array
    {
        $at = strrpos($key, ':');

        return $at === false ? [$key, ''] : [substr($key, 0, $at), substr($key, $at + 1)];
    }

    private static function networkLabel(string $network, string $class): string
    {
        $known = self::NETWORKS[$network] ?? null;

        if ($known !== null) {
            return $class === self::PAID && $known['type'] === 'search' ? $known['label'].' Ads' : $known['label'];
        }

        return match (true) {
            str_starts_with($network, 'tag.') => substr($network, 4),
            str_starts_with($network, 'host.') => substr($network, 5),
            default => $network,
        };
    }
}
