<?php

namespace App\Support\Traffic\Capture;

/**
 * Cheap signs that a hit is not a person, as bits of a visit's bot_flags:
 * a crawler or link-preview User-Agent, a prefetch or prerender, a headless
 * browser, a request without the headers every browser sends, and a burst
 * from one address (set by the click recorder). Flags mark a visit; only the
 * crawler bit keeps an unpaid visit from being recorded at all.
 */
final class BotSignals
{
    public const int CRAWLER = 1;

    public const int PREFETCH = 2;

    public const int HEADLESS = 4;

    public const int NO_BROWSER_HEADERS = 8;

    public const int BURST = 16;

    /** @var array<int, string> each flag as the admin names it, in the order it is listed */
    public const array WORDS = [
        self::CRAWLER => 'Crawler or link preview',
        self::PREFETCH => 'Prefetch',
        self::HEADLESS => 'Headless browser',
        self::NO_BROWSER_HEADERS => 'No browser headers',
        self::BURST => 'Burst from one address',
    ];

    // "bot" as a word or the end of a crawler's name (Googlebot, bingbot/2.0) — not inside a phone's brand such as CUBOT.
    private const string CRAWLER_AGENT = '/(?<!cu)bot\b|bot\/|crawl|spider|slurp|preview|fetch|scrap|monitor|curl|wget|python|java\/|go-http|httpclient|okhttp|facebookexternalhit|whatsapp|telegram|discord|skype|embedly|headlesschrome/i';

    /** @param  array<string, string|null>  $headers  lower-case header name => value */
    public static function of(?string $userAgent, array $headers): int
    {
        $flags = 0;
        $agent = $userAgent ?? '';

        if ($agent !== '' && preg_match(self::CRAWLER_AGENT, $agent) === 1) {
            $flags |= self::CRAWLER;
        }

        foreach (['sec-purpose', 'purpose', 'x-moz', 'x-purpose'] as $name) {
            if (str_contains(strtolower((string) ($headers[$name] ?? '')), 'prefetch') || str_contains(strtolower((string) ($headers[$name] ?? '')), 'preview')) {
                $flags |= self::PREFETCH;
            }
        }

        if (stripos($agent, 'HeadlessChrome') !== false || stripos((string) ($headers['sec-ch-ua'] ?? ''), 'HeadlessChrome') !== false) {
            $flags |= self::HEADLESS;
        }

        $chromium = preg_match('/Chrome\/\d+/', $agent) === 1 && stripos($agent, 'Edg/') === false && stripos($agent, 'OPR/') === false;

        if ($agent === '' || trim((string) ($headers['accept-language'] ?? '')) === '' || ($chromium && trim((string) ($headers['sec-ch-ua'] ?? '')) === '')) {
            $flags |= self::NO_BROWSER_HEADERS;
        }

        return $flags;
    }

    /**
     * The flags a visit carries, as the admin names them.
     *
     * @return list<string>
     */
    public static function words(int $flags): array
    {
        return array_values(array_filter(self::WORDS, fn (int $flag): bool => ($flags & $flag) !== 0, ARRAY_FILTER_USE_KEY));
    }

    public static function isCrawler(int $flags): bool
    {
        return ($flags & self::CRAWLER) !== 0;
    }

    /** Whether a User-Agent is a crawler's or a link preview's, by itself. */
    public static function isCrawlerAgent(?string $userAgent): bool
    {
        return $userAgent !== null && $userAgent !== '' && preg_match(self::CRAWLER_AGENT, $userAgent) === 1;
    }
}
