<?php

use App\Enums\GeoUpdateFailure;
use App\Support\Geo\GeoLookup;
use App\Support\Geo\Update\GzipInflater;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * geo:update keeps the IP location database current: it finds the newest
 * build, downloads it by its exact version, holds it to its checksum, unpacks
 * it, checks it with the reader and puts it in service without a gap — and
 * leaves the serving database alone whenever any of that fails. MaxMind's
 * 16 KB test database stands in for a real build; nothing goes to the network.
 */
const GEO_FIXTURE = 'tests/Fixtures/geo/GeoLite2-City-Test.mmdb';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 10:00:00');
    Http::preventStrayRequests();
    // One fake for the whole test that answers from geoServes(): a second
    // Http::fake() would only add stubs behind the first ones.
    Http::fake(function (Request $request) {
        foreach (test()->geoRoutes ?? [] as $pattern => $answer) {
            if (Str::is('*'.$pattern, $request->url())) {
                return is_callable($answer) ? $answer($request) : $answer;
            }
        }

        return Http::response('', 404);
    });
    geoDirectory();
    config([
        'geo.probes' => ['81.2.69.142' => 'GB', '216.160.83.56' => 'US', '2001:218::' => 'JP'],
        'geo.probes_required' => 3,
        'geo.editions.geolite2_city.min_database_bytes' => 1024,
        'geo.editions.dbip_city_lite.min_database_bytes' => 1024,
        'geo.editions.dbip_city_lite.database_types' => ['GeoLite2-City'],
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    removeGeoDirectory($this);
});

/** @param  array<string, mixed>  $routes  URL pattern => response, or a callable given the request */
function geoServes(array $routes): void
{
    test()->geoRoutes = $routes;
}

/** The npm package of a build: package/GeoLite2-City.mmdb.gz holding the given database. */
function geoPackage(string $database): string
{
    return gzencode(tarOf(['package/README.md' => 'readme', 'package/GeoLite2-City.mmdb.gz' => gzencode($database)]));
}

/** @return array<string, mixed> */
function fakeNpm(string $version, string $package, ?string $integrity = null): array
{
    return [
        'registry.npmjs.org/geolite2-city/latest' => Http::response(['version' => $version, 'dist' => ['integrity' => $integrity ?? 'sha512-'.base64_encode(hash('sha512', $package, true))]]),
        'registry.npmjs.org/geolite2-city/-/geolite2-city-'.$version.'.tgz' => Http::response($package),
    ];
}

function geoState(string $edition = 'geolite2_city'): array
{
    return json_decode((string) file_get_contents(test()->geoDirectory.'/'.$edition.'/state.json'), true);
}

it('installs the newest build from npm and serves it at once', function () {
    $database = file_get_contents(base_path(GEO_FIXTURE));
    geoServes(fakeNpm('1.0.116', geoPackage($database)));

    $this->artisan('geo:update')->assertSuccessful();

    $state = geoState();
    expect(file_get_contents($this->geoDirectory.'/geolite2_city/current.mmdb'))->toBe($database)
        ->and($state['installed'])->toMatchArray(['source' => 'npm', 'version' => '1.0.116', 'database_type' => 'GeoLite2-City', 'sha256' => hash('sha256', $database)])
        ->and($state['last_result'])->toBe('updated')
        ->and($state['failures'])->toBe(0)
        ->and($state['next_check_at'])->toBe('2026-11-18T10:00:00+00:00')
        ->and(glob($this->geoDirectory.'/geolite2_city/*.part'))->toBe([])
        ->and(app(GeoLookup::class)->lookup('81.2.69.142')->countryCode)->toBe('GB');
});

it('downloads nothing while the next check has not come, nor when the source still offers the installed build', function () {
    geoServes(fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))));
    $this->artisan('geo:update')->assertSuccessful();
    Http::assertSentCount(2);

    $this->artisan('geo:update')->assertSuccessful();
    Http::assertSentCount(2);

    CarbonImmutable::setTestNow('2026-11-18 10:00:00');
    $this->artisan('geo:update')->assertSuccessful();

    Http::assertSentCount(3);
    expect(geoState()['last_result'])->toBe('current');
});

it('tries the next source when the first does not answer', function () {
    $gz = gzencode(file_get_contents(base_path(GEO_FIXTURE)));
    geoServes([
        'registry.npmjs.org/*' => Http::response('', 503),
        'data.jsdelivr.com/v1/packages/npm/geolite2-city/resolved*' => Http::response(['version' => '1.0.116']),
        'data.jsdelivr.com/v1/packages/npm/geolite2-city@1.0.116*' => Http::response(['files' => [['name' => '/GeoLite2-City.mmdb.gz', 'hash' => base64_encode(hash('sha256', $gz, true)), 'size' => strlen($gz)]]]),
        'cdn.jsdelivr.net/npm/geolite2-city@1.0.116/GeoLite2-City.mmdb.gz' => Http::response($gz),
    ]);

    $this->artisan('geo:update')->assertSuccessful();

    expect(geoState()['installed'])->toMatchArray(['source' => 'jsdelivr', 'version' => '1.0.116']);
});

