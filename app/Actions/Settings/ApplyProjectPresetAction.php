<?php

namespace App\Actions\Settings;

use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Models\ProjectSettings;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;

class ApplyProjectPresetAction
{
    public function __construct(private readonly ProjectSettingsManager $manager) {}

    public function handle(string $presetKey): void
    {
        $presets = config('project_presets');

        if (! is_array($presets) || ! array_key_exists($presetKey, $presets)) {
            throw UnknownProjectPresetException::for($presetKey);
        }

        $preset = $presets[$presetKey];

        if (! is_array($preset['settings'] ?? null) || ! is_array($preset['feature_flags'] ?? null)) {
            throw UnknownProjectPresetException::for($presetKey);
        }

        $settings = PresetSettingsBuilder::build($preset['settings']);

        ProjectSettings::updateOrCreate(
            ['id' => 1],
            array_merge($settings, [
                'feature_flags' => $preset['feature_flags'],
                'active_preset_key' => $presetKey,
            ])
        );

        $this->manager->flush();
    }
}
