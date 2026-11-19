<?php

namespace App\Enums;

/** Why an update of the IP location database did not install a new build. */
enum GeoUpdateFailure: string
{
    case ZlibMissing = 'zlib_missing';
    case DiskSpace = 'disk_space';
    case DiscoveryFailed = 'discovery_failed';
    case DownloadFailed = 'download_failed';
    case TooLarge = 'too_large';
    case SizeMismatch = 'size_mismatch';
    case ChecksumMismatch = 'checksum_mismatch';
    case ArchiveInvalid = 'archive_invalid';
    case UnpackFailed = 'unpack_failed';
    case WrongDatabaseType = 'wrong_database_type';
    case MetadataInvalid = 'metadata_invalid';
    case BuildTooOld = 'build_too_old';
    case BuildInFuture = 'build_in_future';
    case ProbeMismatch = 'probe_mismatch';
    case SwapFailed = 'swap_failed';
    case NothingToRollBack = 'nothing_to_roll_back';

    /** What the administrator reads on the Traffic sources page. */
    public function description(): string
    {
        return match ($this) {
            self::ZlibMissing => 'PHP on this server has no zlib, so a downloaded database cannot be unpacked.',
            self::DiskSpace => 'There is not enough free disk space to download and unpack the database.',
            self::DiscoveryFailed => 'No download source answered with the latest build.',
            self::DownloadFailed => 'The download did not complete.',
            self::TooLarge => 'The file was larger than any real build, so it was not used.',
            self::SizeMismatch => 'The file was not the size its source announced.',
            self::ChecksumMismatch => 'The file did not match its published checksum.',
            self::ArchiveInvalid => 'The package did not contain the database.',
            self::UnpackFailed => 'The download was cut short or is not a gzip file.',
            self::WrongDatabaseType => 'The file is not the database that was asked for.',
            self::MetadataInvalid => 'The file is not a usable IP location database.',
            self::BuildTooOld => 'The newest build is older than the installed one, or past its licence age.',
            self::BuildInFuture => 'The build date is in the future.',
            self::ProbeMismatch => 'The database placed well-known addresses in the wrong countries.',
            self::SwapFailed => 'The new file could not be put in place of the old one.',
            self::NothingToRollBack => 'There is no earlier database to go back to.',
        };
    }
}
