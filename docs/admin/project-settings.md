# Project Settings

## What ProjectSettings controls

`project_settings` is a singleton table (one row, `id = 1`) that controls:

- `site_name` — displayed in browser title, header brand, and page meta
- `site_tagline` — short descriptor for the project
- `site_description` — longer description (used in future meta tags)
- `object_singular_name` — what a single rated item is called (e.g. "post", "photo", "animal")
- `object_plural_name` — plural form (e.g. "posts", "photos", "animals")
- `upload_cta_label` — text of the upload button (e.g. "Upload post")
- `feed_title` — heading above the main feed (e.g. "Latest posts")
- `default_locale` — locale string (e.g. `en`)
- `default_theme` — one of `system`, `light`, `dark`
- `default_sort` — one of `hot`, `new`, `top`
- `active_preset_key` — which preset was last applied (informational only)
- `preset_applied_at` — successful one-time preset installation timestamp
- `feature_flags` — JSON object controlling UI visibility

## Installation preset status

Project presets are installed from the server with
`php artisan rateguru:setup`. The Project Settings admin page exposes the
installed preset label (for example, “Nature & travel photography”) and
`preset_applied_at` as read-only status; it cannot apply or replace a preset.
The label is resolved from the stored `active_preset_key`.

This separation is intentional: a preset also synchronizes categories, rating
groups, rating options, and tags, so it is not a normal settings-form operation. See
`docs/admin/project-presets.md` for the command workflow and safety guards.

## Fallback defaults

If the `project_settings` table is empty, the app continues to work using these fallback defaults built into `ProjectSettingsManager`:

```text
site_name = RateGuru
site_tagline = Rate anything
object_singular_name = post
object_plural_name = posts
upload_cta_label = Upload post
feed_title = Latest posts
default_locale = en
default_theme = system
default_sort = hot
active_preset_key = generic
preset_applied_at = null
feature_flags:
  show_comments = true
  show_share_buttons = true
  show_vote_breakdown = true
  show_follow_buttons = false
  show_saved_posts = false
  allow_user_uploads = true
  allow_guest_viewing = true
```

## How to read settings in code

Always use `ProjectSettingsManager`, never query `ProjectSettings` directly:

```php
// Correct
$settings = app(ProjectSettingsManager::class)->current();
$siteName = $settings->siteName();

// Also correct for feature flags
$canUpload = app(ProjectSettingsManager::class)->featureEnabled('allow_user_uploads');

// WRONG — do not do this
$settings = \App\Models\ProjectSettings::first();
$siteName = $settings->site_name;
```

## How to read settings in Blade views

Use `@inject`:

```blade
@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
{{ $settingsManager->current()->feedTitle() }}
```

Or share via view composer in `AppServiceProvider` (already done for `layouts.app` and `layouts.guest`):

```blade
{{ $projectSettings->siteName() }}
```

## Why not query ProjectSettings directly in Blade

- Missing DB row would crash the view — the manager handles the fallback
- Direct queries bypass the in-memory cache
- Future: the manager is the single caching/invalidation point

## Feature flags

Feature flags are stored in `feature_flags` JSON column:

| Flag | Default | Controls |
|------|---------|----------|
| `show_comments` | true | Comment form and section visibility |
| `show_share_buttons` | true | Share button and share modal |
| `show_vote_breakdown` | true | Vote distribution display |
| `show_follow_buttons` | false | Follow/unfollow UI |
| `show_saved_posts` | false | Saved/bookmarked posts UI |
| `allow_user_uploads` | true | Upload button visibility |
| `allow_guest_viewing` | true | Whether guests can see the feed |

### Security warning for feature flags

**UI flags are not security by themselves.**

Disabling `allow_user_uploads` in settings hides the upload button, but does not block the backend action. A determined user could still call the upload endpoint directly.

For security-critical flags, a backend guard must also be added. This is documented as future work.

## Relation to future phases

- **Phase 46**: May convert string labels to translatable JSON or a separate translations table. `ProjectSettings` string fields are designed to be compatible with this migration.
- **Theme implementation**: `default_theme` is stored but CSS theme switching is not implemented in Phase 45.
