<?php

namespace App\Enums;

enum MediaHealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    /**
     * The one Filament/Blade color mapping every renderer (MediaHealthWidget,
     * the diagnostics page's own health badge) shares — kept here rather
     * than duplicated in each, matching MediaAuditIssueType::severity()'s
     * precedent elsewhere in this same feature.
     */
    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Critical => 'danger',
            self::Unknown => 'gray',
        };
    }
}
