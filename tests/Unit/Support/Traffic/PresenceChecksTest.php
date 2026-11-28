<?php

use App\Enums\TrafficDevice;
use App\Support\Traffic\Capture\PresenceChecks;
use App\Support\Traffic\Capture\PresenceFacts;

/**
 * What a landing page measured of its visitor, read and capped, and what about
 * the browser contradicts what it says it is: each check alone, the ones that
 * must say nothing when a fact is missing, and a later report merged over an
 * earlier one.
 */
const IPHONE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
const IPAD_DESKTOP_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15';
const ANDROID_AGENT = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';

/** A real-looking desktop Chrome in Germany: nothing to find. */
function presenceFacts(array $changes = []): PresenceFacts
{
    return PresenceFacts::from([
        'vis0' => '1', 'tvis' => '420', 'visms' => '9000', 'tin' => '1800', 'inkind' => 'pointer', 'ins' => '3', 'unt' => '0', 'hin' => '0',
        'wd' => '0', 'auto' => '0', 'botd' => 'none', 'frm' => '0', 'gl' => 'hw', 'tz' => 'Europe/Berlin', 'tzo' => '-60', 'langs' => 'de-DE,de',
        'plat' => 'Win32', 'sw' => '1920', 'sh' => '1080', 'ww' => '1536', 'wh' => '754', 'ow' => '1536', 'oh' => '864', 'dpr' => '1.25', 'mtp' => '0', 'hc' => '8', 'dm' => '8', 'plg' => '5',
        ...$changes,
    ]);
}

it('finds nothing in a real browser where it says it is, and says it was seen and used', function () {
    $facts = presenceFacts();

    expect(PresenceChecks::flags($facts, 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT))->toBe(0)
        ->and($facts->seen())->toBeTrue()
        ->and($facts->engaged())->toBeTrue();
});

