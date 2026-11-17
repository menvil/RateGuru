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

    private const string CRAWLER_AGENT = '/bot|crawl|spider|slurp|preview|fetch|scrap|monitor|curl|wget|python|java\/|go-http|httpclient|okhttp|facebookexternalhit|whatsapp|telegram|discord|skype|embedly|headlesschrome/i';

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

    public static function isCrawler(int $flags): bool
    {
        return ($flags & self::CRAWLER) !== 0;
    }
}
