<?php

namespace App\Support\Geo\Update;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use App\Support\Geo\GeoRecordMapper;
use Carbon\CarbonImmutable;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Checks an unpacked file before it serves: the size a real build has, a
 * readable MaxMind DB of the expected type, IPv6-capable, binary format 2, a
 * build no older than the installed one, not dated in the future and — for
 * an edition with a licence age — young enough, and enough of the probe
 * addresses placed in their countries through the same mapping the site uses.
 */
final class GeoDatabaseValidator
{
    /**
     * @param  array<string, string>  $probes
     * @return array{database_type: string, build_epoch: int}
     */
    public function check(
        string $path,
        GeoEditionSpec $spec,
        ?int $installedBuildEpoch,
        CarbonImmutable $now,
        array $probes,
        int $probesRequired,
    ): array {
        $size = (int) @filesize($path);

        if ($size < $spec->minDatabaseBytes || $size > $spec->maxDatabaseBytes) {
            throw GeoUpdateException::because($size > $spec->maxDatabaseBytes ? GeoUpdateFailure::TooLarge : GeoUpdateFailure::MetadataInvalid);
        }

        try {
            $reader = new Reader($path);
        } catch (Throwable) {
            throw GeoUpdateException::because(GeoUpdateFailure::MetadataInvalid);
        }

        try {
            $metadata = $reader->metadata();

            if (! in_array($metadata->databaseType, $spec->databaseTypes, true)) {
                throw GeoUpdateException::because(GeoUpdateFailure::WrongDatabaseType);
            }

            if ($metadata->ipVersion !== 6 || $metadata->binaryFormatMajorVersion !== 2) {
                throw GeoUpdateException::because(GeoUpdateFailure::MetadataInvalid);
            }

            $build = (int) $metadata->buildEpoch;

            if ($build > $now->getTimestamp() + 2 * 86400) {
                throw GeoUpdateException::because(GeoUpdateFailure::BuildInFuture);
            }

            if ($installedBuildEpoch !== null && $build < $installedBuildEpoch) {
                throw GeoUpdateException::because(GeoUpdateFailure::BuildTooOld);
            }

            if ($spec->maxBuildAgeDays !== null && $now->getTimestamp() > $build + $spec->maxBuildAgeDays * 86400) {
                throw GeoUpdateException::because(GeoUpdateFailure::BuildTooOld);
            }

            $placed = 0;

            foreach ($probes as $ip => $country) {
                try {
                    $record = $reader->get((string) $ip);
                } catch (Throwable) {
                    throw GeoUpdateException::because(GeoUpdateFailure::MetadataInvalid);
                }

                if (GeoRecordMapper::map(is_array($record) ? $record : null)->countryCode === $country) {
                    $placed++;
                }
            }

            if ($placed < min($probesRequired, count($probes))) {
                throw GeoUpdateException::because(GeoUpdateFailure::ProbeMismatch);
            }

            return ['database_type' => $metadata->databaseType, 'build_epoch' => $build];
        } finally {
            $reader->close();
        }
    }
}
