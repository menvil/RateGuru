<?php

namespace App\Filament\Support;

/**
 * Centralised navigation group names for the Filament admin panel.
 *
 * Future Filament resources should reference these constants instead
 * of hardcoding strings so the sidebar stays consistent.
 */
final class AdminNavigationGroup
{
    public const CONTENT = 'Content';

    public const MODERATION = 'Moderation';

    public const USERS = 'Users';

    public const TAXONOMY = 'Taxonomy';

    public const SYSTEM = 'System';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CONTENT,
            self::MODERATION,
            self::USERS,
            self::TAXONOMY,
            self::SYSTEM,
        ];
    }
}
