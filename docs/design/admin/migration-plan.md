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
  media, which waits for a product-wide search contract; the Translation Center item, which arrives with its page;
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
- **Transitional bridge.** Target design: a missing item opens Translation Center. Until Translation Center exists,
  the Languages drawer keeps links to the existing editors so no editing capability is lost; Phase 4 replaces these
  links with Translation Center filters. Translate and Translate all missing are already drawn, disabled with the
  reason, so the step that builds Translation Center only has to switch them on. The translation forms in those editors stay as they are.

### Phase 4+ — Translation Center

**Status: next** — Phase 4, the Translation Center foundation (step 1 below).

In separate steps:

1. **Translation Center** — target-language combobox (FRM-11), section filter and the Missing only / All
   segmented control (FRM-08), the three-column translation rows with DOM-01 states, context drawer, Save and
   Save & next, and its item in the Localization section of the navigation. In the Languages drawer, the disabled
   Translate (per item) and Translate all missing become links to Translation Center filtered by language and
   section; Edit source keeps opening the entity's editor.
2. **AI suggestions** — a translation provider behind an interface, Generate missing, Regenerate, Save all
   generated. AI output stays a draft until an administrator saves it.
3. **Workflow** — review states, if the product needs them.
4. **Translation cutover** — Categories, Tags, Rating groups and options, Project settings and Static pages
  stop editing every language and show their English reference content, a translation status (locale chips,
  STS-05) and a link to Translation Center. From then on Translation Center is the single admin editor for
  DB-owned translated content.

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
| Users and user edit | FRM-06 switch, FRM-08 segmented control, FRM-09 radio cards |
| Categories and Tags | FRM-04 locked identifier, FRM-10 filter dropdown |
| Rating groups | STS-04 range slots, FRM-05 number stepper |
| Project settings | — |
| Media diagnostics | — |
| Dashboard | — |

## Definition of done for a migrated screen

- Passes every item of [`ui-review-checklist.md`](ui-review-checklist.md).
- Built only from `x-admin.ui.*` components, `.rg-admin-*` primitives and `--rg-admin-*` tokens.
- No v1 leftovers on the screen; no `.fi-*` overrides added for it.
- Its existing feature tests still pass, with new ones for any behaviour it adds.
