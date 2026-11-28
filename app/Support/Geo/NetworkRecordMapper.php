<?php

namespace App\Support\Geo;

/**
 * Reads a record of a networks' database — DB-IP ASN Lite, in GeoLite2-ASN's
 * layout — into an ASN and an organisation name; anything else reads as
 * none.
 */
final class NetworkRecordMapper
{
    /** @param  array<mixed>|null  $record */
    public static function asn(?array $record): ?int
    {
        $asn = $record['autonomous_system_number'] ?? null;

        return is_int($asn) && $asn > 0 ? $asn : null;
    }

    /** @param  array<mixed>|null  $record */
    public static function organization(?array $record): ?string
    {
        $name = $record['autonomous_system_organization'] ?? null;

        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null;
    }
}
