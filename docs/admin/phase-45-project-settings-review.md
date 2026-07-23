# Phase 45 — Project Settings / Presets — Review Checklist

> RG-897 supersedes the original reusable admin preset action. Presets are now
> one-time installation operations executed by `php artisan rateguru:setup`.

## Database & Model

- [x] `project_settings` table exists with all required columns
- [x] `ProjectSettings` model exists with `feature_flags` cast to array
- [x] `ProjectSettingsFactory` exists with sensible defaults

## Service Layer

- [x] `ProjectSettingsManager` singleton registered in `AppServiceProvider`
- [x] `ProjectSettingsManager::current()` returns `ResolvedProjectSettings`
- [x] `ProjectSettingsManager::featureEnabled(string)` works
- [x] Missing `project_settings` row does not crash the app — fallback defaults active
- [x] In-memory cache via `flush()` method

## Seeder

- [x] `DefaultProjectSettingsSeeder` exists and is idempotent (`updateOrCreate`)
- [x] Seeder registered in `DatabaseSeeder`

## Presets

- [x] `config/project_presets.php` exists with generic, nature, ai_images, breasts presets
- [x] All presets define settings, feature flags, rating groups/options, and tags
- [x] `ApplyProjectPresetAction` applies the full preset in a transaction and flushes manager cache
- [x] `UnknownProjectPresetException` thrown for invalid preset key
- [x] `preset_applied_at` prevents accidental repeated installation
- [x] Existing posts block normal installation; reviewed `--force` is explicit

## Admin UI

- [x] `ProjectSettingsPage` Filament page exists at `/admin/project-settings`
- [x] Admin can access; regular user and moderator cannot
- [x] Form fields cover editable ProjectSettings columns
- [x] Validation: required fields, max lengths, theme/sort enum validation
- [x] Preset state and installation time are read-only in admin
- [x] Admin exposes no preset application action

## Public UI Integration

- [x] `layouts.app` view composer shares `$projectSettings`
- [x] `layouts.guest` view composer shares `$projectSettings`
- [x] Header brand link uses `$projectSettings->siteName()`
- [x] Page title uses `$projectSettings->siteName()`
- [x] Feed title uses `$feedSettings->feedTitle()` (via @inject)
- [x] Upload CTA button uses `$projectSettings->uploadCtaLabel()`
- [x] Upload CTA modal title uses `$projectSettings->uploadCtaLabel()`

## Feature Flags

- [x] `show_comments` hides/shows comment button and comments section
- [x] `show_share_buttons` hides/shows share button and share modal
- [x] `allow_user_uploads` hides/shows upload button
- [x] All feature flag checks go through `ProjectSettingsManager::featureEnabled()`

## Architecture Constraints

- [x] No `ProjectSettings::first()` calls in Blade views
- [x] No `if ($preset === 'cats')` or similar runtime branching by preset name
- [x] No multilingual/i18n implementation added in Phase 45
- [x] No theme CSS switcher implementation added in Phase 45
- [x] No Phase 44 voting options architecture changed

## Security Note

- [x] `allow_user_uploads = false` hides the upload button (UI-level only)
- [ ] Backend guard for upload action not yet implemented — documented as future work in `docs/admin/project-settings.md`

## Docs

- [x] `docs/admin/project-settings.md` exists
- [x] `docs/admin/project-presets.md` exists
- [x] `docs/admin/phase-45-project-settings-review.md` exists (this file)

## Tests (all passing)

- [x] `ProjectSettingsTableTest` — migration schema
- [x] `ProjectSettingsModelTest` — model and factory
- [x] `ProjectSettingsManagerTest` — fallback, persisted row, feature flags
- [x] `DefaultProjectSettingsSeederTest` — seeder creates row, idempotent
- [x] `ProjectPresetsConfigTest` — config exists, shape valid
- [x] `ApplyProjectPresetActionTest` — apply preset, unknown preset throws
- [x] `ProjectSettingsPageTest` — admin access, user/moderator forbidden
- [x] `ProjectSettingsValidationTest` — required fields, invalid theme/sort
- [x] `ApplyPresetAdminActionTest` — no apply controls; read-only installation status
- [x] `SetupProjectPresetCommandTest` — confirmation, guards, force, full setup
- [x] `LayoutSettingsIntegrationTest` — configured and fallback site name
- [x] `FeedUploadLabelsIntegrationTest` — configured and fallback labels
- [x] `FeatureFlagsPublicUiTest` — comments/share/upload hidden by flags
- [x] `ProjectSettingsDocsTest` — docs file exists
- [x] `ProjectPresetsDocsTest` — docs file exists