it('finds each contradiction on its own', function (array $changes, string $country, TrafficDevice $device, string $agent, int $flag) {
    expect(PresenceChecks::flags(presenceFacts($changes), $country, $device, $agent))->toBe($flag);
})->with([
    'webdriver' => [['wd' => '1'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::WEBDRIVER],
    'an automation tool’s marks' => [['auto' => '16'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::AUTOMATION],
    'BotD calls it a bot' => [['botd' => 'headless_chrome'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::AUTOMATION],
    'inside another site’s frame' => [['frm' => '1'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::FRAMED],
    'a Chinese zone on a German address' => [['tz' => 'Asia/Shanghai'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::TIME_ZONE],
    'a phone with no touch' => [['mtp' => '0', 'plat' => 'Linux armv8l', 'sw' => '412', 'sh' => '915', 'ww' => '412', 'wh' => '780'], 'DE', TrafficDevice::Mobile, ANDROID_AGENT, PresenceChecks::TOUCH],
    'an iPhone without five touch points' => [['mtp' => '1', 'plat' => 'iPhone', 'sw' => '393', 'sh' => '852', 'ww' => '393', 'wh' => '660'], 'DE', TrafficDevice::Mobile, IPHONE_AGENT, PresenceChecks::TOUCH],
    'no screen' => [['sw' => '0', 'sh' => '0'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::SCREEN],
    'a browser window larger than the screen' => [['sw' => '800', 'sh' => '600', 'ow' => '1280', 'oh' => '720'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::SCREEN],
    'the 800 × 600 window automation opens' => [['ww' => '800', 'wh' => '600'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::SCREEN],
    'software graphics' => [['gl' => 'software'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::GRAPHICS],
    'a Linux machine calling itself Windows' => [['plat' => 'Linux x86_64'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::PLATFORM],
    'input while hidden' => [['hin' => '1'], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::HIDDEN_INPUT],
    'input a script made where nobody made any' => [['unt' => '4', 'ins' => '0', 'tin' => ''], 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT, PresenceChecks::SCRIPTED_INPUT],
]);

it('says nothing of what it can’t know: UTC and Etc zones, an unknown country, a zone of the same offset, missing facts', function () {
    expect(PresenceChecks::zoneElsewhere('UTC', 'DE'))->toBeFalse()
        ->and(PresenceChecks::zoneElsewhere('Etc/GMT-1', 'DE'))->toBeFalse()
        ->and(PresenceChecks::zoneElsewhere('Asia/Shanghai', null))->toBeFalse()
        ->and(PresenceChecks::zoneElsewhere('Not/AZone', 'DE'))->toBeFalse()
        // The old name of India's zone is none of the country's names, but keeps its offset.
        ->and(PresenceChecks::zoneElsewhere('Asia/Calcutta', 'IN'))->toBeFalse()
        ->and(PresenceChecks::zoneElsewhere('Europe/Paris', 'DE'))->toBeFalse()
        ->and(PresenceChecks::zoneElsewhere('America/New_York', 'DE'))->toBeTrue()
        ->and(PresenceChecks::flags(PresenceFacts::from([]), 'DE', TrafficDevice::Mobile, IPHONE_AGENT))->toBe(0)
        // An iPad asking for the desktop site says it is a Mac, its platform MacIntel, with five touch points.
        ->and(PresenceChecks::flags(presenceFacts(['plat' => 'MacIntel', 'mtp' => '5', 'sw' => '1024', 'sh' => '1366', 'ww' => '1024', 'wh' => '1292', 'ow' => '1024', 'oh' => '1366']), 'DE', TrafficDevice::Desktop, IPAD_DESKTOP_AGENT))->toBe(0)
        // A page zoomed out to 80%: a viewport wider and taller than the screen, the window itself not.
        ->and(PresenceChecks::flags(presenceFacts(['ww' => '2400', 'wh' => '1194']), 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT))->toBe(0)
        // A password manager's or extension's events beside the person's own.
        ->and(PresenceChecks::flags(presenceFacts(['unt' => '6']), 'DE', TrafficDevice::Desktop, DESKTOP_CHROME_USER_AGENT))->toBe(0);
});

it('caps and words what a report carries, so a forged one stores no more than a real one', function () {
    $facts = PresenceFacts::from(['tvis' => '99999999999', 'ins' => '-5', 'inkind' => 'telepathy', 'gl' => '<script>', 'dpr' => '99', 'tzo' => '-5000', 'tz' => str_repeat('x', 200), 'botd' => 'none', 'unknown' => 'dropped'])->facts;

    expect($facts['to_visible_ms'])->toBe(3_600_000)
        ->and($facts['inputs'])->toBe(0)
        ->and($facts)->not->toHaveKeys(['input_kind', 'graphics', 'unknown'])
        ->and($facts['pixel_ratio'])->toBe(10.0)
        ->and($facts['offset_minutes'])->toBe(-900)
        ->and(mb_strlen($facts['time_zone']))->toBe(64)
        ->and(PresenceFacts::from(['vis0' => '0'])->seen())->toBeFalse()
        ->and(PresenceFacts::from(['vis0' => '0', 'tvis' => '5200'])->seen())->toBeTrue()
        ->and(PresenceFacts::from(['tvis' => '5200'])->engaged())->toBeFalse();
});

it('merges a later report of the page over the first: counts and times grown, first input and browser facts kept', function () {
    $first = presenceFacts(['tin' => '1800', 'ins' => '1', 'visms' => '3000', 'hin' => '0'])->facts;
    $later = presenceFacts(['tin' => '5000', 'inkind' => 'key', 'ins' => '7', 'visms' => '42000', 'hin' => '1', 'tz' => 'Asia/Tokyo'])->over($first);

    expect($later['to_input_ms'])->toBe(1800)
        ->and($later['input_kind'])->toBe('pointer')
        ->and($later['inputs'])->toBe(7)
        ->and($later['visible_ms'])->toBe(42000)
        ->and($later['hidden_input'])->toBeTrue()
        ->and($later['time_zone'])->toBe('Europe/Berlin');
});
