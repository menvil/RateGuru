<?php

namespace App\Filament\Support;

/**
 * The sections of the Admin v2 navigation, in the order the sidebar shows them
 * (docs/design/admin/design-contract.md, "Shell and navigation").
 *
 * Resources and pages name their section with these constants instead of
 * hard-coded strings, and the panel registers all() as its group order.
 */
final class AdminNavigationGroup
{
    public const OVERVIEW = 'Overview';

    public const MODERATION = 'Moderation';

    public const CONTENT = 'Content';

    public const LOCALIZATION = 'Localization';

    public const CONFIGURATION = 'Configuration';

    public const SYSTEM = 'System';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::OVERVIEW,
            self::MODERATION,
            self::CONTENT,
            self::LOCALIZATION,
            self::CONFIGURATION,
            self::SYSTEM,
        ];
    }
}
