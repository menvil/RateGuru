# Project translation lifecycle

How a language goes from a release to a project's visitors, and who is
responsible for which translation along the way.

## Three facts about a language

| | what it means | where it comes from |
|---|---|---|
| **Installed** | the release ships it | `config/locales.php` + `lang/{code}/` |
| **Complete** | how much of it is translated, for this project | computed live, never stored |
| **Enabled** | whether this project offers it to visitors | `project_settings.enabled_locales` |

Admin → System → **Languages** shows all three for every installed language,
with the project default, and is where a language is enabled, disabled or made
the default (always through `UpdateProjectLocaleSettingsAction`). The page
only reads; opening it never changes the database.

### Enabled by default

Every installed language also declares `enabled_by_default`. It is bootstrap
policy, not project state: while a project has never chosen its languages
(`enabled_locales` is `NULL`) it offers the installed languages that are
`enabled_by_default`. English, Russian and Bulgarian are all `true`, so
existing projects keep offering them. A language added in a later release is
declared `false`, so the deploy that installs it does not offer it to any
existing project. The first change on the Languages page writes an explicit
list, and from then on the project's own choice is all that counts.

## Complete: two different measures

**Application** — the release's own text, `lang/{code}/*.php`. Measured by
`TranslationCatalogInspector`, the same code `TranslationParityTest` enforces
in CI: the same catalogs as English, the same keys and no others, a non-blank
string on every line, the same `:placeholders`, no catalog that is English
line for line, no JSON catalogs. The admin catalog is English-only and not
part of it. A language that breaks this contract cannot be enabled — CI should
never let such a release through, and the Languages page fails closed if one
reaches a server anyway.

**Project content** — this project's own translatable content, read from the
database as it is now (`ProjectTranslationCompleteness`):

| content | counted | translated when |
|---|---|---|
| project settings | site name, tagline, description, singular/plural object name, upload label, feed title | `*_translations[code]` |
| static pages | every page in `config/static-pages.php`, title and content | see below |
| categories | active | `name_translations[code]` |
| rating groups | active — label and description | `label_translations` / `description_translations` |
| rating options | active, not archived, in an active group — label and description | as groups |
| tags | all | `name_translations[code]` |

A field whose reference (English) text is blank needs no translation. English
is complete through the base columns. A translation is any non-blank string —
presence only; nothing judges quality. Content an administrator created
counts exactly like preset content. Inactive and archived content does not,
because no visitor sees it. Missing project translations do not block
enabling: the page warns, and visitors see fallback text where a translation
is missing. The page lists each missing translation with a link to the editor
that already manages it.

### Static pages

`config/static-pages.php` is the repository default and the runtime fallback,
so an untouched page is translated through its configured text — nothing has
to be copied into the database. Once the stored English of a page field
differs from the configured English, the configured translations are of text
that is no longer on the page: every other language then needs its own stored
text for that field. A stored copy of the configured translation does not
count, because the Project Settings form stores the configured text of every
language when it saves; the copy is the old text's translation, not the new
one's.

## Who owns which translation

**Repository-owned** — what a release ships: the application catalogs, every
translatable value of every preset in `config/project_presets.php`, and every
page of `config/static-pages.php`. `TranslationParityTest` and
`RepositoryTranslationParityTest` keep CI red until every installed language
has all of it.

**Project-owned** — whatever the project's database says once an
administrator has touched it: renamed settings, rewritten pages, edited or
newly created categories, groups, options and tags. Only an administrator
translates these, in the existing editors.

## The safe backfill

`php artisan rateguru:translations:backfill` (`BackfillProjectTranslationsAction`)
carries repository-owned translations into a project's database — for every
installed language, enabled or not, so a new language is filled in before
anyone enables it. It writes a translation only when **all** of these hold:

1. the content exists in the database, found by the identity preset
   application uses — a category by `slug`, a rating group by `key`, an option
   by its group's `key` and its own `key`, a tag by `Str::slug()` of its
   English name, a setting by field, a static page by key; content is never
   created;
2. that language has no text for the field yet; a non-blank translation is
   never overwritten;
3. the repository has text for that language;
4. the field's English text in the database is exactly the repository's
   English — compared field by field, not row by row, and exactly, with no
   fuzzy matching.

Where (4) fails, the project changed the text: the repository's translation is
of something the project no longer shows, so the field is left for an
administrator, and the Languages page lists it as missing. Settings and
content come from the preset the project was set up with (`active_preset_key`);
a project on no known preset gets static pages only. A static page the
project never saved needs nothing written — its configured text already
applies.

Each row is re-read under a row lock inside its own transaction before it is
written, so an administrator's edit cannot land between the comparison and
the write. The report counts what was `filled`, `already present`, `skipped as
customized` and `skipped as unknown`; a second run fills nothing.

The backfill only ever adds language entries. It keeps no record of where a
translation came from and never updates one the repository changed later —
that would need ownership metadata, which this deliberately does without.

### On deploy

A normal deployment runs the backfill after Laravel preparation and any
requested migrations, before the `current` switch:

- **Historical releases.** The deploy script deploys older releases too. It
  asks the release whether it has the command (`artisan list --raw`); a
  release that predates it is deployed without the step. A release that has
  the command and fails it fails the deployment.
- **Restore and recovery.** A controlled restore or recovery alignment
  installs the exact code the restored data belongs to and nothing else: it
  never runs the backfill.
- **Rollback.** Nothing to undo. Added language entries are ignored by older
  code, so a deployment that fails its health check and returns to the
  previous release leaves a consistent database.

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
5. CI: application parity and repository content parity both green.
6. Merge and deploy.
7. The deploy's safe backfill fills in German for the content the project
   still shows as the repository wrote it.
8. Languages page: German is installed and disabled, with its project
   completeness.
9. Translate the remaining customized and administrator-created content in
   the existing editors.
10. An administrator enables German.
