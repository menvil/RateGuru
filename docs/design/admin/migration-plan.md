# Admin v1 → Admin v2 Migration Plan

The admin moves from Filament's stock look (Admin v1) to the Admin v2 design in
[`design-contract.md`](design-contract.md) one vertical at a time. There is no big-bang rewrite: at every step the
admin works, and every screen is either fully v1 or fully v2.

## Principles

- **Incremental.** A screen is migrated in one change and stays usable before and after it.
- **Additive CSS.** Admin v2 styles apply only through `.rg-admin-*` classes; nothing restyles Filament's `.fi-*`
  components globally. The theme's only rules for Filament's own components are the two fixes carried over from
  the old inline stylesheet (pointer cursors, the frameless records-per-page chooser). One side effect is
  intended: the theme compiles Tailwind from the admin's own views, so utilities that v1 views already used but
  Filament's prebuilt stylesheet lacked (for example `space-y-2`, `lg:grid-cols-5`) now render as written.
- **Filament stays.** Screens keep Filament's routing, authorization and Livewire; v2 replaces what they look
  like, not the framework under them. A migrated screen confirms with OVL-01 and reports with FBK-01 instead of
  Filament's actions and notifications, which the v1 screens keep.
- **The kit first.** A screen is built from `x-admin.ui.*` components and `.rg-admin-*` primitives. Anything a
  screen needs that the kit does not have is added to the kit, under its reference ID, in the same change.
- **No business changes hidden in a redesign.** A migration that needs a domain change (a new state, a new
  table) does it in its own step.

## Sequence

### Phase 0/1 — design contract, theme and UI kit

**Status: done.**

- The two references are filed under `docs/design/admin/reference/original/`.
- `design-contract.md`, `ui-review-checklist.md` and this plan are written.
- Filament uses the custom Vite theme `resources/css/filament/admin/theme.css` with the `--rg-admin-*` tokens and
  the `.rg-admin-*` component CSS. The old inline stylesheet render hook is gone; its two fixes live in the theme.
- The live developer UI kit, local and testing only, lives at `/admin/dev/ui-kit`: FND-01–04, ACT-01–03, STS-01–03 and STS-05, FRM-01–03,
  NAV-01–04, LAY-01–03, TBL-01, TBL-02, TBL-04–06, FBK-01–02 and DOM-01.
- No existing screen, navigation item, translation form or database table changes.

### Phase 2 — Admin v2 shell and navigation

**Status: done.** The contract's [Production shell](design-contract.md#production-shell) describes what runs.

- Filament's sidebar and top bar are replaced by `App\Livewire\Admin\Sidebar` and `Topbar`, registered through
  the panel API: the 300 sidebar, the 68 rail from 1024 px, an overlay drawer below 1024 px, the 62 top bar with
  breadcrumb, and an account menu that signs out through Filament.
- Navigation is regrouped into Overview, Moderation, Content, Localization, Configuration and System, read from
  Filament's registered navigation so access rules stay where they were.
- Every v1 screen renders inside the v2 shell with its content unchanged; the panel is light only.
- The search sits under the workspace as in the reference, with `⌘K` / `Ctrl+K`, drawing Filament's global
  search over the records each user may find.
