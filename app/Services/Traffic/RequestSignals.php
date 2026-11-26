<?php

namespace App\Services\Traffic;

use App\Support\Geo\GeoLookup;
use App\Support\Privacy\IpAddressHash;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\ChannelClassifier;
use App\Support\Traffic\Capture\DeviceClassifier;
use App\Support\Traffic\Capture\VisitorSignals;
use Illuminate\Http\Request;

/**
 * Reads VisitorSignals off the current request. Bound to the request, like
 * the visitor resolver: the pure classifiers it calls do the deciding. The
 * address is looked up in the local IP database once, and kept only as an
 * HMAC under the application key — the same keyed hash the site's other
 * abuse records use.
 */
final readonly class RequestSignals
{
    public function __construct(private Request $request, private GeoLookup $geo) {}

    public function read(): VisitorSignals
    {
        $agent = $this->request->userAgent();
        $headers = [];

        foreach (['sec-purpose', 'purpose', 'x-moz', 'x-purpose', 'sec-ch-ua', 'accept-language'] as $name) {
            $headers[$name] = $this->request->headers->get($name);
        }

        $ip = $this->request->ip();
        $referrer = $this->request->headers->get('referer');

        return new VisitorSignals(
            device: DeviceClassifier::classify($agent, $this->request->headers->get('sec-ch-ua-mobile'), $this->request->headers->get('sec-ch-ua-platform')),
            botFlags: BotSignals::of($agent, $headers),
            location: $this->geo->lookup($ip),
            ipHash: $ip === null ? null : IpAddressHash::of($ip, (string) config('app.key')),
            os: self::os($agent),
            browser: self::browser($agent),
            language: self::language($this->request->headers->get('accept-language')),
            referrer: is_string($referrer) ? mb_substr($referrer, 0, 1000) : null,
            // A host is at most 253 characters, but a bot can send anything: kept to what the column holds.
            referrerHost: is_string($referrer) && ($host = ChannelClassifier::host($referrer)) !== null ? mb_substr($host, 0, 190) : null,
            path: mb_substr('/'.ltrim($this->request->path(), '/'), 0, 255),
        );
    }

    private static function os(?string $agent): ?string
    {
        return match (true) {
            $agent === null => null,
            preg_match('/iPhone|iPad|iPod/i', $agent) === 1 => 'iOS',
            stripos($agent, 'Android') !== false => 'Android',
            stripos($agent, 'Windows') !== false => 'Windows',
            stripos($agent, 'Mac OS X') !== false || stripos($agent, 'Macintosh') !== false => 'macOS',
            stripos($agent, 'CrOS') !== false => 'ChromeOS',
            stripos($agent, 'Linux') !== false => 'Linux',
            default => null,
        };
    }

    private static function browser(?string $agent): ?string
    {
        return match (true) {
            $agent === null => null,
            stripos($agent, 'Edg/') !== false => 'Edge',
            stripos($agent, 'OPR/') !== false || stripos($agent, 'Opera') !== false => 'Opera',
            stripos($agent, 'SamsungBrowser') !== false => 'Samsung Internet',
            stripos($agent, 'Firefox/') !== false || stripos($agent, 'FxiOS') !== false => 'Firefox',
            stripos($agent, 'CriOS') !== false || stripos($agent, 'Chrome/') !== false => 'Chrome',
            stripos($agent, 'Safari/') !== false => 'Safari',
            default => null,
        };
    }

    private static function language(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $first = strtolower(trim(explode(',', $header)[0]));
        $first = trim(explode(';', $first)[0]);

        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $first) === 1 ? $first : null;
    }
}
