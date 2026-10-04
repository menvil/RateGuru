# RateGuru Admin v2 Design Contract

This contract defines the visual and interaction rules of the RateGuru admin (Admin v2). It is the admin's
counterpart of [`docs/design/design-contract.md`](../design-contract.md), which governs the public site. The two
are separate design systems on purpose: they share the Inter typeface and nothing else.

Every value below was taken from the two reference files, not invented. Where production deliberately departs
from them, the departure is listed under [Deviations from the reference](#deviations-from-the-reference).

## Source of truth

The authoritative visual and UX references are:

| File | What it defines |
|---|---|
| [`reference/original/RateGuru-Dev-UI-Kit.html`](reference/original/RateGuru-Dev-UI-Kit.html) | Foundations, every component with a stable reference ID, measurements, tokens and rules |
| [`reference/original/RateGuru-Admin.html`](reference/original/RateGuru-Admin.html) | The full admin prototype: shell, navigation and every screen (Dashboard, Posts, Comments, Reports, Users, User edit, Categories, Tags, Rating groups, Rating group edit, Project settings, Languages, Translation Center, Media diagnostics) |

Rules for the reference files:

- They are kept byte for byte as delivered. Do not format, minify or edit them; a test pins their checksums.
- They are self-unpacking bundles. Open them in a browser to view them; the readable source is inside, gzip
  compressed. They are references, not code: nothing from their runtime, data or React-like structure is used
  in production.
- When this document and a reference disagree, the reference wins unless the difference is listed as a
  deliberate deviation below.

Production builds the same design with Laravel, Filament, Livewire, Blade, Alpine and Tailwind:

| Piece | Location |
|---|---|
| Filament theme entry | `resources/css/filament/admin/theme.css` (registered with `->viteTheme()`) |
| Tokens | `resources/css/filament/admin/tokens.css` (`--rg-admin-*`) |
| Component CSS | `resources/css/filament/admin/components.css` (`.rg-admin-*`) |
| Blade components | `resources/views/components/admin/ui/*` (`x-admin.ui.*`) |
| Live developer kit | `/admin/dev/ui-kit` (`App\Filament\Pages\AdminUiKit`), local and testing only |

## Separation from the public design system

| | Public UI | Admin v2 |
|---|---|---|
| Tokens | `--rg-*` in `resources/css/theme.css` | `--rg-admin-*` in `resources/css/filament/admin/tokens.css` |
| Components | `x-ui.*` | `x-admin.ui.*` |
| Developer kit | `/dev/ui-kit` | `/admin/dev/ui-kit` |
| Colour scheme | dark and light themes | light only |

Admin code never uses `x-ui.*`, `--rg-*` public tokens or `resources/css/theme.css`; public code never uses
`x-admin.ui.*` or `--rg-admin-*`. A test guards both directions.

Reusable admin components never hard-code Tailwind palette colours (`bg-gray-50`, `text-gray-600`,
`border-gray-200`, …). Colour comes from an `--rg-admin-*` token through a `.rg-admin-*` class, so a redesign
changes one file.

The admin CSS is additive. Every Admin v2 rule is scoped to a `.rg-admin-*` class; nothing restyles Filament's
own `.fi-*` components, so the existing screens keep their look until each one is migrated. The theme file holds
exactly two intentional fixes to Filament components (pointer cursor on toggles, selects and file pickers; a
frameless records-per-page chooser), kept unlayered as they were before.

## Foundations

### Colour (FND-01)

Ink and greys carry the interface. Colour appears only where it means a status. No gradients, no brand hue
besides ink, no colour on large surfaces. Text on a tint always uses the dark shade of the same hue.

| Token | Value | Use |
|---|---|---|
| `--rg-admin-gray-0` | `#FFFFFF` | card surface |
| `--rg-admin-gray-50` | `#F6F7FB` | app ground, sunken, hover |
| `--rg-admin-gray-100` | `#F3F4F9` | pill surface, segmented track |
| `--rg-admin-gray-200` | `#E1E4EB` | hairline, progress track, pressed text action |
| `--rg-admin-gray-300` | `#CACFD8` | strong border, “—” empty value |
| `--rg-admin-gray-400` | `#99A0AE` | tertiary text, row icons, edited-field border |
| `--rg-admin-gray-500` | `#7A818E` | ghost icon buttons, focus ring |
| `--rg-admin-gray-600` | `#525866` | secondary text, nav icons |
| `--rg-admin-gray-950` | `#0E121B` | ink: strong text, primary buttons, toasts |

Semantic aliases: `--rg-admin-text-strong|secondary|tertiary|inverse`,
`--rg-admin-surface-app|card|sunken|hover|pill|inverse|pressed`, `--rg-admin-border-default|strong|changed`.

### Status colours

| Tone | Background | Foreground | Dot | Meaning |
|---|---|---|---|---|
| success | `#E1FBEC` | `#1E6447` | `#21C06C` | healthy, published, saved |
| warning | `#FFFAEC` | `#7D5D17` | `#FD8549` | awaiting action, missing |
| danger | `#FFEBEC` | `#6E1A22` | `#E5484D` | failed, rejected, critical |
| info (admin addition) | `#EEF2FB` | `#2D4373` | `#2D4373` | system activity and unsaved AI output only |
| due time | — | `#7A4520` | — | ages past their threshold, “Required”, unsaved counts |

The info tint adds `--rg-admin-status-info-border` `#C9D5EE`, `--rg-admin-status-info-field` `#F4F7FD` (an
unsaved AI draft's field) and `--rg-admin-status-info-cell` `#FAFBFE` (its cell). Data colours: track
`#E1E4EB`, progress ink, complete `#21C06C` (only at 100%), missing `#FD8549`, invalid `#E5484D`, running
`#2D4373`.

### Typography (FND-02)

One family, Inter, falling back to `ui-sans-serif`. Eight sizes, three weights.

| Role | Size / line | Weight | Tracking |
|---|---|---|---|
| title | 24 / 32 | 500 | −0.02em |
| stat | 20 / 28, tabular | 500 | −0.02em |
| dialog title, workspace name | 16 / 24 (22) | 600 | −0.01em |
| card title, UI labels, nav, tabs | 15 / 20 | 500 (400 for nav and tabs) | −0.01em |
| body | 14 / 22 | 400 | — |
| meta | 13 / 18 | 400 | — |
| hint, badge | 12 / 16 | 400 (500 badge) | — |
| overline | 11 / 16 uppercase | 500 | .04em content, .12em sidebar |
| mono | 12–13 | 400 | IDs, keys, slugs, paths, exceptions |

Sentence case everywhere; uppercase only in 11px overlines. No sizes outside the scale; no bold 700. Figures use
`font-variant-numeric: tabular-nums`.

### Spacing, layout, radii and elevation (FND-03)

| Group | Values |
|---|---|
| Grid | 4 · 8 · 12 · 16 · 20 · 24 · 28 |
| Sidebar | 300; icon rail 68 below 1280 px |
| Top bar | 62 |
| Page gutter | 28 |
| Card gap | 24 |
| Detail panel | 448 (drawer, docked panel); 480 for long lists |
| Controls | sm 32 · md 36 · input 40 · nav item 40 · badge 24 |
| Rows | table 56–64 (60 default) · table header 40 · menu item 36 · detail row 36 |
| Radii | 5 checkbox and locale chip · 6 badge · 8 item (menu items, chips, text actions, thumbnails) · 10 control · 12 popover · 16 card · full pill |
| Shadows | `shadow-xs` `0 1px 2px rgba(14,18,27,.04)` · `button-dark` `0 1px 2px rgba(14,18,27,.24), inset 0 1px 0 rgba(255,255,255,.08)` · `popover` `0 16px 40px -8px rgba(14,18,27,.14), 0 2px 6px rgba(14,18,27,.04)` |
| Scrims | drawer `rgba(14,18,27,.2)` · dialog `.28` · lightbox `.56` |
| Motion | 120 ms ease for hover; skeleton pulse 1.2 s; no shimmer |

Cards have no shadow. Only popovers, menus, drawers, dialogs and toasts are elevated.

Z-index ladder from the prototype: popovers 50 → menus 60 → overlay panel 150 → drawer scrim 200 → drawer 210 →
dialog and lightbox 300 → toasts 400.

### Icons (FND-04)

- One outline set: Lucide 0.460.0. Production inlines the geometry of the icons it uses in
  `x-admin.ui.icon`; no icon package is installed. Add an icon there, with its Lucide geometry, when a screen
  needs it.
- Stroke 1.75; 3 inside checkboxes.
- Sizes: navigation 18, buttons 14–16, table 15–18, inline 12.
- Colour inherits `currentColor`; navigation gray-600, rows gray-400.
- Navigation icons: `layout-grid` Dashboard · `image` Posts · `message-square` Comments · `flag` Reports ·
  `users` Users · `folder` Categories · `tag` Tags · `star` Rating groups · `globe` Languages · `languages`
  Translation Center · `settings-2` Project settings · `hard-drive` Media diagnostics.
- No emoji in the interface. Country flags appear only next to language names.
- Every icon-only control has a text label: `aria-label` and a tooltip. Decorative icons are `aria-hidden`.

## Shell and navigation

The prototype's shell is the target of the shell migration; it is documented here and shown as specimens in the
kit, but production still uses Filament's shell.

- **Frame:** sidebar 300 · main column. The main column is a top bar (62), an optional header band, then a
  scrolling body on the app ground with a 28 gutter.
- **Sidebar (NAV-01):** workspace switcher (name 16/600, subtitle 13 tertiary); global search (40, “Search
  posts, users”, `⌘K` focuses it; results popover 6 padding, rows 44 high, Escape closes, Enter opens the first
  result); navigation sections; user menu at the bottom (avatar 36, name 15/500, role 13 tertiary).
- **Navigation structure:**

  | Section | Items |
  |---|---|
  | Overview | Dashboard |
  | Moderation | Posts (pending count, warning) · Comments (reported count, warning) · Reports (open count, warning) · Users |
  | Content | Categories · Tags · Rating groups |
  | Localization | Languages · Translation Center (missing count, neutral) |
  | Configuration | Project settings |
  | System | Media diagnostics (critical count, danger) |

- **Items:** 40 high, radius 10, icon 18, 15px label, 4px apart; active item gray-50 fill with ink 500 label and
  icon; sections 10 apart, labelled by an 11px overline at .12em.
- **Counts:** only operational counts — what is waiting. Pill 22 high, radius full, 12/500. Warning means
  awaiting action, danger means critical, neutral means backlog. No totals for their own sake.
- **Rail below 1280 px:** 68 wide, 44×40 icon buttons, separators between sections, an 8px status dot (orange for
  waiting, red for critical) instead of counts, labels as tooltips (“Posts · 8 pending”). An expand button opens
  the full sidebar as an overlay with a drawer scrim.
- **Top bar (NAV-02):** padding 0 24 0 28, breadcrumb (section › page, last item current) on the left, page
  actions on the right with gap 10 and the primary action last. Meta text 13 gray-400 (“Updated 09:41”); unsaved
  state 13 due-colour (“2 unsaved changes”) next to Save. Edit pages save from the top bar; there is no sticky
  bottom save bar.
- **Toasts (FBK-01):** bottom centre of the main column, 24 from the bottom, at most three stacked, 5.2 s.

## Page anatomy

- **Page header (LAY-01):** band padding 22 28 20, white, bottom hairline. Title 24/32 500; one sentence of
  description 14/20 gray-600, max 640; two to four operational stats on the right, separated by vertical
  hairlines with 24 padding. No decorative charts or vanity totals.
- **Status tabs (NAV-03):** 40 high above the table card; 15px labels with a 12/500 count; 2px ink underline on the
  active tab. Counts are totals across all pages. Default tab: Posts → Pending, Reports → Open, others → All.
- **Card (LAY-02):** white, hairline, radius 16, no shadow. Header 16 20 with title 15/500 and subtitle 13
  gray-400; sections split by full-width hairlines; a sunken footer for read-only notes. Edit screens use two
  columns: fluid plus a 448 panel, gap 24.
- **Detail rows (LAY-03):** min 36; icon 16 gray-400, label 14 gray-600, value 14/500 right-aligned; IDs and paths
  in 12px mono with ellipsis and a title. Section labels are 11px overlines with an optional rule and a 13px
  trailing value.

## Tables

- **Structure (TBL-01):** CSS grid rows; header and rows share one `grid-template-columns`, which each screen sets
  for itself. Production renders `role="table"`, `row`, `columnheader` and `cell` on the grid.
- **Header:** 40 high, 11px overline gray-400, bottom hairline.
- **Rows:** min 56–64, bottom hairline, hover gray-50; a selected row is sunken and shows a checked box.
- **Cells:** padding 0 12, first cell 16; two lines: 14/500 primary and 12–13 tertiary meta; actions cell
  right-aligned with padding 0 16 0 8 and 6 between buttons.
- **Overflow:** the grid has a `min-width`; the card scrolls horizontally. Never hide a column the moderator needs.
- **Toolbar (TBL-02):** padding 14 16, gap 10, wraps. Order: search (320) · filters (button + chevron-down, “Field:
  value”) · segmented control · active filter chips (28 high, radius 8, sunken, removable) · result count on the
  right (13 gray-400, “3 posts match”).
- **Bulk bar (TBL-03):** min 52, sunken, under the toolbar; “3 selected”, one primary sm action with the eligible
  count (“Approve selected (2)”), a note about skipped rows, “Clear selection” on the right.
- **Row actions (TBL-04):** the one or two most used actions for the row's status inline as secondary sm buttons;
  everything else in an ellipsis menu (ghost sm). Menus are 248–272 wide, radius 12, padding 6; items carry a 16
  icon, a 14px label and an optional 12px hint. Unavailable actions stay visible, disabled, with the reason as a
  hint. Destructive items are red-950 after a separator. Menus flip up near the bottom and close on outside
  click, scroll and Escape.
- **Pagination (NAV-04):** footer padding 12 16, 13 gray-600; “1–25 of 1,231” tabular; pages 32 min with the
  current one sunken, hairline and 500; ghost sm arrows disabled at the ends. Page size 25.
- **Empty states (TBL-05):** 40 circle icon, title 15/500, body 13/18 gray-400 max 380. A finished queue (“The
  queue is clear”) differs from a search with no results (“No posts match these filters” + Clear filters); an
  action only when it changes the outcome.
- **Loading (TBL-06):** skeleton rows in the real row's grid; bars gray-200 and gray-100, radius full, 8–10 high;
  an opacity pulse of 1.2 s. Toolbar and tabs stay usable.
- **Ages:** time-sensitive rows show age (“Waiting 3 h 12 min”) in the due colour after 2 h.

Grid templates used in the prototype, for reference:

| Screen | Columns | Min width |
|---|---|---|
| Posts | `44px 56px minmax(200px,1fr) 160px 140px 80px 120px 192px` | 992 |
| Comments | `minmax(240px,1fr) 150px 180px 80px 150px 104px 140px` | 1044 |
| Reports | `100px minmax(240px,1fr) 150px 140px 100px 104px 176px` | 1010 |
| Users | `minmax(200px,1fr) 220px 110px 140px 120px 70px 80px 110px` | 1050 |
| Categories | `minmax(240px,1fr) 190px 120px 110px 90px 120px` | 870 |
| Tags | `minmax(240px,1fr) 240px 100px 120px` | 700 |
| Rating groups | `minmax(240px,1fr) 130px 170px 100px 150px 100px 64px 120px` | 1040 |
| Languages | `minmax(220px,1fr) 90px 150px 180px 190px 110px 130px` | 1060 |
| Translation Center | `240px minmax(0,1fr) minmax(0,1fr)` | — |
| Media diagnostics | `104px 160px 104px 104px 96px minmax(200px,1fr) 104px 210px` | 1100 |

## Forms

- **Search (FRM-01):** 40 high, radius 10, search icon 18 gray-400, 15px text; filters as you type, no submit
  button; the placeholder names what is searched (“Search username, name or email”). `⌘K` only on global search.
- **Text field (FRM-02):** label 13/500 6 above; field 40 high, radius 10; hint 12 gray-400 6 below; an error
  replaces the hint in red-950 and says what to do, not just what is wrong.
- **Textarea (FRM-03):** padding 10 12, 14/22, min 84 in dialogs, 64 in panels; a “Required” (due colour until
  valid) or “Optional” tag and a right-aligned 12px tabular counter (“0 / 500”); preset chips 28 high, radius 8,
  selected with an ink border.
- **Locked identifiers (FRM-04):** slugs and keys are generated on create and locked afterwards: sunken field,
  mono value, `lock` 12 with “Locked”, a “Change slug” text link that unlocks and shows a warning notice (“Links to
  /c/old will stop working after you save”). Never change a slug or key silently.
- **Number stepper (FRM-05):** 40 high, 132 wide, 36-wide minus and plus buttons with labels.
- **Switch (FRM-06):** 36×20 track, 16 knob; ink on, gray-300 off; `role="switch"` and `aria-checked`; disabled at
  50% with the reason. Switches that remove access ask for confirmation when turned off.
- **Checkbox (FRM-07):** 18×18, radius 5; ink fill with a 12px white check (stroke 3); a minus for mixed.
- **Segmented control (FRM-08):** gray-100 track with hairline, padding 3, radius 10; segments 32 (28 compact),
  radius 8; the active one white with shadow-xs and 500. For two to four exclusive options.
- **Radio cards (FRM-09):** padding 12, radius 12; selected with an ink border, sunken fill and a 5px ink ring.
- **Filter dropdown (FRM-10):** popover radius 12, padding 6, 200–240 wide; items 36 high, radius 8, check on the
  selected one, optional count.
- **Searchable combobox (FRM-11):** 52-high trigger, radius 12, border gray-300, overline plus value; a 420-wide
  list with search first and a 340 max height. Built for the target language with 30+ languages.

## Status

- **Badge (STS-01):** 24 high, padding 0 8, radius 6, 12/500; a 6px dot only on live healthy states, running
  work and unsaved AI output. Statuses map to tones in one place per screen; the badge knows tones, never
  statuses.

  | Tone | Labels |
  |---|---|
  | success | Published, Visible, Active, Enabled, Completed, Saved, Resolved |
  | warning | Pending, Open, Limited, Missing, Warning, Degraded |
  | danger | Rejected, Banned, Failed, Critical, Not configured |
  | neutral | Hidden, Ignored, Disabled, Inactive, Shadowbanned, Removal finalized |
  | outline | Draft, Deleted by author, Archived, Default |
  | info | AI suggestion · not saved, Running, Info |

  When time matters, a 12px note sits under the badge (“Restorable until 16 Oct”).
- **Counters (STS-02):** navigation pills as above; the report chip in rows is 24 high, radius 6, `flag` 12,
  warning for 1–2 and danger for 3+; zero shows “—” in gray-300.
- **Progress (STS-03):** one bar, no charts. 6 high in rows, 8 in summaries; ink progress, green only at 100%,
  red for an invalid catalog, info blue while running. The figure is always printed next to it.
- **Range slots (STS-04):** a rating group's active options against its minimum and maximum (12×12 slots in
  tables).
- **Locale chips (STS-05):** 18 high, padding 0 5, radius 5, mono 10/500 uppercase; translated chips white with a
  hairline, missing chips amber; a tooltip names the language and value; “1 missing” follows in the due colour.

## Overlays and feedback

- **Confirmation dialog (OVL-01):** 520 wide, radius 16, top 11vh, dialog scrim; Escape or the scrim cancels.
  Header: a 36px tone circle, title 16/24 600, body 14/22. Levels of friction: *light* (optional reason — Reject,
  Ignore, Limit), *firm* (required reason — Hide, Ban, Disable language), *irreversible* (red icon and callout,
  required internal reason, an acknowledgement checkbox or a typed ID, red confirm), *blocked* (explains why and
  offers the alternative; no confirm). The confirm label repeats the action (“Hide post”, never “OK”); the footer
  says where the action is recorded (“Recorded in the moderation log as …”). Reversible, low-risk actions are never
  confirmed; they get a toast with Undo.
- **Drawer (OVL-02):** 448 wide (480 for long lists), full height on the right, drawer scrim; header 62 high
  with title 15/500, 12px subtitle and close; scrolling body split by hairlines; footer with actions on the right
  and destructive actions on the left. Escape or the scrim closes it. Reports docks the same 448 panel inside the
  layout.
- **Toast (FBK-01):** ink surface, white 14px, radius 12, popover shadow; icon green for success, `#FF9AA2` for
  errors, gray-400 for info; Undo only for reversible actions.
- **Inline notice (FBK-02):** radius 10, padding 10 12, 13/18, icon 16. Info (`info`), warning (`circle-alert`),
  danger (`triangle-alert`, 500, irreversible dialogs only), success (`circle-check`); a full-width strip variant
  under a card toolbar.

## Localization

Localization is the first production vertical of Admin v2:

```text
Localization
├── Languages
└── Translation Center
```

**Invariant.** Translation Center becomes the single admin editor for DB-owned translated content. After the
translation cutover, Categories, Tags, Rating groups and options, Project settings and Static pages hold their
English reference content plus a translation status and a link to Translation Center (“Translations are edited
in Translation Center.”) — not an editor for every language. Until that cutover the existing translation forms
stay exactly as they are.

**Languages** (prototype):

- Page header stats: Installed · Enabled · Project translations (%) · Missing. Tabs: All · Enabled · Disabled ·
  Incomplete.
- Table: Language (flag in a 32 circle, English and native name) · Locale (mono) · Status (Default, Enabled or
  Disabled, with “Reference language” / “Disabled 21 Sep” notes) · Application (catalog bar and “100% · valid” or
  “96% · catalog invalid”) · Project content (bar and “63% · 66 of 104”) · Missing (“38 missing ›”, opens a drawer)
  · Actions. English shows a lock and “Always on”.
- Interface strings are release-managed and read-only here; project content is translated in Translation Center.
- Enabling with missing project translations asks for confirmation and offers “Review missing”; an invalid
  application catalog blocks enabling and explains why; disabling explains that preferences and stored
  translations are kept.
- The missing-translations drawer is 480 wide, groups items by section and links each to Translation Center.

**Translation Center** (prototype):

- Top bar: “Generate missing (n)” (sparkles) and the primary “Save all generated (n)”.
- Header: the target-language combobox (FRM-11) and stats Total items · Translated · Missing plus a completion
  bar. One target language at a time; never a column per language.
- Toolbar: search (“Search source, key or translation”), Section filter, a “Missing only / All” segmented control
  and the result count. An info strip counts unsaved drafts (“2 AI suggestions and 1 edit not saved yet. Nothing
  changes for visitors until you save.”) with “Discard all”.
- Rows (`240px minmax(0,1fr) minmax(0,1fr)`): item (section badge, entity, field, mono key, constraint chips such
  as “Max 24”, “Single line”, `{contact_email}`, a Context link) · English reference in a sunken box with its
  length and “Edit source ↗” · the target field.
- The context drawer (448) shows where the text appears, its constraints, other languages (as AI context only)
  and exactly what AI translate sends.
- Switching the target language with unsaved drafts asks before discarding them.

**Translation field states (DOM-01)** — they must never look alike:

| State | Badge | Field | Note and actions |
|---|---|---|---|
| Saved | success + dot “Saved” | white | “Stored translation” |
| Missing | warning “Missing” | white, placeholder “Missing · type a translation or use AI translate” | “Visitors see the English text” · AI translate |
| AI suggestion · not saved | info + dot | `#F4F7FD` field, `#C9D5EE` border, `#FAFBFE` cell | “Generated 09:38” · Regenerate · Discard |
| Edited · not saved | outline | gray-400 border | “Saved version is kept until you save” · Discard |

Errors turn the border red-950, name the fix (“Keep {contact_email}”, “4 over the limit”) and disable Save. The
counter turns red past the limit. AI output is a draft until an administrator saves it: it is never written to
the database before Save.

## Responsive rules

- Verified widths: 1440, 1280 and 1024.
- At 1280 px and wider the sidebar is 300; below it collapses to the 68 rail.
- Tables keep every column and scroll horizontally inside their card.
- Toolbars wrap; auto-fit grids reflow; drawers cap at `100vw`.
- Reports docks its 448 panel only when the main column leaves at least 1048 px for the table; otherwise the panel
  floats over the list.

## Accessibility baseline

- Icon-only controls have an `aria-label` and a tooltip; decorative SVGs are `aria-hidden`.
- Every input has an associated `<label>`; hints and errors are tied with `aria-describedby`; errors set
  `aria-invalid`.
- Disabled controls are really `disabled`, not only greyed out. A disabled link renders as a disabled button.
- Buttons are `<button>`, links are `<a>`.
- Every focusable control shows a visible focus state.
- State is never encoded by colour alone: badges carry words, notices carry tone icons, missing locale chips carry
  “missing” for screen readers, errors carry an icon and text.
- No ARIA that promises behaviour the markup does not have: menus, comboboxes and tabs get their roles only
  together with their keyboard behaviour.

## Deviations from the reference

| Topic | Reference | Production | Why |
|---|---|---|---|
| Focus | fields darken their border to gray-300; buttons show none | fields: gray-500 border with a 3px halo; controls: 2px gray-500 outline offset 2 on `:focus-visible` | the reference focus is too faint to find with a keyboard |
| Text field size | FRM-02 spec table says 15px | 14px, as the reference's rendered TextField and every textarea | follows the rendered component |
| Ghost button | not a Button variant; drawn as a text action (ACT-03) | `variant="ghost"` of `x-admin.ui.button`, 8px radius | one component for all buttons |
| Status tabs | Sable Tabs use `role="tab"` without tab panels | links with `aria-current` or toggles with `aria-pressed` | they filter a list rather than switch panels |
| Dark mode | not defined | the admin is light only; the kit draws its own light canvas | no reference to follow |
| Dialogs, drawers, row menus, combobox, toasts stack | live in the prototype | specified here; built when the first screen needs them | no production screen uses them yet |

## Reference ID registry

The Dev UI kit reference names 41 elements. IDs never change and are never reused. The production kit at
`/admin/dev/ui-kit` shows the elements built so far under the same IDs; nothing is shown under an ID that is not
built.

| Group | IDs | In the production kit |
|---|---|---|
| Foundations | FND-01 Colour tokens · FND-02 Typography · FND-03 Spacing, radii, elevation · FND-04 Icons | all |
| Actions | ACT-01 Button · ACT-02 Icon button · ACT-03 Links and text actions | all |
| Status | STS-01 Status badge · STS-02 Counters · STS-03 Progress bar · STS-04 Active range slots · STS-05 Locale chips | all but STS-04 |
| Forms | FRM-01 Search field · FRM-02 Text field · FRM-03 Textarea with counter · FRM-04 Locked identifier · FRM-05 Number stepper · FRM-06 Toggle switch · FRM-07 Checkbox · FRM-08 Segmented control · FRM-09 Radio cards · FRM-10 Filter dropdown and chips · FRM-11 Searchable combobox | FRM-01–03 |
| Navigation | NAV-01 Sidebar · NAV-02 Top bar and breadcrumb · NAV-03 Status tabs · NAV-04 Pagination | all (NAV-01 and NAV-02 as specimens) |
| Layout | LAY-01 Page header · LAY-02 Card and sections · LAY-03 Detail rows and section labels | all |
| Tables | TBL-01 Table row · TBL-02 Table toolbar · TBL-03 Bulk action bar · TBL-04 Row actions and menu · TBL-05 Empty states · TBL-06 Loading skeleton | all but TBL-03 (TBL-04's menu as a static surface) |
| Overlays & feedback | OVL-01 Confirmation dialog · OVL-02 Drawer · FBK-01 Toast · FBK-02 Inline notice | FBK-01 (static) and FBK-02 |
| Admin-specific | DOM-01 Translation field states · DOM-02 Report chain | DOM-01 |

The [migration plan](migration-plan.md) says when the remaining elements are built.
