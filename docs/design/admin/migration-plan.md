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
- **Filament stays.** Screens keep Filament's routing, authorization, Livewire, actions and notifications; v2
  replaces what they look like, not the framework under them.
- **The kit first.** A screen is built from `x-admin.ui.*` components and `.rg-admin-*` primitives. Anything a
  screen needs that the kit does not have is added to the kit, under its reference ID, in the same change.
- **No business changes hidden in a redesign.** A migration that needs a domain change (a new state, a new
  table) does it in its own step.

## Sequence

### Phase 0/1 — design contract, theme and UI kit

- The two references are filed under `docs/design/admin/reference/original/`.
- `design-contract.md`, `ui-review-checklist.md` and this plan are written.
- Filament uses the custom Vite theme `resources/css/filament/admin/theme.css` with the `--rg-admin-*` tokens and
  the `.rg-admin-*` component CSS. The old inline stylesheet render hook is gone; its two fixes live in the theme.
- The live developer UI kit, local and testing only, lives at `/admin/dev/ui-kit`: FND-01–04, ACT-01–03, STS-01–03 and STS-05, FRM-01–03,
  NAV-01–04, LAY-01–03, TBL-01, TBL-02, TBL-04–06, FBK-01–02 and DOM-01.
- No existing screen, navigation item, translation form or database table changes.

### Phase 2 — Admin v2 shell and navigation

- Replace Filament's sidebar and top bar with the v2 shell: the 300 sidebar with the navigation structure of the
  contract, the 68 rail below 1280 px, the 62 top bar with breadcrumb, global search with `⌘K`, the user menu.
- Operational counts in navigation (Posts pending, Comments reported, Reports open, Translation missing, Media
  critical).
- The toast stack (FBK-01) and the shared confirmation dialog (OVL-01) and drawer (OVL-02) as components.
- v1 screens render inside the v2 shell unchanged.

### Phase 3 — Languages v2

- The Languages screen as in the prototype: page header with stats, status tabs, the languages table with
  application and project-content progress (STS-03), the missing-translations drawer, and the enable/disable
  confirmations (light, warning, blocked).
- Business rules stay in `UpdateProjectLocaleSettingsAction` and the completeness services; only presentation
  moves.

### Phase 4+ — Translation Center

In separate steps:

1. **Translation Center** — target-language combobox (FRM-11), section filter and the Missing only / All
   segmented control (FRM-08), the three-column translation rows with DOM-01 states, context drawer, Save and
   Save & next.
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
