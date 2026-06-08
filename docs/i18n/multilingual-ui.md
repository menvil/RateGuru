# Multilingual UI — RateGuru

## Supported locales

The following supported locales are defined in `config/locales.php`:

- `en` — English (fallback)
- `ru` — Russian / Русский
- `bg` — Bulgarian / Български

## Locale resolution order

1. Authenticated user locale preference (`users.locale`)
2. Session locale (`locale` key)
3. Cookie locale (`locale` key)
4. Config fallback (`locales.fallback`, default `en`)

No locale URL prefix (`/en/`, `/ru/`) is used in Phase 46. That is reserved for a future SEO/hreflang phase.

## LocaleManager

`App\Support\Locale\LocaleManager` provides:

- `supported(): array` — map of code → label/native
- `isSupported(string $locale): bool`
- `fallback(): string`
- `normalize(?string $locale): string` — normalizes unsupported to fallback
- `label(string $locale): string`
- `nativeLabel(string $locale): string`

## SetLocale middleware

`App\Http\Middleware\SetLocale` is registered in the web stack. It resolves the locale on every request and calls `app()->setLocale()`.

## Language switcher

`<x-locale-switcher />` is a Blade component included in the header for both guests and authenticated users. It posts to `POST /locale` (`locale.change` route).

## User locale preference

Authenticated users can set their preferred locale on the Profile page via `livewire:settings.user-locale-settings`. The preference is stored in `users.locale`.

## Translation files

Located at `lang/{en,ru,bg}/`:

- `ui.php` — public interface labels (feed, voting, comments, share, etc.)
- `admin.php` — admin panel custom labels
- `auth.php` — authentication messages
- `validation.php` — validation error messages

All translation files must have **identical keys** across locales. The `TranslationKeyGuardTest` enforces this.

## ProjectSettings translatable fields

`ProjectSettings` model has JSON translation columns alongside original string columns:

- `site_name_translations`
- `site_tagline_translations`
- `site_description_translations`
- `object_singular_name_translations`
- `object_plural_name_translations`
- `upload_cta_label_translations`
- `feed_title_translations`

`ProjectSettingsManager` passes these to `ResolvedProjectSettings`, which uses `TranslatableField::resolve()` to pick the current locale translation or fall back to the base string.

## RatingGroup and RatingOption translatable fields

Both models have:

- `label_translations` (JSON, nullable)
- `description_translations` (JSON, nullable)

Both have `translatedLabel(?string $locale = null): string` method using `TranslatableField`.

The `rating-options` Blade component uses `translatedLabel()` for rendering.

## TranslatableField

`App\Support\Translations\TranslatableField::resolve(mixed $translations, string $fallback, ?string $locale = null): string`

Fallback chain:
1. `translations[current_locale]` if non-empty
2. `$fallback` base field value

## What is NOT auto-translated

User-generated content is **not auto-translated** in Phase 46:

- `posts.title`
- `posts.description`
- `comments.body`
- `users.bio`

Auto-translation requires external API integration, a UX for original/translated toggle, and quality control — all out of scope for Phase 46.

## Adding a new locale

1. Add the locale to `config/locales.php` under `supported`.
2. Create `lang/{code}/ui.php`, `admin.php`, `auth.php`, `validation.php` with identical keys.
3. The `TranslationKeyGuardTest` will fail until all files match.
