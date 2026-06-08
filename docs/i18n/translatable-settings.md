# Translatable Settings — RateGuru

## Overview

Admin-configurable labels in `ProjectSettings`, `RatingGroup`, and `RatingOption` support per-locale translations without removing or breaking the original string columns.

## How it works

Each translatable field has:
- A base string column (e.g., `site_name`) — the admin-configured default
- A JSON translations column (e.g., `site_name_translations`) — map of `{locale: value}`

Resolution (via `TranslatableField::resolve()`):
1. If `translations[current_locale]` exists and is non-empty → use it
2. Otherwise → use the base field value

## ProjectSettings fields

| Base field | Translations field |
|---|---|
| `site_name` | `site_name_translations` |
| `site_tagline` | `site_tagline_translations` |
| `site_description` | `site_description_translations` |
| `object_singular_name` | `object_singular_name_translations` |
| `object_plural_name` | `object_plural_name_translations` |
| `upload_cta_label` | `upload_cta_label_translations` |
| `feed_title` | `feed_title_translations` |

## RatingGroup / RatingOption fields

| Base field | Translations field |
|---|---|
| `label` | `label_translations` |
| `description` | `description_translations` |

## Admin UI

- **ProjectSettings**: Translations section with locale tabs (English / Русский / Български)
- **RatingGroup form**: Translations section with locale tabs
- **Options relation manager**: Translations section with locale tabs

## Editing translations

1. Go to Admin → Project Settings (or Rating Groups)
2. Expand the Translations section
3. Click the locale tab
4. Fill in the translated value
5. Save

Leaving a field blank means the base value will be shown for that locale.
