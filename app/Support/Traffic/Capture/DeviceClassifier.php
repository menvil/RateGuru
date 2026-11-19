<?php

namespace App\Support\Traffic\Capture;

use App\Enums\TrafficDevice;

/**
 * Mobile or desktop, from what the browser says: its Client Hints first —
 * Sec-CH-UA-Mobile, and the platform, which counts an Android or iOS tablet
 * as mobile — and otherwise its User-Agent. Tablets are mobile: reports split
 * by the two devices only.
 */
final class DeviceClassifier
{
    private const string MOBILE_AGENT = '/iPhone|iPod|iPad|Android|Mobi|Tablet|Kindle|Silk|Windows Phone|Opera Mini|BlackBerry|webOS/i';

    public static function classify(?string $userAgent, ?string $mobileHint = null, ?string $platformHint = null): TrafficDevice
    {
        $mobileHint = $mobileHint === null ? null : trim($mobileHint);
        $platform = $platformHint === null ? '' : strtolower(trim($platformHint, " \t\""));

        if ($mobileHint === '?1') {
            return TrafficDevice::Mobile;
        }

        if ($mobileHint === '?0') {
            return in_array($platform, ['android', 'ios'], true) ? TrafficDevice::Mobile : TrafficDevice::Desktop;
        }

        return $userAgent !== null && preg_match(self::MOBILE_AGENT, $userAgent) === 1 ? TrafficDevice::Mobile : TrafficDevice::Desktop;
    }
}
