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

Project settings, Rating groups and their options edit the base (English) value only. Each editor says which
offered languages miss which of its texts and leads to **Translation Center** (Admin → Localization), the one
editor of translations.

## Editing translations

1. Open Translation Center, or follow Translate from the editor of the text
2. Choose the language and the section
3. Type the translation and save it

Leaving a translation empty means the base value is shown in that language.
