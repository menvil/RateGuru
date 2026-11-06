<?php

namespace App\Support\Studio;

use App\Support\Settings\ProjectSettingsManager;

/**
 * Whether Content Studio's executor runs at all: the project's
 * `content_studio_execution_enabled` flag, off until switched on. While it is
 * off, `studio:dispatch-due` sends nothing and takes nothing back, and a job
 * already sent begins nothing.
 *
 * Read afresh every time, never from what a long-lived worker resolved
 * earlier, so switching it off stops the very next job.
 */
final class StudioExecutionSwitch
{
    public const FLAG = 'content_studio_execution_enabled';

    public function __construct(
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function isOn(): bool
    {
        $this->settings->flush();

        return $this->settings->featureEnabled(self::FLAG);
    }
}
