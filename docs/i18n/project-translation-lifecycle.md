# Project translation lifecycle

Where each translated text lives, who owns it, and how a language goes from a
release to a project's visitors.

## Who owns which text

Three kinds of localized text, kept apart on purpose:

| | runtime source | changed by | examples |
|---|---|---|---|
| **Application** | `lang/{locale}/*.php` | a release | UI, auth, buttons, forms, validation, errors, mail wording, password reset, email verification, notification wording |
| **Project content** | the database | an administrator | project settings, categories, tags, rating groups and options, static pages |
| **Bootstrap donors** | — (not read at runtime) | a release | `config/project_presets.php`, `config/static-pages.php` |

**Application text** is the release's. It is never copied into the database
and the backfill never touches it: a release that changes `lang/de/mail.php`
changes the next mail. In-app notifications store their message key and
parameters, not a rendered sentence, and are rendered with the catalog of the
running release in the reader's language.

**Project content** is the database's. Visitors are served from it and from
nothing else: a category name is its `name` and `name_translations`, a static
page is `project_settings.static_pages`. Content an administrator creates
needs no counterpart anywhere in the repository.

**Bootstrap donors** seed a new project and lend the backfill the
translations it is missing. They are not a runtime fallback: once a value is
in the database, the database owns it, and a later release that changes the
donor changes nothing an existing project shows.

## English is the default

English (`en`, `config/locales.php` `default`) is the default by system
policy: always installed, always enabled, never disabled, and what a visitor
gets when nothing they chose or their browser asks for is offered. No project
setting changes it — there is no per-project default language.

`fallback` in the same file is English too, but a different thing: the
catalog Laravel falls back to for a missing line.

## Three facts about a language

| | what it means | where it comes from |
|---|---|---|
| **Installed** | the release ships it | `config/locales.php` + `lang/{code}/` |
| **Complete** | how much of it is translated, for this project | computed live, never stored |
| **Enabled** | whether this project offers it to visitors | `project_settings.enabled_locales`, English always |

Admin → System → **Languages** shows all three for every installed language
and is where a language other than English is enabled or disabled (always
through `UpdateProjectLocaleSettingsAction`, which refuses a set without
English). English shows as Enabled and Default, with nothing to disable. The
page only reads; opening it never changes the database.

Enabling always asks first. A language whose project content is complete
becomes available with a plain confirmation; one with missing project
translations is enabled after a warning that visitors may see English where a
translation is missing. A language whose application catalogs break the
contract cannot be enabled. Disabling a language sends its visitors to their
browser's language when that is enabled, and to English otherwise; their
choice stays stored and applies again once the language is enabled again.

### Enabled by default

Every installed language also declares `enabled_by_default`. It is bootstrap
policy, not project state: while a project has never chosen its languages
(`enabled_locales` is `NULL`) it offers English plus the installed languages
that are `enabled_by_default`. A language added in a later release is declared
`false`, so the deploy that installs it does not offer it to any existing
project. The first change on the Languages page writes an explicit list, and
from then on the project's own choice is all that counts — with English always
in it.

## Complete: two different measures

**Application** — the release's own text, `lang/{code}/*.php`. Measured by
`TranslationCatalogInspector`, the same code `TranslationParityTest` enforces
in CI: the same catalogs as English, the same keys and no others, a non-blank
string on every line, the same `:placeholders`, no catalog that is English
line for line, no JSON catalogs. The admin catalog is English-only and not
part of it. A language that breaks this contract cannot be enabled — CI should
never let such a release through, and `UpdateProjectLocaleSettingsAction`
fails closed if one reaches a server anyway.

**Project content** — this project's own translatable content, read from the
database as it is now (`ProjectTranslationCompleteness`):

| content | counted | translated when |
|---|---|---|
| project settings | site name, tagline, description, singular/plural object name, upload label, feed title | `*_translations[code]` |
| static pages | every built-in page, title and content | `static_pages[page][code][field]` |
| categories | active | `name_translations[code]` |
| rating groups | active — label and description | `label_translations` / `description_translations` |
| rating options | active, not archived, in an active group — label and description | as groups |
| tags | all | `name_translations[code]` |