it('leaves the serving database alone when a build fails a check, and tries again later', function (callable $fake, GeoUpdateFailure $failure) {
    installGeoDatabase();
    $serving = file_get_contents($this->geoDirectory.'/geolite2_city/current.mmdb');
    config(['geo.editions.geolite2_city.sources' => ['npm']]);
    geoServes($fake());

    $this->artisan('geo:update', ['--force' => true])->assertFailed();

    $state = geoState();
    expect(file_get_contents($this->geoDirectory.'/geolite2_city/current.mmdb'))->toBe($serving)
        ->and($state['last_error']['code'])->toBe($failure->value)
        ->and($state['failures'])->toBe(1)
        ->and($state['next_check_at'])->toBe('2026-11-17T10:15:00+00:00')
        ->and(glob($this->geoDirectory.'/geolite2_city/*.part'))->toBe([]);
})->with([
    'a wrong checksum' => [fn () => fakeNpm('1.0.117', geoPackage(file_get_contents(base_path(GEO_FIXTURE))), 'sha512-'.base64_encode(hash('sha512', 'other', true))), GeoUpdateFailure::ChecksumMismatch],
    'a package without the database' => [fn () => fakeNpm('1.0.117', $p = gzencode(tarOf(['package/README.md' => 'x']))), GeoUpdateFailure::ArchiveInvalid],
    'a database that is not one' => [fn () => fakeNpm('1.0.117', geoPackage(str_repeat('x', 4096))), GeoUpdateFailure::MetadataInvalid],
    'an unanswered registry' => [fn () => ['registry.npmjs.org/*' => Http::response('', 500)], GeoUpdateFailure::DiscoveryFailed],
]);

it('keeps a build that misplaces well-known addresses out of service', function () {
    config(['geo.probes' => ['81.2.69.142' => 'FR', '216.160.83.56' => 'CA', '2001:218::' => 'JP'], 'geo.editions.geolite2_city.sources' => ['npm']]);
    geoServes(fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))));

    $this->artisan('geo:update')->assertFailed();

    expect(geoState()['last_error']['code'])->toBe('probe_mismatch')
        ->and(is_file($this->geoDirectory.'/geolite2_city/current.mmdb'))->toBeFalse();
});

it('keeps the database a new build replaced, and can put it back', function () {
    $database = file_get_contents(base_path(GEO_FIXTURE));
    geoServes(fakeNpm('1.0.116', geoPackage($database)));
    $this->artisan('geo:update')->assertSuccessful();

    geoServes(fakeNpm('1.0.117', geoPackage($database)));
    $this->artisan('geo:update', ['--force' => true])->assertSuccessful();

    expect(geoState()['installed']['version'])->toBe('1.0.117')
        ->and(geoState()['previous'])->toMatchArray(['version' => '1.0.116', 'kept_until' => '2026-11-24T10:00:00+00:00'])
        ->and(is_file($this->geoDirectory.'/geolite2_city/previous.mmdb'))->toBeTrue();

    $this->artisan('geo:update', ['--rollback' => true])->assertSuccessful();

    expect(geoState()['installed']['version'])->toBe('1.0.116')
        ->and(geoState()['skip_version'])->toBe('1.0.117')
        ->and(is_file($this->geoDirectory.'/geolite2_city/previous.mmdb'))->toBeFalse();

    $this->artisan('geo:update', ['--force' => true])->assertSuccessful();
    expect(geoState()['installed']['version'])->toBe('1.0.116');
});

it('takes a GeoLite2 build out of service after 30 days, and DB-IP stands in until a fresh one comes', function () {
    $database = file_get_contents(base_path(GEO_FIXTURE));
    geoServes(fakeNpm('1.0.116', geoPackage($database)));
    $this->artisan('geo:update')->assertSuccessful();

    // The fixture was built on 2026-10-22: 30 days later its licence age is over.
    CarbonImmutable::setTestNow('2026-11-22 12:00:00');
    config(['geo.editions.dbip_city_lite.max_build_age_days' => null]);
    $gz = gzencode($database);
    geoServes([
        ...fakeNpm('1.0.116', geoPackage($database)),
        'download.db-ip.com/free/dbip-city-lite-2026-11.mmdb.gz' => fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 200, ['Content-Length' => (string) strlen($gz), 'ETag' => '"abc"'])
            : Http::response($gz),
    ]);

    $this->artisan('geo:update')->assertFailed();

    expect(geoState()['last_result'])->toBe('failed')
        ->and(geoState()['last_error']['code'])->toBe('build_too_old')
        ->and(is_file($this->geoDirectory.'/geolite2_city/current.mmdb'))->toBeFalse()
        ->and(geoState('dbip_city_lite')['installed']['source'])->toBe('dbip')
        ->and(app(GeoLookup::class)->lookup('81.2.69.142')->countryCode)->toBe('GB');
});

it('says so, and downloads nothing, when PHP has no zlib', function () {
    app()->instance(GzipInflater::class, new GzipInflater(fn () => false));
    config(['geo.editions.dbip_city_lite.sources' => []]);

    $this->artisan('geo:update')->assertFailed();

    expect(geoState()['last_error']['code'])->toBe('zlib_missing');
    Http::assertNothingSent();
});

it('changes nothing at the two levels the host checks once the first run made its directories', function () {
    geoServes(fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))));
    $this->artisan('geo:update')->assertSuccessful();
    $listing = fn (): array => collect(File::allFiles($this->geoDirectory, true))->map->getRelativePathname()
        ->merge(collect(File::directories($this->geoDirectory))->map(fn (string $d): string => basename($d)))
        ->filter(fn (string $path): bool => substr_count($path, '/') < 1)->sort()->values()->all();
    $before = $listing();

    geoServes(fakeNpm('1.0.117', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))));
    $this->artisan('geo:update', ['--force' => true])->assertSuccessful();

    expect($listing())->toBe($before)->toBe(['dbip_city_lite', 'geolite2_city']);
});

it('runs every minute in the background, without overlapping for more than an hour, and reads no environment variable', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'geo:update'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(60)
        ->and($events->first()->runInBackground)->toBeTrue()
        ->and(file_get_contents(config_path('geo.php')))->not->toContain('env(');
});
