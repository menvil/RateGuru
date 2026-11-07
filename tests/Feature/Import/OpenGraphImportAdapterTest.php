<?php

use App\Enums\ImportProvider;
use App\Support\Import\Adapters\OpenGraphImportAdapter;
use App\Support\Import\Dns\HostResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    bindFakeHostResolver();
});

it('creates import preview from open graph page', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="Imported Title"><meta property="og:image" content="https://example.com/image.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->title)->toBe('Imported Title');
    expect($preview->imageUrl)->toBe('https://example.com/image.jpg');
    expect($preview->provider)->toBe(ImportProvider::OpenGraph);
});

it('returns preview without image when og image is missing', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="Title Only"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->title)->toBe('Title Only');
    expect($preview->hasImage())->toBeFalse();
});

it('falls back to html title when og tags are absent', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<html><head><title>Plain Title</title></head></html>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->title)->toBe('Plain Title');
});

it('resolves a relative og:image against the response finalUrl, not the originally requested url, after a redirect to a different directory', function () {
    Http::fake([
        'example.com/a/original' => Http::response('', 302, ['Location' => 'https://example.com/b/final']),
        'example.com/b/final' => Http::response(
            '<head><meta property="og:image" content="photo.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/a/original');

    // Relative to /b/ (the final directory the redirect landed on), not
    // /a/ (the originally requested directory) -- proves relative
    // og:image resolution uses $response->finalUrl, not the input $url.
    expect($preview->imageUrl)->toBe('https://example.com/b/photo.jpg');
});

it('rejects og image url that is unsafe', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:image" content="http://192.168.1.1/image.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->hasImage())->toBeFalse();
});

it('takes the https twin of an http og:image the import may not fetch', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:image" content="http://example.com/photo.jpg"><meta property="og:image:secure_url" content="https://example.com/photo.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    expect(app(OpenGraphImportAdapter::class)->preview('https://example.com/page')->imageUrl)
        ->toBe('https://example.com/photo.jpg');
});

it('takes a second og:image, in Open Graph\'s array form, when the first is one the import may not fetch', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:image" content="http://example.com/a.jpg"><meta property="og:image" content="https://example.com/b.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    expect(app(OpenGraphImportAdapter::class)->preview('https://example.com/page')->imageUrl)
        ->toBe('https://example.com/b.jpg');
});

it('gives the image candidates one lookup budget, and shows the preview without an image past it', function () {
    config(['import.timeout_seconds' => 5]);
    $images = collect(range(1, 9))->map(fn (int $n): string => "<meta property=\"og:image\" content=\"https://slow{$n}.example.net/a.jpg\">")->implode('')
        .'<meta property="og:image" content="https://example.com/fine.jpg">';
    Http::fake(['example.com/page' => Http::response("<head>{$images}</head>", 200, ['Content-Type' => 'text/html'])]);

    // Each slow host takes two seconds to fail to resolve.
    $resolver = new class implements HostResolver
    {
        /** @var list<string> */
        public array $looked = [];

        public function resolve(string $host): array
        {
            if ($host === 'example.com') {
                return ['93.184.216.34'];
            }

            $this->looked[] = $host;
            Carbon::setTestNow(now()->addSeconds(2));

            return [];
        }
    };
    app()->instance(HostResolver::class, $resolver);

    expect(app(OpenGraphImportAdapter::class)->preview('https://example.com/page')->imageUrl)->toBeNull()
        ->and($resolver->looked)->toBe(['slow1.example.net', 'slow2.example.net', 'slow3.example.net']);
});
