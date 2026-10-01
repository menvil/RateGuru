# Multilingual UI — RateGuru

## Supported (installed) locales

The supported locales — every language the application is installed with — are defined in `config/locales.php`, each with its English label, native name and display flag:

- `en` — English (the default, always enabled)
- `ru` — Russian / Русский
- `bg` — Bulgarian / Български
- `de` — German / Deutsch (ships disabled)

The flag is chosen per language rather than derived from the code: a language is not a country.

## Installed, complete, enabled — and the default

| term | source | meaning |
|---|---|---|
| Supported / installed | `config/locales.php` + `lang/{code}/` | present in config and in the translation catalogs |
| Complete | computed live on Admin → System → Languages | application catalogs (the CI contract) and this project's own content as the database holds it |
| Enabled | `project_settings.enabled_locales` | offered by this project to public users; English always; `NULL` means English plus the installed languages marked `enabled_by_default` |
| Default | `config('locales.default')` | English, by system policy: what a visitor gets when nothing else applies; always enabled, never disabled, not a project setting |
| System fallback | `config('locales.fallback')` | the catalog Laravel falls back to for a missing line; English too, never used to choose a visitor's language |

A disabled locale remains installed and its DB/content translations may still be edited in admin: every translation editor (project settings, static pages, categories, tags, rating groups and options) lists all installed languages, while the public switcher, the account language setting, `POST /locale` and the locale middleware only accept enabled ones.

`enabled_by_default` (per installed language, in `config/locales.php`) is bootstrap policy only: it decides what a project offers besides English while `enabled_locales` is `NULL`. A language a release adds is `false`, so installing it never offers it to an existing project.

`enabled_locales` is written only by `App\Actions\Settings\UpdateProjectLocaleSettingsAction`, which takes the offered languages and nothing else and always writes an explicit list: installed codes only, in config order, English always among them — a list without English is refused, not repaired. It also refuses to newly enable a language whose application catalogs break the contract. Its only interface is the **Languages** page (Admin → System), which enables and disables languages other than English; English shows as Enabled and Default with nothing to disable, and there is no way to make another language the default. `SaveProjectSettingsAction` refuses `enabled_locales` from any internal caller. Presets and the default settings seeder never change the languages of an existing project. Reading is defensive: unknown codes are ignored, and English is offered even from a row that leaves it out.

How completeness is measured, who owns which translation, and what the deploy's backfill fills in, is in `docs/i18n/project-translation-lifecycle.md`.

## Locale resolution order

For public requests:

1. Authenticated user locale preference (`users.locale`)
2. Session locale (`locale` key)
3. Cookie locale (`locale` cookie)
4. Browser `Accept-Language`, in quality order; a regional tag (`ru-RU`) matches the installed language (`ru`)
5. English, the default

The first of 1–3 that holds a value is the visitor's choice and decides: served when it is enabled, English when it is not — the search does not go on to an older choice or to the browser, which would hand a visitor who chose Bulgarian some other language they never picked. The stored choice is kept, never deleted, and applies again once the language is enabled again. Only a visitor who has chosen nothing is served by their browser, and English when the browser asks for nothing on offer. The browser's language is used for the current request only; nothing is written from it.

The admin panel is always English (`SetAdminLocale`) and is not part of this order.

No locale URL prefix (`/en/`, `/ru/`) is used in Phase 46. That is reserved for a future SEO/hreflang phase.

## LocaleManager

`App\Support\Locale\LocaleManager` provides:

- `supported(): array` / `isSupported(string $locale): bool` — installed languages
- `default(): string` / `isDefault(string $locale): bool` — English
- `enabled(): array` / `enabledCodes(): array` / `isEnabled(string $locale): bool` — languages this project offers, in config order, English always among them
- `enabledByDefault(): array` — what a project that never chose offers besides English
- `fallback(): string` — the catalog fallback
- `enabledOrDefault(?string $locale): string` — a read value when enabled, otherwise English (never used for writes)
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

See *Adding a language* in `docs/i18n/project-translation-lifecycle.md`: the locale is declared with `enabled_by_default => false`, its catalogs and repository content are complete before CI passes, the deploy backfills what it safely can, and an administrator enables it on the Languages page.
