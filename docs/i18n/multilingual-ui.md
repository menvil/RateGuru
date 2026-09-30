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

Located at `lang/{locale}/`, one namespaced catalog per area — `ui.php`,
`auth.php`, `profile.php`, `mail.php`, `validation.php`, `passwords.php` and the
feature catalogs (`follows.php`, `import.php`, `saved_posts.php`, `sharing.php`).
Every line is read through a stable key such as `__('auth.login.remember')`;
there are no JSON catalogs and no English-sentence keys.

`admin.php` exists in English only: the Filament panel always renders in English
(`SetAdminLocale`), whatever language the visitor chose for the public site.

Every public catalog must have **identical keys and placeholders** across
locales; `TranslationParityTest` enforces this, and `TranslationKeyGuardTest`
checks that the code only reads keys the catalogs define. `lang/README.md` has
the full rules.

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
2. Create `lang/{code}/` with a translation of every file in `lang/en/` except `admin.php`.
3. `TranslationParityTest` will fail, listing what is missing, until the catalogs match.
