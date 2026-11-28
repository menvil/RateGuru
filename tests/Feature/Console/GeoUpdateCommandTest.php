<?php

use App\Enums\GeoUpdateFailure;
use App\Support\Geo\GeoLookup;
use App\Support\Geo\Update\GzipInflater;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
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

/**
 * Holds an edition's update lock, as a run in another process would: flock
 * keeps two open descriptions of one file apart even within one process.
 *
 * @return resource
 */
function holdGeoUpdateLock(string $edition = 'geolite2_city')
{
    File::ensureDirectoryExists(test()->geoDirectory.'/'.$edition);
    $lock = fopen(test()->geoDirectory.'/'.$edition.'/update.lock', 'c');
    flock($lock, LOCK_EX);

    return $lock;
}

/** @param  resource  $lock */
function releaseGeoUpdateLock($lock): void
{
    flock($lock, LOCK_UN);
    fclose($lock);
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
    // The city database's own calls: the networks' database and lists keep schedules of their own.
    $cityCalls = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'geolite2-city'))->count();
    $this->artisan('geo:update')->assertSuccessful();
    expect($cityCalls())->toBe(2);

    $this->artisan('geo:update')->assertSuccessful();
    expect($cityCalls())->toBe(2);

    CarbonImmutable::setTestNow('2026-11-18 10:00:00');
    $this->artisan('geo:update')->assertSuccessful();

    expect($cityCalls())->toBe(3)
        ->and(geoState()['last_result'])->toBe('current');
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
    'a registry that cannot be reached' => [fn () => ['registry.npmjs.org/*' => Http::failedConnection()], GeoUpdateFailure::DiscoveryFailed],
    'a download announced past the size cap' => [function () {
        // A fake response never runs Guzzle's on_headers, so the headers of
        // a build larger than the cap are handed to it here, as cURL would.
        Http::globalMiddleware(fn (callable $handler) => function ($request, array $options) use ($handler) {
            if (isset($options['on_headers'])) {
                $options['on_headers'](new GuzzleResponse(200, ['Content-Length' => (string) (config('geo.editions.geolite2_city.max_archive_bytes') + 1)]));
            }

            return $handler($request, $options);
        });

        return fakeNpm('1.0.117', geoPackage(file_get_contents(base_path(GEO_FIXTURE))));
    }, GeoUpdateFailure::TooLarge],
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

    // An update holding the lock may be swapping these very files: the rollback waits for it.
    $files = fn (): array => array_map(fn (string $name): string|false => @file_get_contents($this->geoDirectory.'/geolite2_city/'.$name), ['current.mmdb', 'previous.mmdb', 'state.json']);
    $before = $files();
    $lock = holdGeoUpdateLock();

    $this->artisan('geo:update', ['--rollback' => true])
        ->expectsOutputToContain('An update of the GeoLite2 City database is running; try again when it finishes.')
        ->assertFailed();

    expect($files())->toBe($before);
    releaseGeoUpdateLock($lock);

    $this->artisan('geo:update', ['--rollback' => true])->assertSuccessful();

    expect(geoState()['installed']['version'])->toBe('1.0.116')
        ->and(geoState()['skip_version'])->toBe('1.0.117')
        ->and(is_file($this->geoDirectory.'/geolite2_city/previous.mmdb'))->toBeFalse();

    $this->artisan('geo:update', ['--force' => true])->assertSuccessful();
    expect(geoState()['installed']['version'])->toBe('1.0.116');
});

it('reads the state and drops an expired previous database only under the update lock', function () {
    $database = installGeoDatabase();
    copy($database, $this->geoDirectory.'/geolite2_city/previous.mmdb');
    file_put_contents($this->geoDirectory.'/geolite2_city/state.json', json_encode([
        'installed' => ['source' => 'npm', 'version' => '1.0.116', 'size' => filesize($database), 'sha256' => hash_file('sha256', $database)],
        'previous' => ['source' => 'npm', 'version' => '1.0.115', 'kept_until' => '2026-11-16T10:00:00+00:00'],
        'last_attempt_at' => '2026-11-17T09:00:00+00:00',
        'next_check_at' => '2026-11-18T09:00:00+00:00',
    ]));
    // A run holding the lock may be installing a build whose previous copy is this file.
    $lock = holdGeoUpdateLock();

    $this->artisan('geo:update')->assertSuccessful();

    expect(is_file($this->geoDirectory.'/geolite2_city/previous.mmdb'))->toBeTrue()
        ->and(geoState()['previous'])->toMatchArray(['version' => '1.0.115']);

    releaseGeoUpdateLock($lock);
    $this->artisan('geo:update')->assertSuccessful();

    expect(is_file($this->geoDirectory.'/geolite2_city/previous.mmdb'))->toBeFalse()
        ->and(geoState()['previous'])->toBeNull();
    Http::assertNothingSent();
});

