<?php

namespace App\Support\Traffic\Capture;

use App\Enums\TrafficChannel;

/**
 * The unpaid channel a visit came through, from where it came from: a game's
 * result page reached from outside (or a game reached from a result page) is
 * a shared result; this site's own pages are inside the site; a search
 * engine is search; any other site is another site — the common redirectors
 * of social networks read as the site they stand for; nothing at all is
 * direct, except in the in-app browsers of social apps, which send none.
 */
final class ChannelClassifier
{
    /** @var list<string> */
    private const array SEARCH_ENGINES = [
        'google.', 'bing.com', 'yahoo.', 'yandex.', 'duckduckgo.com', 'baidu.com', 'ecosia.org', 'qwant.com',
        'search.brave.com', 'startpage.com', 'naver.com', 'seznam.cz', 'ask.com', 'aol.com', 'sogou.com', 'so.com',
    ];

    /** @var array<string, string> */
    private const array REDIRECTORS = [
        'l.facebook.com' => 'facebook.com',
        'lm.facebook.com' => 'facebook.com',
        'm.facebook.com' => 'facebook.com',
        'l.instagram.com' => 'instagram.com',
        't.co' => 'twitter.com',
        'out.reddit.com' => 'reddit.com',
        'com.reddit.frontpage' => 'reddit.com',
        'lnkd.in' => 'linkedin.com',
        't.me' => 'telegram.org',
        'away.vk.com' => 'vk.com',
    ];

    /**
     * @param  string|null  $referrer  the full referring address, as sent
     * @param  string  $ownHost  this site's host
     * @param  string|null  $referrerRoute  the route name the referrer's path matches on this site, when it is this site's
     * @return array{channel: TrafficChannel, origin: string|null}
     */
    public static function classify(?string $referrer, string $ownHost, bool $landsOnResult, ?string $referrerRoute, ?string $userAgent): array
    {
        $referrer = $referrer === null ? '' : trim($referrer);

        if ($referrer === '') {
            if (! $landsOnResult && $userAgent !== null && preg_match('/FBAN|FBAV|Instagram|Line\/|Snapchat|TikTok/i', $userAgent) === 1) {
                return ['channel' => TrafficChannel::Referral, 'origin' => 'in-app browser'];
            }

            return ['channel' => $landsOnResult ? TrafficChannel::Shared : TrafficChannel::Direct, 'origin' => null];
        }

        if (str_starts_with($referrer, 'android-app://')) {
            $app = strtolower((string) parse_url($referrer, PHP_URL_HOST));

            if ($landsOnResult) {
                return ['channel' => TrafficChannel::Shared, 'origin' => self::REDIRECTORS[$app] ?? $app];
            }

            if ($app === 'com.google.android.googlequicksearchbox' || $app === 'com.google.android.gm') {
                return ['channel' => TrafficChannel::Search, 'origin' => 'google'];
            }

            return ['channel' => TrafficChannel::Referral, 'origin' => self::REDIRECTORS[$app] ?? $app];
        }

        $host = self::host($referrer);

        if ($host === null) {
            return ['channel' => TrafficChannel::Direct, 'origin' => null];
        }

        if ($host === self::bare($ownHost)) {
            if ($referrerRoute !== null && str_starts_with($referrerRoute, 'games.') && str_contains($referrerRoute, 'result') && ! $landsOnResult) {
                return ['channel' => TrafficChannel::Shared, 'origin' => 'result page'];
            }

            return ['channel' => TrafficChannel::Internal, 'origin' => self::surface($referrerRoute)];
        }

        if ($landsOnResult) {
            return ['channel' => TrafficChannel::Shared, 'origin' => self::REDIRECTORS[$host] ?? $host];
        }

        foreach (self::SEARCH_ENGINES as $engine) {
            if (self::isEngine($host, $engine)) {
                return ['channel' => TrafficChannel::Search, 'origin' => explode('.', rtrim($engine, '.'))[0]];
            }
        }

        return ['channel' => TrafficChannel::Referral, 'origin' => self::REDIRECTORS[$host] ?? $host];
    }

    /**
     * Whether a host is the engine's own: its domain or one under it, a name
     * ending in a dot standing for the engine's country domains (google.de,
     * google.co.uk) — never a site that only starts with the engine's name.
     */
    private static function isEngine(string $host, string $engine): bool
    {
        if (str_ends_with($engine, '.')) {
            return preg_match('/(^|\.)'.preg_quote(rtrim($engine, '.'), '/').'\.(com?\.)?[a-z]{2,3}$/', $host) === 1;
        }

        return $host === $engine || str_ends_with($host, '.'.$engine);
    }

    /** A referrer's host without "www.", lower-case; null when it has none. */
    public static function host(?string $referrer): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }

        $host = parse_url(trim($referrer), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? self::bare($host) : null;
    }

    private static function bare(string $host): string
    {
        $host = strtolower(trim($host, '. '));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function surface(?string $route): string
    {
        return match (true) {
            $route === null => 'page',
            $route === 'feed' => 'feed',
            str_starts_with($route, 'posts.') => 'post',
            str_starts_with($route, 'games.') => 'game',
            str_starts_with($route, 'profile.') => 'profile',
            default => 'page',
        };
    }
}
