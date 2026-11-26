<?php

namespace App\Support\Privacy;

/**
 * A one-way hash of an IP address under the application's key: two visits or accounts from one address share it,
 * and the address can't be read back from it. A visit keeps it in place of the address (RequestSignals), an
 * account the hashes of where it signed up and where its latest visit started (RecordMemberPlaceAction).
 */
final class IpAddressHash
{
    public static function of(string $ip, string $key): string
    {
        return hash_hmac('sha256', self::canonical($ip), $key);
    }

    /**
     * The hash a search names: an IP address hashed, or a hash as such — what a link to the accounts sharing an
     * address carries while the address itself is not kept. Anything else names none.
     */
    public static function fromSearch(string $search, string $key): ?string
    {
        $search = trim($search);

        return match (true) {
            filter_var($search, FILTER_VALIDATE_IP) !== false => self::of($search, $key),
            preg_match('/^[0-9a-f]{64}$/', $search) === 1 => $search,
            default => null,
        };
    }

    /**
     * An address in one spelling, so every way of writing it hashes alike: an IPv6 address lower-case and
     * compressed (`2A02:06B8:0:0:0:0:0:1` is `2a02:6b8::1`), as the server hands a request's address over.
     * Anything that is no address stays as given.
     */
    private static function canonical(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        $spelled = $packed === false ? false : inet_ntop($packed);

        return $spelled === false ? $ip : $spelled;
    }
}
