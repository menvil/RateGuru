# Translations

English is the reference. Every supported locale must match it exactly — same
files, same keys, same `:placeholders` — and `TranslationParityTest` fails the
build until it does.

That guard exists because the failure mode here is silence. Laravel's `__()`
returns the key itself when a line is missing, so a half-finished language
looks fine in review and reaches readers as a mix of their language and raw
`ui.notifications.messages.post_approved` strings. Nothing throws.

## Who owns which text

The catalogs here are the **application's** text: UI, auth, buttons, forms,
validation, errors, mail and notification wording. They are read from these
files at runtime and nowhere else — never copied into the database, never
touched by the translation backfill — so a release that changes
`lang/de/mail.php` changes the next mail.

A project's own text — its settings, categories, tags, rating groups and
options, static pages — lives in the **database**, which visitors are served
from. `config/project_presets.php` and `config/static-pages.php` only seed a
new project and lend the backfill missing translations; they are never a
runtime fallback. See `docs/i18n/project-translation-lifecycle.md`.

## The words for languages

| term | where it lives | what it means |
|---|---|---|
| **supported / installed** | `config/locales.php` + `lang/{code}/` | the application ships the language: a complete catalog, a label, a native name, a flag and `enabled_by_default` |
| **complete** | computed on the Languages page | how much of the application catalogs and of this project's own content (database) the language translates |
| **enabled** | `project_settings.enabled_locales` | the installed languages this project offers its visitors — English always; `NULL` means English and the ones `enabled_by_default`, until the project chooses |
| **default** | `config('locales.default')` | English, by system policy: what a visitor gets when nothing else applies; always enabled, never disabled, set by no project |
| **system fallback** | `config('locales.fallback')` | the catalog Laravel falls back to for a missing line; English too, never used to choose a visitor's language |

A disabled language stays installed: its catalogs are still checked, and its
database translations (categories, tags, rating groups, project settings,
static pages) can still be edited in admin — which is how a language is
prepared before it is offered. Translation editors list every installed
language; everything a visitor can pick or be served uses the enabled ones.

`enabled_by_default` is bootstrap policy, not project state: while a project
has never chosen its languages (`enabled_locales` is `NULL`) it is offered
English and the installed languages declared `enabled_by_default`. A language
a release adds ships with `false`, so installing it never offers it to an
existing project.

`App\Support\Locale\LocaleManager` is the one place these are read:
`supported()`, `default()`, `enabled()`, `isEnabled()`, `fallback()`. Which
languages are enabled is written only by `UpdateProjectLocaleSettingsAction`,
which takes the offered languages and nothing else, refuses a set without
English and an uninstalled code, and always writes an explicit list — so a
project that has chosen never consults `enabled_by_default` again. Admin →
System → **Languages** is where an administrator enables and disables every
language but English; it refuses to enable a language whose application
catalogs break the contract, and always asks before enabling — warning when
the project content is incomplete. `SaveProjectSettingsAction` refuses
`enabled_locales` from any internal caller; presets and the default settings
seeder never change the languages of an existing project.

## Which language a visitor gets

`SetLocale` looks for a choice the visitor made, in this order:

1. the account's chosen language (`users.locale`)
2. the session — a choice made earlier in this visit
3. the `locale` cookie — a choice made on an earlier visit

The first one found decides: served when it is enabled, English when it is
not. The search does not go on to an older choice or to the browser — a
visitor who chose Bulgarian, after Bulgarian is disabled, reads English, not
Russian because their browser prefers it. The choice is kept, never deleted,
and counts again once the language is enabled again.

Only a visitor who has chosen nothing is served by

4. the browser's `Accept-Language`, in quality order; `ru-RU` matches `ru`
5. English, the default.

Choosing a language (`POST /locale`) writes the session and a year-long
`locale` cookie, and the account for a signed-in visitor; a language picked
from the browser is used for the request and never written anywhere. The
admin panel does not take part: it is always English (below).

## Where the languages are declared

