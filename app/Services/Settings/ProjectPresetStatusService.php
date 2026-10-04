<?php

namespace App\Services\Settings;

use App\Models\ProjectSettings;

final class ProjectPresetStatusService
{
    public function display(): string
    {
        $settings = ProjectSettings::query()->find(1);

        if ($settings?->preset_applied_at === null) {
            return __('admin.project_settings.preset_not_applied');
        }

        $presetKey = $settings->active_preset_key ?? 'unknown';
        $presetLabel = config("project_presets.{$presetKey}.label", $presetKey);

        return __('admin.project_settings.preset_applied', [
            'preset' => $presetLabel,
            'date' => $settings->preset_applied_at->format('Y-m-d H:i:s'),
        ]);
    }
}
