# Project Presets

## What are presets

A preset is a named template of `ProjectSettings` values. Applying a preset writes those values into the `project_settings` row. After that, the admin can modify any field manually.

Presets live in `config/project_presets.php`.

## Available presets

| Key | Label | Site name | Object |
|-----|-------|-----------|--------|
| `generic` | Generic rating | RateGuru | post / posts |
| `food` | Food rating | FoodGuru | dish / dishes |
| `cats` | Cat photos rating | CatGuru | cat / cats |
| `ai_images` | AI image rating | AIGuru | image / images |

## How applying a preset works

```text
config/project_presets.php
    ↓
ApplyProjectPresetAction::handle('cats')
    ↓
ProjectSettings::updateOrCreate(['id' => 1], [...preset values...])
    ↓
ProjectSettingsManager::flush()  — clears in-memory cache
```

After applying, the settings row contains the preset values. The admin can then change any field freely via the admin settings page.

## Preset shape

Each preset in `config/project_presets.php` must have:

```php
'cats' => [
    'label' => 'Cat photos rating',      // shown in admin UI
    'settings' => [                        // written to project_settings columns
        'site_name' => 'CatGuru',
        'site_tagline' => 'Rate every cat',
        'site_description' => '...',
        'object_singular_name' => 'cat',
        'object_plural_name' => 'cats',
        'upload_cta_label' => 'Upload cat',
        'feed_title' => 'Latest cats',
        'default_locale' => 'en',
        'default_theme' => 'system',
        'default_sort' => 'hot',
    ],
    'feature_flags' => [                   // replaces feature_flags JSON column
        'show_comments' => true,
        // ... all flags ...
    ],
],
```

## How to add a new preset

1. Add a new key to `config/project_presets.php` following the shape above.
2. Add the test assertion to `ProjectPresetsConfigTest`:

```php
expect(config('project_presets.my_preset'))->not->toBeNull();
```

3. Run `composer test` to confirm the shape validation passes.

No code changes outside the config file are needed.

## Anti-pattern: do not branch by preset name

**Never** add conditional logic based on the active preset key:

```php
// FORBIDDEN
if ($settings->activePresetKey() === 'cats') {
    // special cats behavior
}

// FORBIDDEN
@if(app(ProjectSettingsManager::class)->current()->activePresetKey() === 'food')
    <x-food-specific-component />
@endif
```

The correct approach: presets write values into settings, and UI reads those values:

```blade
// Correct
@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
{{ $settingsManager->current()->objectPluralName() }}  {{-- shows "cats", "dishes", etc. --}}
```

If a preset needs truly different UI behavior, create a separate Phase for that feature — not a conditional branch inside existing components.

## Testing presets

The shape of all presets is validated in:

```text
tests/Feature/Support/Settings/ProjectPresetsConfigTest.php
```

The action is tested in:

```text
tests/Feature/Actions/ApplyProjectPresetActionTest.php
```