it('leaves the other edition\'s files alone while a run of it holds its lock, and deletes them on the next run', function () {
    geoServes(fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))));
    $standIn = installGeoDatabase('dbip_city_lite');
    $lock = holdGeoUpdateLock('dbip_city_lite');

    $this->artisan('geo:update')->assertSuccessful();

    expect(geoState()['last_result'])->toBe('updated')
        ->and(is_file($standIn))->toBeTrue();

    releaseGeoUpdateLock($lock);
    $this->artisan('geo:update')->assertSuccessful();

    expect(is_file($standIn))->toBeFalse()
        ->and(geoState('dbip_city_lite')['last_result'])->toBe('discarded');
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

    expect($listing())->toBe($before)->toBe(['dbip_asn_lite', 'dbip_city_lite', 'geolite2_city', 'networks']);
});

it('installs the networks’ database from DB-IP beside the city one, held to network probes of its own, and keeps it when a build names the wrong networks', function () {
    $asn = file_get_contents(base_path('tests/Fixtures/geo/GeoLite2-ASN-Test.mmdb'));
    config([
        'geo.editions.dbip_asn_lite.min_database_bytes' => 1024,
        'geo.editions.dbip_asn_lite.database_types' => ['GeoLite2-ASN'],
        'geo.editions.dbip_asn_lite.probes' => ['1.128.0.0' => 1221, '12.81.92.0' => 7018, '2600:6000::' => 237],
    ]);
    $gz = gzencode($asn);
    geoServes([
        ...fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))),
        'download.db-ip.com/free/dbip-asn-lite-2026-11.mmdb.gz' => Http::response($gz, 200, ['Content-Length' => (string) strlen($gz), 'ETag' => '"asn-1"']),
    ]);

    $this->artisan('geo:update')->expectsOutputToContain('DB-IP ASN Lite: updated.')->assertSuccessful();

    expect(geoState('dbip_asn_lite')['installed'])->toMatchArray(['source' => 'dbip'])
        ->and(hash_file('sha256', $this->geoDirectory.'/dbip_asn_lite/current.mmdb'))->toBe(hash('sha256', $asn));

    // A newer build whose answers name other networks never replaces it.
    config(['geo.editions.dbip_asn_lite.probes' => ['1.128.0.0' => 15169, '12.81.92.0' => 13335, '2600:6000::' => 19281]]);
    geoServes([
        ...fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))),
        'download.db-ip.com/free/dbip-asn-lite-2026-11.mmdb.gz' => Http::response($gz, 200, ['Content-Length' => (string) strlen($gz), 'ETag' => '"asn-2"']),
    ]);

    $this->artisan('geo:update', ['--force' => true])->expectsOutputToContain('DB-IP ASN Lite: failed.')->assertSuccessful();

    expect(geoState('dbip_asn_lite')['last_error']['code'])->toBe(GeoUpdateFailure::ProbeMismatch->value)
        ->and(geoState('dbip_asn_lite')['installed']['version'])->toBe('2026-11:asn-1')
        ->and(is_file($this->geoDirectory.'/dbip_asn_lite/current.mmdb'))->toBeTrue();
});

it('keeps the network lists: the hosting and VPN networks of every list joined, Private Relay’s ranges merged, each on its own schedule', function () {
    config(['geo.network_lists.hosting.min_entries' => 3, 'geo.network_lists.relay.min_entries' => 2]);
    geoServes([
        ...fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))),
        'X4BNet/lists_vpn/main/input/datacenter/ASN.txt' => Http::response("AS45090 # Tencent\nAS3223 # VOXILITY\n"),
        'X4BNet/lists_vpn/main/input/vpn/ASN.txt' => Http::response("AS9009 # M247 (NordVPN)\nAS3223 # twice\n"),
        'mask-api.icloud.com/egress-ip-ranges.csv' => Http::response("172.224.226.0/27,GB,GB-EN,London,\n172.224.226.32/31,GB,GB-SC,Aberdeen,\n2a02:26f7:b3c0:4000::/64,GB,GB-EN,London,\n"),
    ]);

    $this->artisan('geo:update')->expectsOutputToContain('Hosting and VPN networks: updated.')->expectsOutputToContain('iCloud Private Relay addresses: updated.')->assertSuccessful();

    $state = fn (string $list): array => json_decode((string) file_get_contents($this->geoDirectory.'/networks/'.$list.'.json'), true);
    expect(file_get_contents($this->geoDirectory.'/networks/hosting.list'))->toBe("3223\n9009\n45090\n")
        ->and($state('hosting')['installed']['entries'])->toBe(3)
        ->and($state('relay')['installed']['entries'])->toBe(2)
        ->and(filesize($this->geoDirectory.'/networks/relay.list'))->toBe(64)
        ->and($state('hosting')['next_check_at'])->toBe('2026-11-18T10:00:00+00:00')
        ->and($state('relay')['next_check_at'])->toBe('2026-11-24T10:00:00+00:00');

    // Nothing is fetched again before its next check.
    $listCalls = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'X4BNet') || str_contains($request->url(), 'icloud'))->count();
    $before = $listCalls();
    $this->artisan('geo:update')->assertSuccessful();
    expect($listCalls())->toBe($before);
});

