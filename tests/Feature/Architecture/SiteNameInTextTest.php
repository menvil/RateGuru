<?php

use Illuminate\Support\Facades\File;

/*
 * One repository serves projects of any name, so text people read takes the
 * name from Project settings (site_name()). Comments may name the engine.
 */

/** What may keep the engine's name, each for a reason of its own. */
const ENGINE_NAME_ALLOWED = [
    // Operators' commands, and how they are invoked.
    'app/Console/',
    // The name a project has until it chooses one.
    'app/Support/Settings/ProjectSettingsManager.php',
    // Telemetry tags and the user agent are the engine identifying itself to services, not text on a page.
    'app/Providers/ObservabilityServiceProvider.php',
    'app/Services/Traffic/Connectors/CallsNetworkApi.php',
    // Developer references that answer 404 outside local and testing.
    'app/Filament/Pages/AdminUiKit.php',
    'resources/views/filament/pages/admin-ui-kit',
    'resources/views/dev/',
];

function engineNameAllowed(string $path): bool
{
    return collect(ENGINE_NAME_ALLOWED)->contains(fn (string $allowed): bool => str_starts_with($path, $allowed));
}

it('keeps the engine’s name out of every string the application shows', function () {
    $found = [];

    foreach (File::allFiles(app_path()) as $file) {
        $path = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

        if ($file->getExtension() !== 'php' || engineNameAllowed($path)) {
            continue;
        }

        foreach (token_get_all($file->getContents()) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) && str_contains($token[1], 'RateGuru')) {
                $found[] = $path.':'.$token[2];
            }
        }
    }

    expect($found)->toBe([]);
});

it('keeps the engine’s name out of every view', function () {
    $found = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $path = 'resources/views/'.str_replace('\\', '/', $file->getRelativePathname());

        if (engineNameAllowed($path)) {
            continue;
        }

        $code = preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $file->getContents());

        if (str_contains((string) $code, 'RateGuru')) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
});
