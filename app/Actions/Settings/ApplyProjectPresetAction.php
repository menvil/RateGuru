<?php

namespace App\Actions\Settings;

use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;

class ApplyProjectPresetAction
{
    public function __construct(private readonly ProjectSettingsManager $manager) {}

    public function handle(string $presetKey): void
    {
        $presets = config('project_presets');

        if (! array_key_exists($presetKey, $presets)) {
            throw UnknownProjectPresetException::for($presetKey);
        }

        $preset = $presets[$presetKey];

        ProjectSettings::updateOrCreate(
            ['id' => 1],
            array_merge($preset['settings'], [
                'feature_flags' => $preset['feature_flags'],
                'active_preset_key' => $presetKey,
            ])
        );

        $this->manager->flush();
    }
}