it('keeps the network list in service when a fetched one is short, unreadable or not there, and tries again later', function (callable $answer, GeoUpdateFailure $failure) {
    config(['geo.network_lists.hosting.min_entries' => 2]);
    geoServes([
        ...fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))),
        'X4BNet/lists_vpn/main/input/*' => Http::response("AS45090\nAS3223\n"),
    ]);
    $this->artisan('geo:update')->assertSuccessful();

    CarbonImmutable::setTestNow('2026-11-18 10:00:00');
    geoServes([
        ...fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))),
        'X4BNet/lists_vpn/main/input/*' => $answer,
    ]);
    $this->artisan('geo:update')->expectsOutputToContain('Hosting and VPN networks: failed.')->assertSuccessful();

    $state = json_decode((string) file_get_contents($this->geoDirectory.'/networks/hosting.json'), true);
    expect(file_get_contents($this->geoDirectory.'/networks/hosting.list'))->toBe("3223\n45090\n")
        ->and($state['failures'])->toBe(1)
        ->and($state['last_error']['code'])->toBe($failure->value)
        ->and($state['next_check_at'])->toBe('2026-11-18T10:15:00+00:00')
        ->and(glob($this->geoDirectory.'/networks/*.part'))->toBe([]);
})->with([
    'short' => [fn () => fn () => Http::response("AS45090\n"), GeoUpdateFailure::ListInvalid],
    'no ASN in it' => [fn () => fn () => Http::response('<html>not a list</html>'), GeoUpdateFailure::ListInvalid],
    'not there' => [fn () => fn () => Http::response('', 404), GeoUpdateFailure::DownloadFailed],
    // Distinct ASNs past the 2 MB cap: only the cap refuses it.
    'too large' => [fn () => fn () => Http::response(implode('', array_map(fn (int $asn): string => 'AS'.$asn.' # a hosting network'."\n", range(1, 120000)))), GeoUpdateFailure::TooLarge],
]);

it('waits after a first run that died fetching a list not installed yet, instead of fetching it again a minute later', function () {
    // What a run killed during its fetch leaves behind: the attempt and its next try, no list, no failure counted.
    File::ensureDirectoryExists($this->geoDirectory.'/networks');
    file_put_contents($this->geoDirectory.'/networks/relay.json', json_encode(['last_attempt_at' => '2026-11-17T09:59:00+00:00', 'next_check_at' => '2026-11-17T10:14:00+00:00']));
    geoServes([
        ...fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))),
        'mask-api.icloud.com/egress-ip-ranges.csv' => Http::response("172.224.226.0/27,GB\n"),
    ]);
    $relayCalls = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'icloud'))->count();

    $this->artisan('geo:update')->assertSuccessful();
    expect($relayCalls())->toBe(0);

    CarbonImmutable::setTestNow('2026-11-17 10:14:00');
    $this->artisan('geo:update')->assertSuccessful();
    expect($relayCalls())->toBe(1);
});

it('records a network list’s next try before it fetches, so a run that dies on the way waits instead of fetching again a minute later', function () {
    $seen = null;
    geoServes([
        ...fakeNpm('1.0.116', geoPackage(file_get_contents(base_path(GEO_FIXTURE)))),
        'mask-api.icloud.com/egress-ip-ranges.csv' => function () use (&$seen) {
            $seen = json_decode((string) file_get_contents(test()->geoDirectory.'/networks/relay.json'), true);

            return Http::response('', 503);
        },
    ]);

    $this->artisan('geo:update')->assertSuccessful();

    expect($seen)->toMatchArray(['last_attempt_at' => '2026-11-17T10:00:00+00:00', 'next_check_at' => '2026-11-17T10:15:00+00:00']);
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
