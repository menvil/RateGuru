# Phase 46 — Multilingual UI Review Checklist

## Config & Infrastructure

- [x] `config/locales.php` exists with en/ru/bg and fallback `en`
- [x] LocaleManager exists at `App\Support\Locale\LocaleManager`
- [x] LocaleManager resolves supported locales, fallback, labels, native labels
- [x] `TranslatableField` helper exists at `App\Support\Translations\TranslatableField`

## Database

- [x] `users.locale` column exists (nullable string 12)
- [x] `project_settings` has 7 JSON translation columns
- [x] `rating_groups` has `label_translations`, `description_translations`
- [x] `rating_options` has `label_translations`, `description_translations`

## Middleware & Routing

- [x] SetLocale middleware exists and is registered in web stack
- [x] SetLocale resolves: auth user → session → cookie → config fallback
- [x] Unsupported locale falls back to `en` safely
- [x] `POST /locale` route (`locale.change`) exists
- [x] `ChangeLocaleAction` stores session locale and updates auth user

## Public UI

- [x] language switcher component exists in header (guest and auth)
- [x] language switcher shows English / Русский / Български
- [x] Core public UI strings use translation keys (`__()`)
- [x] Feed filter buttons Source/Category use translation keys
- [x] Share/Comments labels use translation keys
- [x] User-generated content (post titles, descriptions, comments) is NOT translated

## Translation Files

- [x] `lang/en/ui.php`, `lang/ru/ui.php`, `lang/bg/ui.php` exist
- [x] `lang/en/admin.php`, `lang/ru/admin.php`, `lang/bg/admin.php` exist
- [x] `lang/en/auth.php`, `lang/ru/auth.php`, `lang/bg/auth.php` exist
- [x] `lang/en/validation.php`, `lang/ru/validation.php`, `lang/bg/validation.php` exist
- [x] translation keys are consistent across locales (guardrail test passes)

## ProjectSettings Translations

- [x] ProjectSettings translation fields exist and cast to arrays
- [x] ProjectSettingsManager passes translation arrays to ResolvedProjectSettings
- [x] ResolvedProjectSettings uses TranslatableField for all label methods
- [x] Fallback to base field when translation is missing/empty
- [x] Admin can edit translations via ProjectSettingsPage Translations section

## Rating Group & Option Translations

- [x] RatingGroup.translatedLabel() works with locale fallback
- [x] RatingOption.translatedLabel() works with locale fallback
- [x] rating-options blade component uses translatedLabel()
- [x] Admin can edit rating group translations via RatingGroupForm
- [x] Admin can edit rating option translations via OptionsRelationManager

## User Locale Preference

- [x] UserLocaleSettings Livewire component exists
- [x] Component integrated into profile/edit page
- [x] Authenticated user can save locale preference
- [x] Unsupported locale rejected with validation error

## Guardrails

- [x] TranslationKeyGuardTest checks ui/admin/auth/validation keys across locales
- [x] User-generated content explicitly NOT auto-translated
- [x] No locale URL prefix (`/en/`, `/ru/`) added — reserved for SEO phase
- [x] No hreflang or multi-domain routing added

## Final Checks

- [x] `composer test` passes
- [x] `npm run build` passes
- [x] `php artisan migrate:fresh` passes
- [x] Phase 46 ready for release branch `release/v0.3.3-phase46-multilingual-ui`
