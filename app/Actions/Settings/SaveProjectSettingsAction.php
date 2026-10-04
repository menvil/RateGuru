<?php

namespace App\Actions\Settings;

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SaveProjectSettingsAction
{
    public function __construct(
        private readonly ProjectSettingsManager $manager,
    ) {}

    /** @param array<string, mixed> $settings */
    public function handle(array $settings): ProjectSettings
    {
        // Which languages the project offers is not a general setting: it is
        // written only by UpdateProjectLocaleSettingsAction, which keeps the
        // default among them and refuses a language with broken catalogs.
        if (array_key_exists('enabled_locales', $settings)) {
            throw new InvalidArgumentException('The offered languages are written by UpdateProjectLocaleSettingsAction, not with the other project settings.');
        }

        $model = DB::transaction(function () use ($settings): ProjectSettings {
            // An installation without a row starts from the same bootstrap
            // every other writer uses, not from whatever this payload holds.
            $row = ProjectSettings::query()->lockForUpdate()->find(1)
                ?? ProjectSettings::unguarded(fn (): ProjectSettings => new ProjectSettings(['id' => 1, ...$this->manager->defaults()]));

            $row->fill($settings)->save();

            return $row;
        });

        $this->manager->flush();

        return $model;
    }
}
