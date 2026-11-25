<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Geo\GeoLookup;
use App\Support\Privacy\IpAddressHash;
use App\Support\Settings\ProjectSettingsManager;
use Carbon\CarbonImmutable;

/**
 * Where a member is, by the address of the request: when the account is made, where it was made from, and each
 * time a visit starts — a sign-in, or a return after more than an hour away — where the latest one started from
 * and when. The country and city the local IP database places the address in, a one-way hash of it that finds
 * the accounts sharing an address, and the address itself only while Project settings keep IP addresses.
 *
 * One statement on the member's row, outside any transaction and without a lock or a new updated_at: the latest
 * write is the latest place.
 */
final class RecordMemberPlaceAction
{
    public function __construct(private readonly ProjectSettingsManager $settings) {}

    /** The account was just made: where from, which is where its first visit started too. */
    public function signedUp(User $user, ?string $ip, CarbonImmutable $now): void
    {
        $place = $this->place($ip);

        $this->write($user, [
            'signup_ip' => $place['ip'],
            'signup_ip_hash' => $place['hash'],
            'signup_country' => $place['country'],
            'signup_city' => $place['city'],
            ...self::visit($place, $now),
        ]);
    }

    /** A visit of the member's started: a sign-in, or a return after more than an hour away. */
    public function visitStarted(User $user, ?string $ip, CarbonImmutable $now): void
    {
        $this->write($user, self::visit($this->place($ip), $now));
    }

    /**
     * @param  array{ip: ?string, hash: ?string, country: ?string, city: ?string}  $place
     * @return array<string, mixed>
     */
    private static function visit(array $place, CarbonImmutable $now): array
    {
        return [
            'visit_started_at' => $now,
            'visit_ip' => $place['ip'],
            'visit_ip_hash' => $place['hash'],
            'visit_country' => $place['country'],
            'visit_city' => $place['city'],
        ];
    }

    /** @return array{ip: ?string, hash: ?string, country: ?string, city: ?string} */
    private function place(?string $ip): array
    {
        $ip = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;

        if ($ip === null) {
            return ['ip' => null, 'hash' => null, 'country' => null, 'city' => null];
        }

        // Resolved only once there is an address to place: building the lookup reads the traffic settings, which a
        // page nobody signed in on — where TrackMemberVisit still builds this action — should never query.
        $location = app(GeoLookup::class)->lookup($ip);

        return [
            'ip' => $this->settings->featureEnabled('keep_ip_addresses') ? $ip : null,
            'hash' => IpAddressHash::of($ip, (string) config('app.key')),
            'country' => $location->countryCode,
            'city' => $location->city === null ? null : mb_substr($location->city, 0, 80),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function write(User $user, array $values): void
    {
        User::withoutTimestamps(fn (): int => User::query()->whereKey($user->id)->update($values));

        // The rest of the request reads the member as written, and a later save never writes it again.
        $user->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }
}
