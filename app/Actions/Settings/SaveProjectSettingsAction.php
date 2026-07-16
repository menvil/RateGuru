<?php

namespace App\Actions\Settings;

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;

final class SaveProjectSettingsAction
{
    public function __construct(private readonly ProjectSettingsManager $manager) {}

    /** @param array<string, mixed> $settings */
    public function handle(array $settings): ProjectSettings
    {
        $model = ProjectSettings::updateOrCreate(['id' => 1], $settings);

        $this->manager->flush();

        return $model;
    }
}
