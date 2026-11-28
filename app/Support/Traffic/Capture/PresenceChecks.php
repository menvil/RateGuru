<?php

namespace App\Support\Traffic\Capture;

use App\Enums\TrafficDevice;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * What about a browser contradicts what it says it is, from what its landing
 * page measured (PresenceFacts) against what the server knows of the request:
 * the country its address is in, the device and system its User-Agent names.
 * Each finding is a bit of a visit's quality_flags. None proves a bot — a VPN
 * moves a person's address, a privacy browser hides its time zone — so a
 * visit is judged by how many it has and a place by how often its visits
 * have them, never by one alone.
 *
 * - webdriver, automation: navigator.webdriver, an automation tool's marks,
 *   or BotD calling it a bot;
 * - framed: the page inside another site's frame;
 * - time zone: the browser's zone is none of its address's country's, nor at
 *   the same offset as one of them (UTC and Etc zones say nothing);
 * - touch: a phone without touch, or an iPhone without its five touch points;
 * - screen: no screen, a browser window larger than the screen (its outer
 *   size, which page zoom leaves alone), or exactly the 800 × 600 page
 *   automation opens by default on a desktop;
 * - graphics: drawn by software, as on servers and virtual machines;
 * - platform: navigator.platform naming another system than the User-Agent;
 * - hidden input, scripted input: a touch or click while the page was hidden,
 *   or clicks, touches or wheels a script made where the browser made none
 *   (keys aren't counted: password managers type into fields that way).
 */
final class PresenceChecks
{
    public const int WEBDRIVER = 1;

    public const int AUTOMATION = 2;

    public const int FRAMED = 4;

    public const int TIME_ZONE = 8;

    public const int TOUCH = 16;

    public const int SCREEN = 32;

    public const int GRAPHICS = 64;

    public const int PLATFORM = 128;

    public const int HIDDEN_INPUT = 256;

    public const int SCRIPTED_INPUT = 512;

    /** @var array<int, string> each finding as the admin names it, in the order it is listed */
    public const array WORDS = [
        self::WEBDRIVER => 'Driven by WebDriver',
        self::AUTOMATION => 'Automation tool',
        self::FRAMED => 'Inside another site’s frame',
        self::TIME_ZONE => 'Time zone not of its country',
        self::TOUCH => 'Touch not of its device',
        self::SCREEN => 'Impossible screen',
        self::GRAPHICS => 'Software graphics',
        self::PLATFORM => 'System not of its browser',
        self::HIDDEN_INPUT => 'Input while hidden',
        self::SCRIPTED_INPUT => 'Scripted input',
    ];

    /** @param  string|null  $country  the address's country code (Countries), null where unknown */
    public static function flags(PresenceFacts $presence, ?string $country, TrafficDevice $device, ?string $userAgent): int
    {
        $facts = $presence->facts;
        $agent = (string) $userAgent;
        $flags = 0;

        $flags |= ($facts['webdriver'] ?? false) === true ? self::WEBDRIVER : 0;
        $flags |= (int) ($facts['automation'] ?? 0) > 0 || (isset($facts['botd']) && $facts['botd'] !== 'none') ? self::AUTOMATION : 0;
        $flags |= ($facts['framed'] ?? false) === true ? self::FRAMED : 0;
        $flags |= self::zoneElsewhere($facts['time_zone'] ?? null, $country) ? self::TIME_ZONE : 0;
        $flags |= self::touchOff($facts, $device, $agent) ? self::TOUCH : 0;
        $flags |= self::screenOff($facts, $device) ? self::SCREEN : 0;
        $flags |= ($facts['graphics'] ?? null) === 'software' ? self::GRAPHICS : 0;
        $flags |= self::platformOff($facts['platform'] ?? null, $agent) ? self::PLATFORM : 0;
        $flags |= ($facts['hidden_input'] ?? false) === true ? self::HIDDEN_INPUT : 0;
        // Only a page a script touched and nobody did: a person's extension may add events beside their own.
        $flags |= (int) ($facts['untrusted'] ?? 0) > 0 && (int) ($facts['inputs'] ?? 0) === 0 ? self::SCRIPTED_INPUT : 0;

        return $flags;
    }

    /** @return list<string> */
    public static function words(int $flags): array
    {
        return array_values(array_filter(self::WORDS, fn (int $flag): bool => ($flags & $flag) !== 0, ARRAY_FILTER_USE_KEY));
    }

    /**
     * Whether a browser's time zone is none of its address's country's, nor
     * at the offset one of them has now (an old name of a zone keeps its
     * offset); unknown when either is missing or the zone is UTC or Etc.
     */
    public static function zoneElsewhere(mixed $zone, ?string $country): bool
    {
        if (! is_string($zone) || $zone === '' || $country === null || $country === '' || $zone === 'UTC' || str_starts_with($zone, 'Etc/')) {
            return false;
        }

        try {
            $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($country));
            $browser = new DateTimeZone($zone);
        } catch (Throwable) {
            return false;
        }

        if ($zones === [] || in_array($zone, $zones, true)) {
            return false;
        }

        $now = new DateTimeImmutable('now');
        $offset = $browser->getOffset($now);

        foreach ($zones as $name) {
            if ((new DateTimeZone($name))->getOffset($now) === $offset) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $facts */
    private static function touchOff(array $facts, TrafficDevice $device, string $agent): bool
    {
        if (! isset($facts['touch_points'])) {
            return false;
        }

        $points = (int) $facts['touch_points'];

        return ($device === TrafficDevice::Mobile && $points === 0)
            || (preg_match('/iPhone/i', $agent) === 1 && $points !== 5);
    }

    /** @param  array<string, mixed>  $facts */
    private static function screenOff(array $facts, TrafficDevice $device): bool
    {
        $screen = [$facts['screen_width'] ?? null, $facts['screen_height'] ?? null];
        $outer = [$facts['outer_width'] ?? null, $facts['outer_height'] ?? null];
        $viewport = [$facts['window_width'] ?? null, $facts['window_height'] ?? null];

        if (in_array(null, $screen, true)) {
            return false;
        }

        if ($screen[0] === 0 || $screen[1] === 0) {
            return true;
        }

        // The browser window — outer sizes, which page zoom leaves alone — wider and taller than the screen, past a
        // maximised window's borders; and the 800 × 600 page automation opens by default on a desktop.
        return (! in_array(null, $outer, true) && $outer[0] > $screen[0] + 16 && $outer[1] > $screen[1] + 16)
            || ($device === TrafficDevice::Desktop && $viewport === [800, 600]);
    }

    private static function platformOff(mixed $platform, string $agent): bool
    {
        if (! is_string($platform) || $platform === '' || $agent === '') {
            return false;
        }

        $says = match (true) {
            preg_match('/iPhone|iPad|iPod/i', $agent) === 1 => ['iPhone', 'iPad', 'iPod', 'MacIntel'],
            stripos($agent, 'Android') !== false => ['Linux', 'Android', 'null'],
            stripos($agent, 'Windows') !== false => ['Win'],
            stripos($agent, 'Macintosh') !== false || stripos($agent, 'Mac OS X') !== false => ['Mac', 'iPad', 'iPhone'],
            stripos($agent, 'CrOS') !== false => ['Linux', 'CrOS'],
            stripos($agent, 'Linux') !== false => ['Linux'],
            default => null,
        };

        if ($says === null) {
            return false;
        }

        foreach ($says as $prefix) {
            if (stripos($platform, $prefix) === 0) {
                return false;
            }
        }

        return true;
    }
}
