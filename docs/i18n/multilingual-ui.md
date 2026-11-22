# Multilingual UI — RateGuru

## Supported (installed) locales

The supported locales — every language the application is installed with — are defined in `config/locales.php`, each with its English label, native name and display flag:

- `en` — English (the default, always enabled)
- `ru` — Russian / Русский
- `bg` — Bulgarian / Български
- `de` — German / Deutsch (ships disabled)
- `es` — Spanish / Español (ships disabled)

and, all shipping disabled: French (`fr`), Italian (`it`), Portuguese — Brazil (`pt`), Turkish (`tr`), Japanese (`ja`),
Polish (`pl`), Romanian (`ro`), Greek (`el`), Dutch (`nl`), Hungarian (`hu`), Czech (`cs`), Slovak (`sk`),
Serbian — Cyrillic (`sr`), Croatian (`hr`), Slovenian (`sl`), Finnish (`fi`), Swedish (`sv`), Norwegian Bokmål (`nb`),
Danish (`da`), Estonian (`et`), Lithuanian (`lt`), Latvian (`lv`), Ukrainian (`uk`), Chinese — Simplified (`zh`),
Georgian (`ka`), Icelandic (`is`), Thai (`th`), Vietnamese (`vi`), Filipino (`fil`), Malay (`ms`), Montenegrin (`cnr`),
Bosnian (`bs`), Macedonian (`mk`) and Albanian (`sq`). `config/locales.php` is the list; this is a summary of it.

The flag is chosen per language rather than derived from the code: a language is not a country.

## Installed, complete, enabled — and the default

| term | source | meaning |
|---|---|---|
| Supported / installed | `config/locales.php` + `lang/{code}/` | present in config and in the translation catalogs |
| Complete | computed live on Admin → System → Languages | application catalogs (the CI contract) and this project's own content as the database holds it |
| Enabled | `project_settings.enabled_locales` | offered by this project to public users; English always; `NULL` means English plus the installed languages marked `enabled_by_default` |
| Default | `config('locales.default')` | English, by system policy: what a visitor gets when nothing else applies; always enabled, never disabled, not a project setting |
| System fallback | `config('locales.fallback')` | the catalog Laravel falls back to for a missing line; English too, never used to choose a visitor's language |

A disabled locale remains installed and its DB/content translations may still be edited in admin: Translation Center, the one editor of project-content translations, lists every installed language but English, the reference translations are made from, while the public switcher, the account language setting, `POST /locale` and the locale middleware only accept enabled ones. The editors of project settings, static pages, categories, tags, rating groups and options edit the English reference only.

`enabled_by_default` (per installed language, in `config/locales.php`) is bootstrap policy only: it decides what a project offers besides English while `enabled_locales` is `NULL`. A language a release adds is `false`, so installing it never offers it to an existing project.

`enabled_locales` is written only by `App\Actions\Settings\UpdateProjectLocaleSettingsAction`, which takes the offered languages and nothing else and always writes an explicit list: installed codes only, in config order, English always among them — a list without English is refused, not repaired. It also refuses to newly enable a language whose application catalogs break the contract. Its only interface is the **Languages** page (Admin → System), which enables and disables languages other than English; English shows as Enabled and Default with nothing to disable, and there is no way to make another language the default. `SaveProjectSettingsAction` refuses `enabled_locales` from any internal caller. Presets and the default settings seeder never change the languages of an existing project. Reading is defensive: unknown codes are ignored, and English is offered even from a row that leaves it out.

How completeness is measured, who owns which translation, and what the deploy's backfill fills in, is in `docs/i18n/project-translation-lifecycle.md`.

## Locale resolution order

For public requests the first **enabled** locale among:

