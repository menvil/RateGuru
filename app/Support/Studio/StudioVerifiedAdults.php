<?php

namespace App\Support\Studio;

use App\Support\Settings\ProjectSettingsManager;

/**
 * Whether an administrator has confirmed that everyone shown in the photos
 * Content Studio uses is a verified adult who agreed to their publication:
 * the project's `content_studio_verified_adults` flag, off until switched on
 * in the project's settings, and off again whenever a preset is applied.
 *
 * It is the one confirmation of anyone's age the texts and discussions
 * Content Studio writes ever rely on: on, they may flirt with and compliment
 * the people shown; off, they keep to the situation. A tone is never one.
 *
 * Read afresh every time, never from what a long-lived worker resolved
 * earlier, so switching it off changes the very next request.
 */
final class StudioVerifiedAdults
{
    public const FLAG = 'content_studio_verified_adults';

    public function __construct(
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function confirmed(): bool
    {
        $this->settings->flush();

        return $this->settings->featureEnabled(self::FLAG);
    }
}
