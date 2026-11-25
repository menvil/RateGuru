<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Settings\ApplyProjectPresetAction;
use App\Actions\Settings\SaveProjectSettingsAction;
use App\Actions\Users\RecordMemberPlaceAction;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Privacy\IpAddressHash;
use Carbon\CarbonImmutable;

/*
 * Where a member signed up from and where their latest visit started — a sign-in, or a return after more than an
 * hour away — by the request's address: the country and city of the local IP database, a one-way hash of the
 * address always, and the address itself only while Project settings keep IP addresses.
 */

const PLACE_LONDON_IP = '81.2.69.142';
const PLACE_MILTON_IP = '216.160.83.56';

beforeEach(function () {
    geoDirectory();
    installGeoDatabase();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00:00'));
});

afterEach(function () {
    removeGeoDirectory($this);
});

/** The columns a member's places are kept in, as their row holds them now. */
function memberPlaces(User $member): array
{
    return $member->fresh()->only(['signup_ip', 'signup_ip_hash', 'signup_country', 'signup_city', 'visit_started_at', 'visit_ip', 'visit_ip_hash', 'visit_country', 'visit_city']);
}

it('keeps where an account was made from, its first visit starting there, and the address only while kept', function (bool $keep) {
    ProjectSettings::factory()->create(['feature_flags' => ['keep_ip_addresses' => $keep]]);

    $this->withServerVariables(['REMOTE_ADDR' => PLACE_LONDON_IP])->post('/register', screenedSignUp())->assertRedirect();

    $member = User::query()->where('email', 'new@example.com')->sole();
    $hash = IpAddressHash::of(PLACE_LONDON_IP, (string) config('app.key'));

    expect(memberPlaces($member))->toMatchArray([
        'signup_ip' => $keep ? PLACE_LONDON_IP : null,
        'signup_ip_hash' => $hash,
        'signup_country' => 'GB',
        'signup_city' => 'London',
        'visit_ip' => $keep ? PLACE_LONDON_IP : null,
        'visit_ip_hash' => $hash,
        'visit_country' => 'GB',
        'visit_city' => 'London',
    ])
        ->and($member->fresh()->visit_started_at->toDateTimeString())->toBe('2026-11-26 09:00:10');
})->with(['the address kept' => true, 'the address not kept' => false]);

it('keeps where a sign-in came from as the latest visit, leaving where the account was made', function () {
    $member = User::factory()->create(['signup_country' => 'GB', 'signup_city' => 'London']);

    $this->withServerVariables(['REMOTE_ADDR' => PLACE_MILTON_IP])
        ->post('/login', ['email' => $member->email, 'password' => 'password'] + authModalFields('login', '/'))
        ->assertRedirect();

    expect(memberPlaces($member))->toMatchArray(['signup_country' => 'GB', 'signup_city' => 'London', 'visit_country' => 'US', 'visit_city' => 'Milton'])
        ->and($member->fresh()->visit_started_at->toDateTimeString())->toBe('2026-11-26 09:00:00');
});

it('moves the latest visit to where a member comes back from after more than an hour away, not within the hour', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => PLACE_LONDON_IP])->get('/')->assertOk();

    expect(memberPlaces($member)['visit_city'])->toBe('London');

    // A page within the hour, from another address: the same visit.
    $this->travel(10)->minutes();
    $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => PLACE_MILTON_IP])->get('/')->assertOk();

    expect(memberPlaces($member)['visit_city'])->toBe('London');

    // Back the next day, the session still signed in: a new visit, from where it starts.
    $this->travel(1)->day();
    $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => PLACE_MILTON_IP])->get('/')->assertOk();

    expect(memberPlaces($member))->toMatchArray(['visit_country' => 'US', 'visit_city' => 'Milton'])
        ->and($member->fresh()->visit_started_at->toDateTimeString())->toBe('2026-11-27 09:10:00');
});

it('places nothing an address the database does not know, still keeping its hash', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->get('/')->assertOk();

    expect(memberPlaces($member))->toMatchArray(['visit_country' => null, 'visit_city' => null, 'visit_ip' => null, 'visit_ip_hash' => IpAddressHash::of('10.0.0.7', (string) config('app.key'))]);
});

it('never writes where a member is into what the account shows of itself', function () {
    $member = User::factory()->create(['signup_ip' => PLACE_LONDON_IP, 'signup_ip_hash' => 'a', 'visit_ip' => PLACE_MILTON_IP, 'visit_ip_hash' => 'b']);

    expect($member->toArray())->not->toHaveKeys(['signup_ip', 'signup_ip_hash', 'visit_ip', 'visit_ip_hash']);
});

it('forgets where an anonymised account signed up and visited from', function () {
    $member = User::factory()->create([
        'signup_ip' => PLACE_LONDON_IP, 'signup_ip_hash' => 'a', 'signup_country' => 'GB', 'signup_city' => 'London',
        'visit_started_at' => now(), 'visit_ip' => PLACE_MILTON_IP, 'visit_ip_hash' => 'b', 'visit_country' => 'US', 'visit_city' => 'Milton',
    ]);

    app(AnonymizeUserAccountAction::class)->execute($member);

    expect(array_filter(memberPlaces($member), fn (mixed $value): bool => $value !== null))->toBe([]);
});

it('never writes a place onto an account anonymised while its visit was starting', function () {
    $member = User::factory()->create();
    // The account is deleted between the visit starting and its place being written.
    app(AnonymizeUserAccountAction::class)->execute(User::query()->findOrFail($member->id));

    app(RecordMemberPlaceAction::class)->visitStarted($member, PLACE_MILTON_IP, CarbonImmutable::now());

    expect(array_filter(memberPlaces($member), fn (mixed $value): bool => $value !== null))->toBe([]);
});

it('forgets the addresses kept once Project settings stop keeping them, by the page or a preset, and keeps the rest', function (string $by) {
    ProjectSettings::factory()->create(['feature_flags' => ['keep_ip_addresses' => true]]);
    $hash = IpAddressHash::of(PLACE_LONDON_IP, (string) config('app.key'));
    $member = User::factory()->create(['signup_ip' => PLACE_LONDON_IP, 'signup_ip_hash' => $hash, 'signup_country' => 'GB', 'signup_city' => 'London', 'visit_ip' => PLACE_LONDON_IP, 'visit_ip_hash' => $hash]);

    // Saved with keeping still on: nothing forgotten.
    app(SaveProjectSettingsAction::class)->handle(['feature_flags' => ['keep_ip_addresses' => true]]);

    expect(memberPlaces($member)['signup_ip'])->toBe(PLACE_LONDON_IP);

    match ($by) {
        'page' => app(SaveProjectSettingsAction::class)->handle(['feature_flags' => ['keep_ip_addresses' => false]]),
        'preset' => app(ApplyProjectPresetAction::class)->handle('generic', force: true),
    };

    expect(memberPlaces($member))->toMatchArray(['signup_ip' => null, 'signup_ip_hash' => $hash, 'signup_country' => 'GB', 'signup_city' => 'London', 'visit_ip' => null, 'visit_ip_hash' => $hash]);
})->with(['switched off on the page' => 'page', 'a preset applied' => 'preset']);
