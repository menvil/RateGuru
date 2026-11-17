<?php

use App\Support\Geo\GeoRecordMapper;

it('reads a GeoLite2 city record', function () {
    $location = GeoRecordMapper::map([
        'country' => ['iso_code' => 'GB', 'names' => ['en' => 'United Kingdom']],
        'subdivisions' => [['iso_code' => 'ENG'], ['iso_code' => 'WBK']],
        'city' => ['names' => ['en' => 'London', 'ru' => 'Лондон']],
        'location' => ['time_zone' => 'Europe/London'],
    ]);

    expect($location->countryCode)->toBe('GB')
        ->and($location->countryName)->toBe('United Kingdom')
        ->and($location->region)->toBe('ENG')
        ->and($location->city)->toBe('London')
        ->and($location->timeZone)->toBe('Europe/London')
        ->and($location->isKnown())->toBeTrue();
});

it('reads a DB-IP city record, without the district in brackets and with no time zone', function () {
    $location = GeoRecordMapper::map([
        'country' => ['iso_code' => 'RU'],
        'subdivisions' => [['iso_code' => 'mow']],
        'city' => ['names' => ['en' => 'Moscow (Tsentralnyy administrativnyy okrug)']],
        'location' => ['latitude' => 55.75, 'longitude' => 37.61],
    ]);

    expect($location->countryCode)->toBe('RU')
        ->and($location->region)->toBe('MOW')
        ->and($location->city)->toBe('Moscow')
        ->and($location->timeZone)->toBeNull();
});

it('keeps a city name to 80 characters', function () {
    $location = GeoRecordMapper::map(['country' => ['iso_code' => 'US'], 'city' => ['names' => ['en' => str_repeat('a', 120)]]]);

    expect(mb_strlen((string) $location->city))->toBe(80);
});

it('knows nothing of a record without a country it lists', function (?array $record) {
    $location = GeoRecordMapper::map($record);

    expect($location->isKnown())->toBeFalse()
        ->and($location->city)->toBeNull()
        ->and($location->timeZone)->toBeNull();
})->with([
    'no record' => [null],
    'no country' => [['city' => ['names' => ['en' => 'Somewhere']], 'location' => ['time_zone' => 'UTC']]],
    'an unknown code' => [['country' => ['iso_code' => 'ZZ'], 'city' => ['names' => ['en' => 'Somewhere']]]],
    'a code that is not a string' => [['country' => ['iso_code' => 12]]],
]);
