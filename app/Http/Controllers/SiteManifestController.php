<?php

namespace App\Http\Controllers;

use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Http\JsonResponse;

/**
 * The web manifest, named after the project in its settings, as the browser
 * tab and the header name it: one repository serves projects of any name, so
 * a name written into a static public/ file would be wrong for all but one.
 * The icons it lists are the static ones in public/.
 */
final class SiteManifestController extends Controller
{
    public function __invoke(ProjectSettingsManager $settings): JsonResponse
    {
        $name = $settings->current()->siteName();

        return response()->json(
            [
                'name' => $name,
                'short_name' => $name,
                'start_url' => route('feed', absolute: false),
                'display' => 'standalone',
                'background_color' => '#000000',
                'theme_color' => '#000000',
                'icons' => [
                    ['src' => asset('android-chrome-192x192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                    ['src' => asset('android-chrome-512x512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                    ['src' => asset('android-chrome-maskable-512x512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ],
            ],
            headers: ['Content-Type' => 'application/manifest+json'],
            options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
