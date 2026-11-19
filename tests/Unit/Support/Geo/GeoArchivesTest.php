<?php

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use App\Support\Geo\Update\GzipInflater;
use App\Support\Geo\Update\TarMemberExtractor;

beforeEach(function () {
    $this->dir = makeScratchDir('rateguru-geo-archives');
});

afterEach(function () {
    removeScratchDir($this->dir);
});

function geoFailure(callable $call): ?GeoUpdateFailure
{
    try {
        $call();
    } catch (GeoUpdateException $exception) {
        return $exception->failure;
    }

    return null;
}

it('unpacks a gzip file whole', function () {
    $contents = random_bytes(3 * 1024 * 1024 + 17);
    file_put_contents($this->dir.'/in.gz', gzencode($contents));

    $written = (new GzipInflater)->inflate($this->dir.'/in.gz', $this->dir.'/out', 4 * 1024 * 1024);

    expect($written)->toBe(strlen($contents))
        ->and(file_get_contents($this->dir.'/out'))->toBe($contents);
});

it('refuses a gzip stream cut short, a file that is not gzip, and one that unpacks past the limit', function () {
    $packed = gzencode(random_bytes(200_000));
    file_put_contents($this->dir.'/cut.gz', substr($packed, 0, intdiv(strlen($packed), 2)));
    file_put_contents($this->dir.'/plain.gz', 'not gzip at all');
    file_put_contents($this->dir.'/big.gz', gzencode(str_repeat('a', 2_000_000)));
    $gzip = new GzipInflater;

    expect(geoFailure(fn () => $gzip->inflate($this->dir.'/cut.gz', $this->dir.'/out', 1_000_000)))->toBe(GeoUpdateFailure::UnpackFailed)
        ->and(geoFailure(fn () => $gzip->inflate($this->dir.'/plain.gz', $this->dir.'/out', 1_000_000)))->toBe(GeoUpdateFailure::UnpackFailed)
        ->and(geoFailure(fn () => $gzip->inflate($this->dir.'/big.gz', $this->dir.'/out', 1_000_000)))->toBe(GeoUpdateFailure::TooLarge);
});

it('refuses a gzip bomb past the limit without unpacking it all into memory first', function () {
    // 256 MiB of zeros packs into about 250 KiB: one read of the old 1 MiB
    // step would hold every one of those bytes unpacked before the limit
    // was ever compared.
    $bomb = gzopen($this->dir.'/bomb.gz', 'wb9');
    $block = str_repeat("\0", 1024 * 1024);

    for ($i = 0; $i < 256; $i++) {
        gzwrite($bomb, $block);
    }

    gzclose($bomb);
    unset($block);
    memory_reset_peak_usage();
    $before = memory_get_usage();

    expect(geoFailure(fn () => (new GzipInflater)->inflate($this->dir.'/bomb.gz', $this->dir.'/out', 1024 * 1024)))->toBe(GeoUpdateFailure::TooLarge)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(48 * 1024 * 1024);
});

it('says so when PHP has no zlib, without touching the file', function () {
    file_put_contents($this->dir.'/in.gz', gzencode('x'));

    expect(geoFailure(fn () => (new GzipInflater(fn () => false))->inflate($this->dir.'/in.gz', $this->dir.'/out', 10)))->toBe(GeoUpdateFailure::ZlibMissing)
        ->and(is_file($this->dir.'/out'))->toBeFalse();
});

it('takes the named file out of a package and nothing else', function () {
    file_put_contents($this->dir.'/package.tar', tarOf([
        'package/README.md' => str_repeat('r', 700),
        'package/GeoLite2-City.mmdb.gz' => $wanted = random_bytes(1500),
        'package/NOTICE' => 'n',
    ]));

    $size = (new TarMemberExtractor)->extract($this->dir.'/package.tar', 'package/GeoLite2-City.mmdb.gz', $this->dir.'/out', 10_000);

    expect($size)->toBe(1500)->and(file_get_contents($this->dir.'/out'))->toBe($wanted);
});

it('refuses a package without the file, and one whose file is past the limit', function () {
    file_put_contents($this->dir.'/package.tar', tarOf(['package/other.mmdb.gz' => 'x', 'package/GeoLite2-City.mmdb.gz' => str_repeat('y', 3000)]));
    $tar = new TarMemberExtractor;

    expect(geoFailure(fn () => $tar->extract($this->dir.'/package.tar', 'package/missing.gz', $this->dir.'/out', 10_000)))->toBe(GeoUpdateFailure::ArchiveInvalid)
        ->and(geoFailure(fn () => $tar->extract($this->dir.'/package.tar', 'package/GeoLite2-City.mmdb.gz', $this->dir.'/out', 1000)))->toBe(GeoUpdateFailure::TooLarge);
});
