# Project Presets

## Purpose

A project preset is a one-time installation template. It configures the whole
rating site before real content is created:

- project identity, object labels, locale, theme, and feature flags;
- optional single-value post categories used by feed navigation and filtering;
- rating groups and options;
- tags.

Presets live in `config/project_presets.php`. After installation, administrators
may edit normal project settings, categories, rating configuration, and tags
independently.
Runtime code must not branch on the preset key.

## Available presets

| Key | Label | Site name | Object |
|-----|-------|-----------|--------|
| `generic` | Generic rating | RateGuru | post / posts |
| `nature` | Nature & travel photography | NatureGuru | photo / photos |
| `ai_images` | AI image rating | AIGuru | image / images |
| `breasts` | Breast rating | BreastGuru | photo / photos |

## Initial setup

Run the setup command after migrations and before importing or creating posts:

```bash
php artisan rateguru:setup nature
```

Omit the key to choose a preset interactively:

```bash
php artisan rateguru:setup
```

The command shows the selected preset and asks for confirmation. On success it
prints the numbers of categories, rating groups, rating options, and tags changed.

The command applies all changes in one database transaction. If any part fails,
settings, categories, rating configuration, and tags are rolled back together. The
`project_settings.preset_applied_at` timestamp records successful installation.

## Safety rules

A normal setup is rejected when:

- `preset_applied_at` is already set; or
- at least one post already exists.

The admin page deliberately has no Apply button. It displays only the installed
preset and application time, or the setup command when no preset was installed.

For a reviewed reset, `--force` bypasses both guards and skips confirmation:

```bash
php artisan rateguru:setup ai_images --force
```

`--force` does not delete users or posts, but it can replace project settings,
deactivate old rating groups and options, and delete tags not present in the new
preset. When `categories` is `null`, existing category records are unchanged.
When a preset provides an explicit category list, categories omitted from that
list are deactivated rather than deleted, so existing post relationships remain
valid. Use `--force` only after reviewing those effects and taking any required
backup.

Default seeders detect `preset_applied_at` and do not overwrite an installed
preset when `php artisan db:seed` is run later.

## Preset shape

Each preset must define:

```php
'my_preset' => [
    'label' => 'My rating preset',
    'settings' => [
        'site_name' => ['en' => 'MyGuru'],
        'site_tagline' => ['en' => 'Rate every item'],
        'site_description' => ['en' => null],
        'object_singular_name' => ['en' => 'item'],
        'object_plural_name' => ['en' => 'items'],
        'upload_cta_label' => ['en' => 'Upload item'],
        'feed_title' => ['en' => 'Latest items'],
        'default_theme' => 'system',
        'default_sort' => 'hot',
    ],
    'feature_flags' => [
        // All supported feature flags.
    ],
    'categories' => [
        [
            'slug' => 'showcase',
            'name' => ['en' => 'Showcase'],
            'sort_order' => 10,
        ],
    ],
    'rating_groups' => [
        [
            'key' => 'type',
            'label' => ['en' => 'Type'],
            'description' => ['en' => null],
            'sort_order' => 10,
            'options' => [
                [
                    'key' => 'example',
                    'label' => ['en' => 'Example'],
                    'sort_order' => 10,
                ],
            ],
        ],
    ],
    'tags' => [
        ['en' => 'Example'],
    ],
],
```

Every text is English alone. A project translates its own content — what a
preset seeded and what an administrator created alike — in Translation Center,
so adding a language never means translating presets;
`RepositoryProjectContentLanguageTest` fails a preset or static page that
ships another language.

A preset does not decide languages: the offered languages are set on the
Languages page, and English is always the default. A `default_locale` or
`enabled_locales` key, left over in a custom preset, is ignored.

A preset is a bootstrap source, not a runtime one: applying it copies its
values into the database, which visitors are then served from. A later change
to a preset does not reach a project that already applied it (see
`docs/i18n/project-translation-lifecycle.md`).

Set `categories`, `rating_groups`, or `tags` to `null` to keep the corresponding
records unchanged. Configured categories are activated or created and omitted
categories are deactivated. Configured rating records are activated or created,
old options are archived, old groups are deactivated, and non-configured tags
are removed.

Categories, rating groups, and tags have distinct meanings:

- a post has zero or one category, chosen by its author and used for navigation;
- a rating group is a question visitors answer by choosing one option;
- a post can have many tags for flexible search and discovery.

After setup, administrators manage these independently in Filament:

- **Categories** adds, edits, orders, activates, or deactivates post categories;
- **Rating groups** controls voting questions and their options;
- **Tags** controls the many-to-many discovery vocabulary.

A category referenced by a post cannot be deleted. Deactivate it instead to
preserve existing content while removing it from new uploads and feed filters.

## Adding a preset

1. Add its complete definition to `config/project_presets.php`.
2. Extend `tests/Feature/Support/Settings/ProjectPresetsConfigTest.php`.
3. Run the Action, command, and config tests on each supported database.

Do not add a preset-specific seeder or an admin action. The command and
`ApplyProjectPresetAction` are the single setup path.

## Testing

- `ProjectPresetsConfigTest` validates preset configuration.
- `ApplyProjectPresetActionTest` covers full application and safety guards.
- `SetupProjectPresetCommandTest` covers the operator workflow.
- `ApplyPresetAdminActionTest` verifies that the admin page is read-only for
  installation state.
