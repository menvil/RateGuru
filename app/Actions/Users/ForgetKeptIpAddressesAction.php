<?php

namespace App\Actions\Users;

use App\Models\ProjectSettings;
use App\Models\User;

/**
 * The IP addresses members' accounts keep, forgotten once Project settings stop keeping them: an address is kept
 * only while Keep IP addresses is on (RecordMemberPlaceAction), so a save of the settings that switches it off —
 * the page's or a preset's — takes those kept so far with it. The countries, cities and hashes stay: they are
 * kept whatever the setting.
 */
final class ForgetKeptIpAddressesAction
{
    /** Whether a settings row keeps IP addresses: off until switched on, as ProjectSettingsManager's default. */
    public static function kept(ProjectSettings $row): bool
    {
        $flags = is_array($row->feature_flags) ? $row->feature_flags : [];

        return (bool) ($flags['keep_ip_addresses'] ?? false);
    }

    /**
     * After a save of the settings: the addresses forgotten when it switched keeping them off.
     *
     * @return int how many accounts kept an address
     */
    public function ifSwitchedOff(bool $keptBefore, ProjectSettings $row): int
    {
        return $keptBefore && ! self::kept($row) ? $this->handle() : 0;
    }

    /** @return int how many accounts kept an address */
    public function handle(): int
    {
        return User::withoutTimestamps(fn (): int => User::query()
            ->where(fn ($kept) => $kept->whereNotNull('signup_ip')->orWhereNotNull('visit_ip'))
            ->update(['signup_ip' => null, 'visit_ip' => null]));
    }
}