- Deliberately not in this step (see the contract's
  [Transitional omissions](design-contract.md#transitional-omissions)): searching settings, pages, languages and
  media, which waits for a product-wide search contract; the Translation Center item, which arrived with its page;
  operational counts, which arrive with each screen's migration; and moving page headers and actions, which
  happens per page.

### Phase 3 — Languages v2

**Status: done.** `/admin/languages` is the first screen drawn entirely in Admin v2; the contract's Localization
section describes what runs.

- The Languages screen as in the prototype: page header with stats (Installed, Enabled, Project translations,
  Missing), status tabs (All, Enabled, Disabled, Incomplete) and a search in the URL, the languages table with
  application and project-content progress (STS-03), the missing-translations drawer with each item's English
  text, and the enable/disable confirmations (light, warning; a broken catalog blocks Enable in the row).
- Filament's table, actions, modals and notifications are gone from the screen; the page draws its own view and
  takes the whole main column.
- Business rules stay in `UpdateProjectLocaleSettingsAction` and the completeness services; only presentation
  moves. No migration, no new table, no change to how translations are stored.
- The kit elements it is the first to need, built as reusable components and shown live in the kit: the
  confirmation dialog (OVL-01), the drawer (OVL-02) and the toast stack (FBK-01), which the shell draws on every
  admin page.
- **Transitional bridge**, closed in Phase 4. Target design: a missing item opens Translation Center. Until it
  existed, the Languages drawer kept links to the existing editors so no editing capability was lost, with Translate
  and Translate all missing drawn disabled; Phase 4 replaced them with Translation Center filters. The translation
  forms in those editors stay as they are.

### Phase 4 — Translation Center

**Status: done.** `/admin/translation-center` translates the project's own content, one target language at a
time; the contract's Localization section describes what runs.

- **One catalog.** `ProjectTranslationCatalog` is the one list of translatable project content
  (`ProjectTranslationUnit`: a stable id from the record id, section, business key, English reference, stored
  translations, limits and placeholders). Translation Center edits these units and `ProjectTranslationCompleteness`
  counts them, so Languages' figures are unchanged and always about what Translation Center can edit.
- **Storage unchanged.** Translations stay where they were — the `{field}_translations` columns and the static
  pages JSON. No migration, no new table. `UpdateProjectTranslationAction` writes one language of one unit, found
  again under a lock on its row, held to that unit's limits, every other language left as it is.
- **The screen** as in the prototype, without AI: the target-language combobox (FRM-11) and its figures; search,
  Section (FRM-10) and Missing only / All (FRM-08) in the browser, kept in the URL; three-column rows with the
  DOM-01 Saved, Missing and Edited · not saved states; drafts in the browser only, with Discard, Discard all and a
  confirmation before switching language; Save and Save & next; the context drawer (OVL-02).
- **Languages bridge done.** Translate on a missing item opens Translation Center on that item, and Translate all
  missing on everything the language is missing; Edit source still opens the content's editor. Catalog issues
  never lead there. Each language's name opens Translation Center on that language, and the Languages table fits
  its card at every width — tightened columns, then one labelled block per language — without scrolling sideways.
- **Kit:** FRM-08, FRM-10 and FRM-11 are reusable components, live in `/admin/dev/ui-kit`.
- **Deliberately not in this step:** AI (Generate missing, AI translate, Regenerate, Save all generated, the AI
  suggestion state); review states; history and source hashes; the missing count on the navigation item (see the
  contract's [Transitional omissions](design-contract.md#transitional-omissions)). The editors that translate in
  place keep their translation tabs and write the same stored values until the cutover.

### Phase 5+ — AI suggestions, workflow and the translation cutover

**Status: in progress** — 5A, the translation engine, is done; 5B, interactive AI suggestions, is next.

In separate steps:

5. **AI suggestions**, in four steps. AI output stays a draft until an administrator saves it, through the same save
   Translation Center uses now.
   - **5A — Translation engine. Status: done.** A reusable, provider-neutral engine in
     `app/Support/TranslationEngine`, described in
     [`docs/architecture/translation-engine.md`](../../architecture/translation-engine.md): one batch-only API
     (`TranslationService::translate(TranslationBatchRequest)`), logical batches of up to 500 items cut into provider
     requests of up to 50 items and 60,000 characters, a provider router driven by `config/translation.php`, OpenAI's
     Responses API with strict structured output as the first provider, and every returned translation checked by id
     and against its item's constraints. It stores nothing, queues nothing, retries nothing and has no fallback.
     Translation Center does not use it yet: the screen is unchanged and manual-only.
   - **5B — Interactive AI suggestions. Status: next.** Translation Center's first AI controls: AI translate and
     Regenerate on a row, the AI suggestion · not saved state, and what the context drawer shows of a request —
     built on the engine, with project content sent as `public_content`.
   - **5C — Background and bulk generation.** Generate missing and Save all generated: a language's missing items
     translated in the background, in batches, keeping the translations a partly failed batch did produce.
   - **5D — Observability, limits and provider policies.** What the engine's call metadata feeds: usage and cost
     visibility, budgets and limits, and the retry and fallback policies the engine deliberately leaves out.
6. **Workflow** — review states, if the product needs them.
7. **Translation cutover** — Categories, Tags, Rating groups and options, Project settings and Static pages
  stop editing every language and show their English reference content, a translation status (locale chips,
  STS-05) and a link to Translation Center. From then on Translation Center is the single admin editor for
  DB-owned translated content. Whether it then also lists inactive and archived content, which no visitor sees
  and which today only its own editor translates, is decided there.

### Then — freeze Admin UI Kit v1

Once Localization runs on v2, the kit is frozen as Admin UI Kit v1: components and tokens change only through a
reviewed design change that updates the contract, the kit and the screens using them together.

### Then — the remaining sections

Migrated one at a time, each building any missing kit element first:

| Section | Kit elements it brings |
|---|---|
| Posts | TBL-03 bulk bar, FRM-07 checkbox, TBL-04 row menu popover |
| Comments | — |
| Reports | DOM-02 report chain, the docked 448 panel |
| Users and user edit | FRM-06 switch, FRM-09 radio cards (FRM-08 segmented control is built) |
| Categories and Tags | FRM-04 locked identifier (FRM-10 filter dropdown is built) |
| Rating groups | STS-04 range slots, FRM-05 number stepper |
| Project settings | — |
| Media diagnostics | — |
| Dashboard | — |

## Definition of done for a migrated screen

- Passes every item of [`ui-review-checklist.md`](ui-review-checklist.md).
- Built only from `x-admin.ui.*` components, `.rg-admin-*` primitives and `--rg-admin-*` tokens.
- No v1 leftovers on the screen; no `.fi-*` overrides added for it.
- Its existing feature tests still pass, with new ones for any behaviour it adds.