A field whose English is blank needs no translation. English is complete
through the base columns. A translation is any non-blank string — presence
only; nothing judges quality, and nothing compares it with the repository.
Content an administrator created counts exactly like preset content. Inactive
and archived content does not, because no visitor sees it. The Languages page
lists each missing translation with a link to the editor that already manages
it.

## Static pages

Which pages exist is the application's: `about`, `privacy`, `terms` and
`contact` are routes, and the keys of `config/static-pages.php` name them.
What they say is the project's:

```text
config/static-pages.php  →  copied into project_settings.static_pages  →  what visitors read
   (bootstrap / donor)          (once, or by the backfill when missing)       (the only source)
```

A visitor gets, field by field, the stored text of their language, otherwise
the stored English. The Project Settings form shows exactly what is stored — a
language with no text shows empty — and requires the English title and
content of every page, since nothing falls back for English.

## The safe backfill

`php artisan rateguru:translations:backfill` (`BackfillProjectTranslationsAction`)
lends a project the repository's translations it does not have yet — for every
installed language, enabled or not, so a new language is filled in before
anyone enables it. It writes a translation only when **all** of these hold:

1. the content exists in the database, found by the identity preset
   application uses — a category by `slug`, a rating group by `key`, an option
   by its group's `key` and its own `key`, a tag by `Str::slug()` of its
   English name, a setting by field, a static page by key;
2. that language has no text for the field yet; a non-blank translation is
   never overwritten — not when the repository ships a different one later
   either;
3. the repository has text for that language;
4. the field's English text in the database is exactly the repository's
   English — compared field by field, not row by row, and exactly, with no
   fuzzy matching.

| case | outcome |
|---|---|
| the language has text | `already present` — kept as it is |
| missing, English unchanged, repository has it | `filled` |
| missing, the project rewrote the English | `skipped as customized` — the repository's text translates something the project no longer shows |
| content the repository does not know (an administrator created it) | `skipped as unknown` — never filled |

No category, tag, group or option is ever created. Static pages have one
addition: a built-in page — or a field of one — with no English in the
database at all (a page a later release adds, or a row saved before pages had
content) gets the repository's English, and with it the translations. That
fills a gap and replaces nothing; from then on the page is the project's.

Settings and content come from the preset the project was set up with
(`active_preset_key`); a project on no known preset gets static pages only.
Each row is re-read under a row lock inside its own transaction before it is
written, so an administrator's edit cannot land between the comparison and
the write.

A second run fills nothing and changes nothing. That is what makes it safe to
run on every deploy. It keeps no record of where a translation came from —
ownership needs none: whatever is in the database is the project's.

### On deploy

Every ordinary deployment runs the backfill after Laravel preparation and any
requested migrations, so it sees the new release's schema, and before the
`current` switch. It logs

```text
backfilling repository-known project translations
Translation backfill: filled X, already present Y, skipped as customized Z, skipped as unknown N.
```

`filled 0` is a normal, successful run. A failing backfill fails the
deployment before the switch.

- **Historical releases.** The deploy script deploys older releases too. It
  asks the release whether it has the command (`artisan list --raw`); a
  release that predates it is deployed without the step.
- **Restore and recovery.** A controlled restore or recovery alignment
  installs the exact code the restored data belongs to and nothing else: it
  never runs the backfill.
- **Rollback.** Nothing to undo. Added language entries and pages are ignored
  by older code, so a deployment that fails its health check and returns to
  the previous release leaves a consistent database.

On a development checkout, run the command after pulling a release that adds a
language or a page.

Nothing in production translates text automatically; every translation comes
with a release or from an administrator.

## Adding a language

German as the example:

1. Add `de` to `config/locales.php` with its label, native name, flag and
   `enabled_by_default => false`.
2. Add `lang/de/*` — every catalog English has except `admin.php`.
3. Add a `de` value to every translatable value of every preset in
   `config/project_presets.php`.
4. Add `de` to every page of `config/static-pages.php`.
5. Map `de` to `de_DE` in `app/Support/Seo/PostOpenGraph.php`.
6. CI: application parity and repository content parity both green.
7. Merge and deploy.
8. The deploy's backfill fills in German for the content the project still
   shows as the repository wrote it.
9. Languages page: German is installed and disabled, with its project
   completeness.
10. Translate the remaining customized and administrator-created content in
    the existing editors.
11. An administrator enables German.
