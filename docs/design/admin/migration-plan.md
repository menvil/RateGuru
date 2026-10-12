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

**Status: in progress** — AI suggestions are done: 5A, the translation engine, 5B, interactive AI suggestions, 5C, background and bulk generation, and of 5D, 5D.1, Translation AI telemetry, and 5D.2, the Translation AI page. Budgets, limits and provider policies, and the workflow of step 6, are deferred. The translation cutover, step 7, is done.

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
     This step left Translation Center manual-only; 5B connects it.
   - **5B — Interactive AI suggestions. Status: done.** One row at a time: AI translate on a Missing row, Suggest
     alternative on a Saved row (the saved version stays stored until the alternative is saved) and Regenerate on
     an AI suggestion, the AI suggestion · not saved state, an AI-aware unsaved strip, and “What AI translate sends”
     in the context drawer. The generic engine is reused through a project adapter,
     `ProjectTranslationRequestFactory`, which describes catalog units to it as `public_content` — English source,
     the unit's limits, placeholders and usage, the other installed languages as context — and takes a list, so the
     interactive suggestion is simply a batch of one. `GenerateProjectTranslationSuggestionAction` takes only the
     unit, the language and the stored text the row shows from the browser — the text only compared, never sent —
     finds the unit again and refuses one whose stored translation changed meanwhile. A suggestion
     is a browser-only draft: it is never stored, the figures ignore it, and Save stays explicit, through the same
     `UpdateProjectTranslationAction` as any edit. No background work and no persistence: a reload drops it.
   - **5C — Background and bulk generation. Status: done.** Generate missing and Save all generated. Generate
     missing sends only the language: `StartProjectTranslationGenerationAction` takes every unit the catalog lists
     as missing, describes them through the same factory, plans them with the engine's chunker at the configured
     provider's limits — one chunk per provider request, any number of chunks — and queues one
     `GenerateProjectTranslationChunkJob` per chunk on the existing queue; it never waits for a provider. Each job
     claims its chunk once, re-checks the actor, the provider and every item (still listed, same English by source
     fingerprint, still missing) before sending, makes at most one provider call and is never retried. Suggestions
     are temporary workflow state in Redis (`translation.bulk.cache_store`), plain arrays under text-free keys, 48
     hours from creation — never the database, so no migration — kept per administrator and language, one running
     generation per language. The page restores them on load, polls while generation runs and fills only rows that
     are still missing and untouched; a partly failed batch keeps what it produced. Save, Save all generated (after
     OVL-01) and Discard go through the server, which holds the text: each suggestion is saved on its own through
     `UpdateProjectTranslationAction`, and one whose English or stored translation changed is skipped.
   - **5D — Observability, limits and provider policies.** What the engine's call metadata feeds: usage and cost
     visibility, budgets and limits, and the retry and fallback policies the engine deliberately leaves out. The
     visibility is done; the rest is deferred.
     - **5D.1 — Translation AI telemetry. Status: done.** Every request a provider was actually sent is one row of
       `translation_ai_provider_calls`, recorded by the consumer once the engine has returned — the engine itself
       still stores nothing — through one `TranslationAiCallRecorder`: AI translate, Suggest alternative and
       Regenerate as `interactive_ai`, Generate missing as `generate_missing`. A row is metadata only: provider,
       model, consumer, the installed language and the tag the provider was asked for, status and error code, HTTP
       status, the provider's request id, latency, items sent, succeeded and failed with failures by code, tokens,
       and an estimated cost in USD with the rates it used — never a text, a prompt, context, an item id or a
       credential. Provider success and item success are counted apart. The cost is priced from
       `translation.telemetry.pricing` for the model the provider reported, and is `null` — never $0 — when the
       rates or the tokens are unknown. Recording is best effort: a failed write is logged and dropped, and never
       changes, repeats or withholds a translation. `TranslationAiUsageQuery` reads it back for the page — summary,
       errors by code, an hourly or daily timeline, recent calls — and `translations:purge-ai-calls` deletes rows
       older than 90 days, daily.
     - **5D.2 — Translation AI page. Status: done.** `/admin/translation-ai` (`App\Filament\Pages\TranslationAiPage`),
       the third item of Localization, is read-only technical monitoring drawn in Admin v2 as in the prototype, for
       whoever may manage project settings; the contract's Localization section describes it. Provider health is
       the configuration translations run on now — Not configured, Configured, Healthy or Degraded, from the
       configuration and the calls of the configured model, never from the model filter; one rejected API key with
       no success since is Degraded, any other failure only in a series. Usage and cost, Failures
       and issues and Recent provider calls are the history a period (24 hours, 7 or 30 days) and an optional model
       choose, all from the database through `TranslationAiUsageQuery`, which gained the models recorded, the last
       success, failed calls in series (Isolated, Burst, Ongoing) and calls with issues; failed calls and failed
       items are counted apart, and an unknown cost is never $0. Background generations reads the generation of
       each installed language and the viewer's own through the store's pointers — never a scan — as counts and
       times only, re-read every few seconds only while one runs; an unreachable store takes that block alone.
       Configuration and guardrails shows the values configured, Not available for the worker's own timeout, and
       Not configured for limits and budgets. No provider is asked anything, no setting changes, no table or
       migration is added, and the engine and Generate missing are unchanged. Kit: NAV-04 is the reusable
       `x-admin.ui.pagination`, and FRM-11 options can lead with an icon.
     - **Deferred: budgets, limits and provider policies** — hourly limits, budgets, and the retry and fallback
       policies the engine deliberately leaves out, built when real traffic shows they are needed. Until then
       Translation AI shows them as Not configured or None. Deferred with them: a short-lived, opt-in journal of
       what each call sent and what came back, for reviewing translation quality from Recent provider calls — it
       would keep texts, which telemetry deliberately does not, so it needs its own decision first.