1. Authenticated user locale preference (`users.locale`)
2. Session locale (`locale` key)
3. Cookie locale (`locale` cookie)
4. Browser `Accept-Language`, in quality order; a regional tag (`ru-RU`) matches the installed language (`ru`), and `no` matches `nb` — unless it names another script or variant of an installed language (`zh-TW`, `zh-Hant`, `sr-Latn`, `cnr-Cyrl`, `pt-PT`), which reaches nothing and leaves the browser's next language to be tried; `LanguageRules` holds these rules
5. English, the default — only when nothing above matches

A stored preference for a disabled locale is skipped, not deleted — the search goes on to the next source — and applies again once the locale is re-enabled. The browser's language is used for the current request only; nothing is written from it.

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
- `direction(?string $locale = null): string` — `'ltr'` or `'rtl'`, read from `config/locales.php`; the language being served when none is named (see *Text direction* below)

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
1. English: always the base field value — the text its editor saves. Translations never hold English
   (`TranslatableField::withoutReference()` drops it wherever they are written), and an English entry left among
   them is never read.
2. Any other language: `translations[current_locale]` if non-empty
3. `$fallback` base field value

## What is NOT auto-translated

User-generated content is **not auto-translated** in Phase 46:

- `posts.title`
- `posts.description`
- `comments.body`
- `users.bio`

Auto-translation requires external API integration, a UX for original/translated toggle, and quality control — all out of scope for Phase 46.

## Text direction

A language is read left to right unless its row in `config/locales.php` declares `'direction' => 'rtl'` (`config/locales.php` is the list). `LocaleManager::direction()` reads that key and nothing else: no code keeps a list of right-to-left languages of its own, and `SupportedLocaleListTest` refuses one — a map of directions by language code included.

What follows from the direction:

- **The root element.** `layouts.app` and `layouts.guest` — so every public page, the not-found page included — put `dir` beside `lang` on `<html>`. The HTML email layout (`resources/views/vendor/mail/html/layout.blade.php`) puts it on `<html>`, `<body>` and the `wrapper` table around everything, the contact message on `<html>` and a `<div>` around the message. The rest of the layout follows `dir` by itself, because the templates name sides only as start and end (below).
- **Emails.** Webmail such as Gmail drops `<html>` and `<body>` with whatever they carry, so the wrapper is what still says which way the lines run there — without it an Arabic sentence ends with its full stop on the wrong side. Mail clients know little logical CSS and many keep only what is inlined into each element, so an email in a right-to-left language also carries a small block that turns the mail theme's left-aligned body text and the panel's left edge by naming the sides, and sets `direction` on the wrapper; it is inlined with the theme. The header and footer stay centred.
- **The administration** stays English and left to right (`SetAdminLocale`).
- **Error pages.** The site's not-found page is drawn in `layouts.app` and takes the visitor's direction once their language is known. A page found missing before that — a route-model binding that finds nothing, since `SubstituteBindings` runs before `SetLocale` — and Laravel's own error pages (500, 503, …, `lang="en"`) are English, and left to right.
- **What users write** — titles, descriptions, comments — is laid out in the page's direction; a run in another script inside it is left to the Unicode bidirectional algorithm.
- **Things always written left to right.** An `@name` is isolated in `<bdi>`; the brand (`x-brand-wordmark`) reads its own way (`dir="auto"`), so a Latin site name too long for a phone's header is cut at its end, not its beginning; a URL field (`<x-ui.input type="url">`) is `dir="ltr"`, so `https://` does not turn into `//:https`.

### The rule for templates, styles and scripts

The public site names sides only as start and end: every Blade view outside `filament/`, `components/admin/`, `livewire/admin/` and `dev/`, `resources/css/app.css` and `theme.css`, and `resources/js`.

