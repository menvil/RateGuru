<?php

it('accepts an https app url matching the deployment hostname', function () {
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'filesystems.disks.public.url' => 'https://rateguru.staging.myprojects.pp.ua/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])
        ->expectsOutputToContain('Sharing configuration is valid')
        ->assertSuccessful();
});

it('rejects a missing non-https or unexpected app url', function (string $url, string $message) {
    config([
        'app.url' => $url,
        'filesystems.disks.public.url' => rtrim($url, '/').'/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'missing' => ['', 'APP_URL must be configured'],
    'http' => ['http://rateguru.staging.myprojects.pp.ua', 'APP_URL must use HTTPS'],
    'wrong hostname' => ['https://example.com', 'APP_URL host must match'],
]);

it('rejects a public image disk on a different hostname', function () {
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'filesystems.disks.public.url' => 'https://cdn.example.com/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])
        ->expectsOutputToContain('Public image URL host must match')
        ->assertFailed();
});