6. **Workflow** — review states, if the product needs them. **Status: deferred.**
7. **Translation cutover. Status: done.** In one change, in the editors as they are: Categories, Tags, Rating
  groups and options, Project settings and Static pages stop editing every language. Their per-language tabs go
  and each keeps its English reference content, with no note in their place: Translation Center is where
  translations are edited, and the navigation already leads there. No data moves: the stored translations stay
  where they are, and Translation Center already edits them, so it becomes the single admin editor for DB-owned
  translated content at once. Saving an editor never touches a translation: the settings page holds none, and
  `SaveProjectSettingsAction` refuses them and takes a static page's English alone, merging it, under the row's
  lock, with the other languages as stored — so one saved in Translation Center while the page was open is kept. Translation Center keeps listing what visitors can see, as it does now: the
  translations of inactive and archived content are kept as they are, edited nowhere while it is off, and come
  back into Translation Center with it. The translation status the prototype shows on these screens (locale
  chips, STS-05, and missing counts) arrives with their Admin v2 migration below.

### Then — freeze Admin UI Kit v1

**Status: in effect.** Localization runs on v2, so the kit is frozen as Admin UI Kit v1: components and tokens
change only through a reviewed design change that updates the contract, the kit and the screens using them
together. Elements the kit does not have yet are still added, each under its reference ID, with the first screen
that needs it.

### Then — the remaining sections

Migrated one at a time, in this order, each building any missing kit element first. The content screens come
first: after the translation cutover their prototype already has no language tabs, and each brings the
translation status that links into Translation Center. The moderation screens follow, then the system pages.

| Order | Section | Kit elements it brings |
|---|---|---|
| 1 | Categories and Tags | FRM-04 locked identifier, TBL-04 row menu popover (FRM-10 filter dropdown is built) |
| 2 | Rating groups and the group editor | STS-04 range slots, FRM-05 number stepper |
| 3 | Project settings and Static pages | — |
| 4 | Posts | TBL-03 bulk bar, FRM-07 checkbox (TBL-04 row menu is built by then) |
| 5 | Comments | — |
| 6 | Reports | DOM-02 report chain, the docked 448 panel |
| 7 | Users and user edit | FRM-06 switch, FRM-09 radio cards (FRM-08 segmented control is built) |
| 8 | Media diagnostics | — |

Each migration also brings its navigation item's operational count (see the contract's
[Transitional omissions](design-contract.md#transitional-omissions)).

Alongside, as a step of its own: Languages and Translation Center were built from the earlier delivery of the
prototype (`RateGuru-Admin-v1.html`) and are reviewed against the current one, which then becomes their
reference.

Planned on their own, once the screens above are on v2: the Dashboard, which is work of its own rather than a
migration, and the prototype's Growth section — Games with the game editor, Experiments and Analytics.

## Definition of done for a migrated screen

- Passes every item of [`ui-review-checklist.md`](ui-review-checklist.md).
- Built only from `x-admin.ui.*` components, `.rg-admin-*` primitives and `--rg-admin-*` tokens.
- No v1 leftovers on the screen; no `.fi-*` overrides added for it.
- Its existing feature tests still pass, with new ones for any behaviour it adds.
