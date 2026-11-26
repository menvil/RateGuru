<?php

namespace App\Actions\Users;

use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Geo\GeoLookup;
use App\Support\Privacy\IpAddressHash;
use App\Support\Settings\ProjectSettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where a member is, by the address of the request: when the account is made, where it was made from, and each
 * time a visit starts — a sign-in, or a return after more than an hour away — where the latest one started from
 * and when. The country and city the local IP database places the address in, a one-way hash of it that finds
 * the accounts sharing an address, and the address itself only while Project settings keep IP addresses.
 *
 * One statement on the member's row, without a new updated_at, never over a later visit — and, while an address is
 * kept, in step with a save of the settings that stops keeping them, so none read as kept is left behind.
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
        $written = ($values['signup_ip'] ?? $values['visit_ip'] ?? null) !== null
            ? DB::transaction(function () use ($user, &$values): int {
                // An address is written in step with a save of the settings that stops keeping addresses: read under
                // a shared lock, the settings row waits for such a save to commit, and such a save waits for this
                // write to commit and then forgets the address with the others (ForgetKeptIpAddressesAction).
                $row = ProjectSettings::query()->whereKey(1)->sharedLock()->first();

                if (! ($row instanceof ProjectSettings && ForgetKeptIpAddressesAction::kept($row))) {
                    $values = array_replace($values, array_fill_keys(array_intersect(['signup_ip', 'visit_ip'], array_keys($values)), null));
                }

                return $this->update($user, $values);
            })
            : $this->update($user, $values);

        if ($written === 0) {
            return;
        }

        // The rest of the request reads the member as written, and a later save never writes it again.
        $user->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /** @param  array<string, mixed>  $values */
    private function update(User $user, array $values): int
    {
        // Never onto an account anonymised meanwhile — a deletion committed between the visit starting and this
        // write: its places went with it, and nothing would clear them again.
        $query = User::query()->whereKey($user->id)->whereNull('anonymized_at');

        // Nor over a later visit: two visits starting at once can finish writing in either order.
        if (isset($values['visit_started_at'])) {
            $query->where(fn (Builder $stored) => $stored->whereNull('visit_started_at')->orWhere('visit_started_at', '<=', $values['visit_started_at']));
        }

        return User::withoutTimestamps(fn (): int => $query->update($values));
    }
}
