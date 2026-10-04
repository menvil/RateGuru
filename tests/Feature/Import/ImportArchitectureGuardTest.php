<?php

/**
 * A grep-based trip-wire, not exhaustive static analysis — same pattern as
 * tests/Unit/Services/Media/MediaLifecycleDoesNotUseFilesystemTest.php.
 * PinnedImportHttpTransport is the one class in this application allowed to
 * open an outbound connection for a user-controlled import URL (it pins the
 * connection to a resolved-and-validated IP — see its own docblock); every
 * other file in the import subsystem must go through SafeImportHttpClient
 * instead of reaching for a raw network primitive that would bypass
 * UrlImportValidator entirely.
 */
function importSubsystemPhpFiles(): array
{
    $roots = [
        app_path('Actions/Import'),
        app_path('Support/Import'),
        app_path('Livewire/Import'),
    ];

    $files = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

it('scans at least the files this guard is meant to protect, so the scan itself is not silently empty', function () {
    $files = importSubsystemPhpFiles();

    expect($files)->not->toBeEmpty();
    expect(count($files))->toBeGreaterThanOrEqual(15);
});

it('never calls file_get_contents, fopen, or a raw curl_ function anywhere in the import subsystem', function () {
    foreach (importSubsystemPhpFiles() as $path) {
        $source = file_get_contents($path);
        $relative = str_replace(app_path().'/', '', $path);

        expect($source)->not->toContain('file_get_contents(', "Found file_get_contents( in {$relative}");
        expect($source)->not->toContain('fopen(', "Found fopen( in {$relative}");
        expect($source)->not->toMatch('/\bcurl_[a-z_]+\s*\(/i', "Found a raw curl_*() call in {$relative}");
    }
});

it('never uses the Http facade outside PinnedImportHttpTransport', function () {
    foreach (importSubsystemPhpFiles() as $path) {
        if (basename($path) === 'PinnedImportHttpTransport.php') {
            continue;
        }

        $source = file_get_contents($path);
        $relative = str_replace(app_path().'/', '', $path);

        expect($source)
            ->not->toContain('Http::', "Found Http:: in {$relative}")
            ->not->toContain('Illuminate\Http\Client\Factory', "Found the Http client factory referenced directly in {$relative}");
    }
});

it('confirms PinnedImportHttpTransport is the one file actually allowed to use the Http facade', function () {
    $source = file_get_contents(app_path('Support/Import/PinnedImportHttpTransport.php'));

    expect($source)->toContain('Http::');
});

it('proves the raw curl_ regex actually catches a real call and does not false-positive on a CURLOPT_ constant', function () {
    // A negative control on the guard itself: if a future edit to the
    // pattern above (escaping, character class, anchoring) ever made it
    // stop matching real curl_*() calls, the main guard test would keep
    // passing for the wrong reason -- silently ineffective rather than
    // failing loudly. Same exact pattern, asserted against both cases
    // directly.
    $pattern = '/\bcurl_[a-z_]+\s*\(/i';

    expect(preg_match($pattern, 'curl_init($url);'))->toBe(1)
        ->and(preg_match($pattern, '$ch = curl_exec($handle);'))->toBe(1)
        ->and(preg_match($pattern, 'CURLOPT_RESOLVE => [$entry],'))->toBe(0)
        ->and(preg_match($pattern, 'CURLOPT_SSL_VERIFYPEER'))->toBe(0);
});
