# Translations

English is the reference. Every supported locale must match it exactly — same
files, same keys, same `:placeholders` — and `TranslationParityTest` fails the
build until it does.

That guard exists because the failure mode here is silence. Laravel's `__()`
returns the key itself when a line is missing, so a half-finished language
looks fine in review and reaches readers as a mix of their language and raw
`ui.notifications.messages.post_approved` strings. Nothing throws.

## Four words for languages

| term | where it lives | what it means |
|---|---|---|
| **supported / installed** | `config/locales.php` + `lang/{code}/` | the application ships the language: a complete catalog, a label, a native name and a flag |
| **enabled** | `project_settings.enabled_locales` | the installed languages this project offers its visitors — `NULL` means all of them |
| **project default** | `project_settings.default_locale` | what a visitor gets when nothing about them points elsewhere; always an enabled language |
| **system fallback** | `config('locales.fallback')` | the technical emergency locale and the catalog Laravel falls back to; always installed, not necessarily enabled |

A disabled language stays installed: its catalogs are still checked, and its
database translations (categories, tags, rating groups, project settings,
static pages) can still be edited in admin — which is how a language is
prepared before it is offered. Translation editors list every installed
language; everything a visitor can pick or be served uses the enabled ones.

`App\Support\Locale\LocaleManager` is the one place these are read:
`supported()`, `enabled()`, `isEnabled()`, `projectDefault()`, `fallback()`.
Which languages are enabled is written only by
`UpdateProjectLocaleSettingsAction`, which changes them and the project default
atomically and refuses an empty set, an uninstalled code and a default outside
the set. The Project Settings form may change the default on its own, but only
to a language that is currently enabled; presets and the default settings
seeder never change the languages or the default of an existing project.

## Which language a visitor gets

`SetLocale` takes the first of these that is an **enabled** language:

1. the account's chosen language (`users.locale`)
2. the session — a choice made earlier in this visit
3. the `locale` cookie — a choice made on an earlier visit
4. the browser's `Accept-Language`, in quality order; `ru-RU` matches `ru`
5. the project default
6. the system fallback — only when the project settings themselves resolve to
   nothing usable

A stored choice the project no longer offers is skipped, not deleted, and
counts again if the language is offered again. Choosing a language
(`POST /locale`) writes the session and a year-long `locale` cookie, and the
account for a signed-in visitor; a language picked from the browser is used
for the request and never written anywhere. The admin panel does not take part:
it is always English (below).

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
   name and flag.
2. Create `lang/{locale}/` and translate every file in `lang/en/` except the
   English-only catalogs listed below.
3. Run the suite. It will list, by file and key, whatever is still missing.

Step 1 on its own turns CI red. That is deliberate: a language is either
finished or not installed. Installing is not offering: a project that narrowed
its enabled languages keeps them until it enables the new one, while a project
that never narrowed them (`enabled_locales` is `NULL`) offers it at once.

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