| instead of | write |
|---|---|
| `ml-*` / `mr-*`, `pl-*` / `pr-*` | `ms-*` / `me-*`, `ps-*` / `pe-*` |
| `left-*` / `right-*` | `inset-s-*` / `inset-e-*` (`inset-x-*` for both) |
| `text-left` / `text-right` | `text-start` / `text-end` |
| `rounded-l` / `rounded-r`, `rounded-tl` / `-tr` / `-bl` / `-br` | `rounded-s` / `rounded-e`, `rounded-ss` / `-se` / `-es` / `-ee` |
| `border-l` / `border-r` | `border-s` / `border-e` |
| `float-left` / `float-right` | `float-start` / `float-end` |
| CSS `margin-left`, `padding-right`, `left`, `right`, `text-align: left` | `margin-inline-start`, `padding-inline-end`, `inset-inline-start`, `inset-inline-end`, `text-align: start` |

On a page read left to right each logical form is drawn exactly as the physical one, so such a page does not change by a pixel. Tailwind 4's `space-x-*` and `divide-x-*` are logical already.

Where there is no logical form — a slide (`translate-x-*`), a transform origin, a background's position — write it once for each direction: `ltr:-translate-x-full rtl:translate-x-full`, `ltr:origin-top-right rtl:origin-top-left`; in a stylesheet, a rule under `[dir='rtl']` (the forms plugin's select chevron is moved to the left that way). Script that swaps such classes swaps both (the post drawer does). Centring with `left-1/2 -translate-x-1/2` lands in the middle either way.

`PhysicalDirectionGuardTest` enforces this and holds the commented allow-lists of what stays physical on purpose. It also stops at public script that reads a horizontal position (`clientX`, `scrollLeft`, …) or hears `ArrowLeft` / `ArrowRight`: on a page read right to left the left arrow means *next*, so such code has to follow the page's direction and say how in the guard's allow-list. None does today.

### Icons and arrows

A sign that points along the line — on, back, reply, undo, a trend over time — turns round on a right-to-left page; up, down and side-less icons (search, a heart, a tick) never do. `<x-ui.icon>` marks `arrow-right`, `reply`, `restore` and `trending` with `rg-mirror-rtl` by itself; any other drawing that points along the line — the pagers' chevrons, a `→` written as text in the game's buttons — carries the class too. `[dir='rtl'] .rg-mirror-rtl` flips it, on a right-to-left page only.

### The game

The pair, the progress dots and the Next button's timer follow the line: on a right-to-left page the first option is drawn on the right and the timer fills from the right. A pick is sent as the option's own position, never as a side, so what is recorded is the same whichever way the pair is drawn.

### Fonts

Nothing is downloaded for Arabic, Urdu or Hebrew. `:root:lang(ar)`, `:lang(ur)` and `:lang(he)` in `app.css` put the system's own faces after Inter in `--font-sans` — Noto on Android and Linux, Geeza Pro or Arial Hebrew on Apple systems, Segoe UI or Tahoma on Windows — so Latin keeps the site's face and each Arabic or Hebrew letter falls through to a face that has it. `--font-mono` lists the same faces before Courier New, a monospaced face that has these scripts and tears joined Arabic apart. Urdu asks for Nastaliq first (Noto Nastaliq Urdu, Jameel Noori Nastaleeq, Urdu Typesetting), which stands far taller than Latin: an Urdu page raises the leading of the page, of every Tailwind text size and of the game's headlines.

These rules sit outside every cascade layer on purpose. Tailwind writes the theme's variables (`--font-sans`, `--text-sm--line-height`, …) outside every layer, and an unlayered rule outranks a layered one whatever its selector: inside `@layer base` an override of a theme variable never applies. `RightToLeftBrowserTest` checks the faces and the Urdu leading in a real browser.

### Adding a language read right to left

Declare it in `config/locales.php` with `'direction' => 'rtl'`; the layout needs nothing more. If Inter and the stacks above do not cover its script, add a `:lang()` stack of system faces to `app.css`.

## Adding a new locale

See *Adding a language* in `docs/i18n/project-translation-lifecycle.md`: the locale is declared with `enabled_by_default => false`, its catalogs and repository content are complete before CI passes, the deploy backfills what it safely can, and an administrator enables it on the Languages page.
