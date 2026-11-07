<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

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

it('rejects an http public image URL on the expected host', function () {
    // The probe post has no image asset, so its Open Graph image comes from the
    // APP_URL fallback and never touches this disk. Without a check of its own,
    // an http:// disk on the right host passes everything else in this command
    // and then serves every real post image to Facebook over plain HTTP.
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'filesystems.disks.public.url' => 'http://rateguru.staging.myprojects.pp.ua/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])
        ->expectsOutputToContain('Public image URL must use HTTPS.')
        ->assertFailed();
});

it('accepts a protocol-relative public image URL', function () {
    // `//host/storage` carries no scheme of its own and inherits APP_URL's, which
    // this command has already required to be https — and PostOpenGraph resolves
    // it exactly that way. Reading a missing scheme as "not https" would fail a
    // deployment that serves every image over HTTPS.
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'filesystems.disks.public.url' => '//rateguru.staging.myprojects.pp.ua/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])->assertSuccessful();
});

it('accepts an uppercase HTTPS scheme on the public image URL', function () {
    // A scheme is case-insensitive, and this check must not become a new way to
    // fail a correctly configured deployment.
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'filesystems.disks.public.url' => 'HTTPS://rateguru.staging.myprojects.pp.ua/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])->assertSuccessful();
});

it('checks the disk media is stored on, not the one named public', function () {
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'media.disks.public' => 'media',
        'filesystems.disks.media' => ['driver' => 'local', 'root' => storage_path('app/media'), 'url' => 'http://cdn.example.com/media'],
        // Correct, but not where the images are.
        'filesystems.disks.public.url' => 'https://rateguru.staging.myprojects.pp.ua/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])
        ->expectsOutputToContain('Public image URL host must match')
        ->expectsOutputToContain('Public image URL must use HTTPS')
        ->assertFailed();
});

it('reads a media disk with no url of its own as serving from APP_URL, as Open Graph does', function (array $disk) {
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'media.disks.public' => 'media',
        'filesystems.disks.media' => ['driver' => 'local', 'root' => storage_path('app/media'), ...$disk],
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])->assertSuccessful();
})->with([
    'no url' => [[]],
    'a path' => [['url' => '/storage']],
]);

it('leaves a disk media is not stored on unchecked', function () {
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'media.disks.public' => 'media',
        'filesystems.disks.media' => ['driver' => 'local', 'root' => storage_path('app/media'), 'url' => 'https://rateguru.staging.myprojects.pp.ua/media'],
        // Wrong, but no image is served from it.
        'filesystems.disks.public.url' => 'http://cdn.example.com/storage',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])->assertSuccessful();
});

it('checks the host a disk builds its own urls on, as a bucket does with no url configured', function () {
    // A driver that names its own host for every file, as a bucket's does.
    Storage::extend('bucket', function ($app, array $config): FilesystemAdapter {
        $adapter = new class(storage_path('framework/testing/bucket')) extends LocalFilesystemAdapter
        {
            public function getUrl(string $path): string
            {
                return 'https://rg-media.s3.eu-central-1.amazonaws.com/'.$path;
            }
        };

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    });
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'media.disks.public' => 'media',
        'filesystems.disks.media' => ['driver' => 'bucket'],
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])
        ->expectsOutputToContain('Public image URL host must match')
        ->assertFailed();
});

it('says why image URLs cannot be built from the media disk', function () {
    config([
        'app.url' => 'https://rateguru.staging.myprojects.pp.ua',
        'media.disks.public' => 'nowhere',
    ]);

    $this->artisan('rateguru:sharing:verify', [
        '--expected-host' => 'rateguru.staging.myprojects.pp.ua',
    ])
        ->expectsOutputToContain('Public image URLs cannot be built from the [nowhere] disk: Disk [nowhere] does not have a configured driver.')
        ->assertFailed();
});
