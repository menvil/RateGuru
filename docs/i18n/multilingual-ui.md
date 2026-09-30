# Multilingual UI — RateGuru

## Supported (installed) locales

The supported locales — every language the application is installed with — are defined in `config/locales.php`, each with its English label, native name and display flag:

- `en` — English (system fallback)
- `ru` — Russian / Русский
- `bg` — Bulgarian / Български

The flag is chosen per language rather than derived from the code: a language is not a country.

## Enabled locales, project default, system fallback

| term | source | meaning |
|---|---|---|
| Supported / installed | `config/locales.php` + `lang/{code}/` | present in config and in the translation catalogs |
| Enabled | `project_settings.enabled_locales` | offered by this project to public users; `NULL` means every installed language |
| Project default | `project_settings.default_locale` | the normal locale for a visitor with no preference and no browser match; always enabled |
| System fallback | `config('locales.fallback')` | technical emergency locale; always installed, not necessarily enabled |

A disabled locale remains installed and its DB/content translations may still be edited in admin: every translation editor (project settings, static pages, categories, tags, rating groups and options) lists all installed languages, while the public switcher, the account language setting, `POST /locale` and the locale middleware only accept enabled ones.

`enabled_locales` and `default_locale` are written together by `App\Actions\Settings\UpdateProjectLocaleSettingsAction`, which stores installed codes only, in config order, never an empty set, and a default inside the set. Reading is defensive: unknown codes are ignored, and a row that leaves nothing usable resolves to the system fallback.

## Locale resolution order

For public requests the first **enabled** locale among:

1. Authenticated user locale preference (`users.locale`)
2. Session locale (`locale` key)
3. Cookie locale (`locale` cookie)
4. Browser `Accept-Language`, in quality order; a regional tag (`ru-RU`) matches the installed language (`ru`)
5. Project default (`project_settings.default_locale`)
6. System fallback (`locales.fallback`) — only when the project settings resolve to nothing usable

A stored preference for a disabled locale is ignored, not deleted, and applies again if the locale is re-enabled. The browser's language is used for the current request only; nothing is written from it.

The admin panel is always English (`SetAdminLocale`) and is not part of this order.

No locale URL prefix (`/en/`, `/ru/`) is used in Phase 46. That is reserved for a future SEO/hreflang phase.

## LocaleManager

`App\Support\Locale\LocaleManager` provides:

- `supported(): array` / `isSupported(string $locale): bool` — installed languages
- `enabled(): array` / `enabledCodes(): array` / `isEnabled(string $locale): bool` — languages this project offers, in config order
- `projectDefault(): string` — the enabled default, else the fallback when enabled, else the first enabled locale
- `fallback(): string` — the system fallback
- `enabledOrDefault(?string $locale): string` — a read value when enabled, otherwise the project default (never used for writes)
- `fromAcceptLanguage(?string $header): ?string` — the enabled locale a browser asks for, or `null`
- `label()`, `nativeLabel()`, `flag()`

## SetLocale middleware

`App\Http\Middleware\SetLocale` is registered in the web stack. It resolves the locale on every request, in the order above, and calls `app()->setLocale()`.

## Language switcher

`<x-locale-switcher />` is a Blade component included in the header for both guests and authenticated users. It lists the enabled locales with their flag and native name and posts to `POST /locale` (`locale.change` route), which accepts only an enabled locale and stores it in the session, in a year-long encrypted `locale` cookie (path `/`, SameSite Lax, HTTP-only) and, for a signed-in user, on the account.

## User locale preference

Authenticated users can set their preferred locale on the Profile page via `livewire:settings.user-locale-settings`, which offers only enabled locales. The preference is stored in `users.locale`.

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

1. Add the locale to `config/locales.php` under `supported`, with its `label`, `native` name and `flag`.
2. Create `lang/{code}/` with a translation of every file in `lang/en/` except `admin.php`.
3. `TranslationParityTest` will fail, listing what is missing, until the catalogs match.
4. It is offered at once by projects that never narrowed their enabled languages (`enabled_locales` is `NULL`); a project with an explicit list offers it only once it is enabled there.
