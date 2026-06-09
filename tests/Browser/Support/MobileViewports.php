<?php

namespace Tests\Browser\Support;

/**
 * Mobile viewport dimensions for browser smoke tests.
 *
 * Usage in browser tests:
 *   ->viewport(...MobileViewports::SMALL_MOBILE)
 *   ->viewport(...MobileViewports::MOBILE)
 *   ->viewport(...MobileViewports::LARGE_MOBILE)
 *   ->viewport(...MobileViewports::TABLET)
 */
class MobileViewports
{
    /** 375×812 — small mobile baseline (iPhone SE, older iPhones) */
    public const SMALL_MOBILE = [375, 812];

    /** 390×844 — common mobile baseline (iPhone 14/15) */
    public const MOBILE = [390, 844];

    /** 430×932 — large mobile baseline (iPhone 14/15 Plus) */
    public const LARGE_MOBILE = [430, 932];

    /** 768×1024 — tablet boundary */
    public const TABLET = [768, 1024];

    /** All mobile viewports as an array for iteration */
    public static function all(): array
    {
        return [
            'small_mobile' => self::SMALL_MOBILE,
            'mobile' => self::MOBILE,
            'large_mobile' => self::LARGE_MOBILE,
            'tablet' => self::TABLET,
        ];
    }

    /** Mobile-only viewports (below tablet) */
    public static function mobileOnly(): array
    {
        return [
            'small_mobile' => self::SMALL_MOBILE,
            'mobile' => self::MOBILE,
            'large_mobile' => self::LARGE_MOBILE,
        ];
    }
}