`config/locales.php` is the only list of installed languages. The locale
switcher, the settings form, validation and every localization test read it —
the public ones through `LocaleManager::enabled()`. Tests ask for
`supportedLocales()` or `translatedLocales()` (from `tests/Pest.php`) instead
of writing the codes out, and `SupportedLocaleListTest` fails if two or more
supported codes appear as a list anywhere else — a hand-written copy is a place
the next language silently skips.

## Adding a language

1. Add it to `config/locales.php` under `supported`, with its label, native
   name, flag and `enabled_by_default => false`.
2. Create `lang/{locale}/` and translate every file in `lang/en/` except the
   English-only catalogs listed below.
3. Add its text to every translatable value of every preset in
   `config/project_presets.php` and to every page of
   `config/static-pages.php`.
4. Map it to its Open Graph locale in `app/Support/Seo/PostOpenGraph.php`.
5. Run the suite. `TranslationParityTest` lists, by file and key, what the
   catalogs still miss; `RepositoryTranslationParityTest` lists the preset and
   static page values; `PostShowMetaTagsTest` names a language still announced
   to link previews as English. Tests that put every language through the
   public site offer every installed one first
   (`offerEveryInstalledLocale()`), so a language that ships disabled is held
   to them from the start.

Step 1 on its own turns CI red. That is deliberate: a language is either
finished or not installed. Installing is not offering: after the deploy the
language is installed and disabled everywhere, the deploy's safe backfill has
filled in what the repository knows, and an administrator enables it on the
Languages page once the project's own content is translated.

## Keys, never sentences

Every line is read through a stable, namespaced key in one of the catalogs:
`__('auth.login.remember')`, `__('profile.delete.confirm_title')`. Prose keys —
`__('Remember me')`, looked up in `lang/{locale}.json` — are not used, and
there are no JSON catalogs. A prose key is the English sentence itself:
rewording it in a view silently orphans every translation of it, and no
catalog check can see that.

A new public string gets a key in the catalog it belongs to — `auth` for
sign-in and registration screens, `profile` for account settings, `ui` for the
rest of the interface, `mail` for anything a recipient reads — with a line in
every supported language. `TranslationKeyGuardTest` fails for a prose key, for
a key English does not define, and for a key in a catalog only the framework
ships (such as `pagination.*`), since those have an English line and nothing
else.

Messages shown to a reader, including the ones carried by domain exceptions,
are resolved from these catalogs when they are raised, in the reader's
language.

## The admin panel is English-only

Filament `/admin` always renders in English, whatever language the visitor
reads the public site in: `SetAdminLocale` sets it for every panel request,
and it never touches the session, the cookie or the account's `locale`, so
the public site is back in the reader's language on the next request.

Its strings live in `lang/en/admin.php` only. That catalog is excluded from
parity, a new language does not translate it, and a translated copy
(`lang/ru/admin.php`) fails the build as dead weight. Public pages must not
read it — `TranslationKeyGuardTest` checks that too.

## What the parity guard checks

| check | why |
|---|---|
| every public `lang/en/*.php` exists for every locale | a missing file is a whole silent section |
| same keys, no extras | an extra key is usually a rename applied to one language only, invisible until the old key stops being used |
| no blank or non-text lines | worse than a missing key — `__()` returns the blank happily and the UI renders nothing |
| same `:placeholders` | dropping `:username` renders a sentence with the name silently gone |
| no JSON catalogs | keyed by English prose, so nothing above can check them |
| a line for every notification type | the bell builds `ui.notifications.messages.<type>` from the payload, so a missing line renders the key at the reader |

## Emails

Everything a recipient can read lives in `lang/{locale}/mail.php`, including
the subject lines. The two framework emails (verify address, reset password)
are built in `MailLocalizationServiceProvider`, and the notification layout
(`resources/views/vendor/notifications`, `resources/views/vendor/mail`) is
published so its greeting fallback, button fallback line and footer come from
`mail.php` as well — the framework's versions are English prose keys.

Which language a recipient gets is decided by `User::preferredLocale()`: the
language on the account, or — when the account has never chosen one — whatever
the request is already in.

## In-app notifications

Never store a rendered sentence. Notifications store `message_key` and
`message_params`; `NotificationMessage` renders them when the bell is drawn, so
a notification written last month is read in the language chosen today.
