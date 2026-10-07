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
own `.fi-*` components, so the existing screens keep their design until each one is migrated. The theme file
holds the only rules that touch Filament's own elements, all unlayered: two fixes carried over from the old
inline stylesheet (pointer cursor on toggles, selects and file pickers; a frameless records-per-page chooser),
and one that fits Filament into the Admin v2 shell (hiding Filament's own sidebar overlay, which the shell
replaces). Because the theme compiles Tailwind
from `app/Filament` and `resources/views/filament`, utilities those views already used but Filament's prebuilt
stylesheet lacked now take effect as written.

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
| `--rg-admin-gray-400` | `#99A0AE` | edited-field border, info icons on ink; the reference's tertiary text colour (production text uses `--rg-admin-text-tertiary`, below) |
| `--rg-admin-gray-500` | `#7A818E` | ghost icon buttons, focus ring |
| `--rg-admin-gray-600` | `#525866` | secondary text, nav icons |
| `--rg-admin-gray-950` | `#0E121B` | ink: strong text, primary buttons, toasts |

Text tokens:

| Token | Value | Contrast on white / app ground |
|---|---|---|
| `--rg-admin-text-strong` | `#0E121B` (gray-950) | 18.73:1 / 17.50:1 |
| `--rg-admin-text-secondary` | `#525866` (gray-600) | 7.13:1 / 6.66:1 |
| `--rg-admin-text-tertiary` | `#68707D` (production; reference gray-400 `#99A0AE`) | 5.00:1 / 4.67:1 |
| `--rg-admin-text-inverse` | `#FFFFFF` (gray-0) | on ink |

Tertiary text deliberately departs from the reference; see
[Tertiary text contrast](#tertiary-text-contrast). Other semantic aliases:
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
- Colour inherits `currentColor`; navigation gray-600 (secondary), rows tertiary.
- Navigation icons: `layout-grid` Dashboard · `image` Posts · `message-square` Comments · `flag` Reports ·
  `users` Users · `folder` Categories · `tag` Tags · `star` Rating groups · `globe` Languages · `languages`
  Translation Center · `settings-2` Project settings · `hard-drive` Media diagnostics.
- No emoji in the interface. Country flags appear only next to language names.
- Every icon-only control has a text label: `aria-label` and a tooltip. Decorative icons are `aria-hidden`.

## Shell and navigation

This section is the target design from the prototype. Production builds it as described under
[Production shell](#production-shell) below, which also lists what is deliberately not there yet.

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
  actions on the right with gap 10 and the primary action last. Meta text 13 tertiary (“Updated 09:41”); unsaved
  state 13 due-colour (“2 unsaved changes”) next to Save. Edit pages save from the top bar; there is no sticky
  bottom save bar.
- **Toasts (FBK-01):** bottom centre of the main column, 24 from the bottom, at most three stacked, 5.2 s.

### Production shell

Every admin page except the developer kit is drawn inside the Admin v2 shell. Page content is still Filament's
until each page is migrated, so a page can show both the shell's breadcrumb and its own legacy heading for now.
Languages and Translation Center are migrated: their content is Admin v2 too.

- **Components:** `App\Livewire\Admin\Sidebar` and `App\Livewire\Admin\Topbar`, registered with the panel's
  `sidebarLivewireComponent()` and `topbarLivewireComponent()`. Filament keeps everything else: routing,
  authentication, authorization, page and resource rendering, actions, modals and notifications. No Filament view
  is published or copied, and Filament's own sidebar and top bar classes are not restyled.
- **Navigation source:** Filament's registered navigation (`filament()->getNavigation()`), reshaped by
  `App\Filament\Support\AdminShellNavigation`. Each resource and page still declares its section, label, sort
  and access; the sidebar draws only what Filament considers visible for the signed-in user, so a destination a
  user may not open is never offered. The section order is `AdminNavigationGroup::all()`. Nothing in the shell's
  Blade hard-codes a URL or an authorization rule.
- **Icons:** `AdminShellNavigation::ICONS` maps each destination (by its resource or page class) to its
  `x-admin.ui.icon`, in one place; a test fails if a production destination has none.
- **Widths:** one markup for every width. From 1280px the 300 sidebar; from 1024px the 68 rail (workspace mark,
  expand button, icons with section separators, avatar); below 1024px no sidebar, and a menu button in the top
  bar. The sidebar component's root is a spacer as wide as the sidebar inside Filament's layout row, so the page
  beside it is never covered; the top bar shifts by the same width.
- **Overlay:** the rail's expand button and the top bar's menu button open the same sidebar as a 300 overlay over
  the drawer scrim. Escape, the scrim, the close button and following a link close it; focus returns to the button
  that opened it. The scrim is not focusable.
- **Rail tooltips:** one tooltip element names the icon under the pointer or keyboard focus; each link also keeps
  its label for screen readers, so the tooltip is never the only name.
- **Workspace:** a static “RateGuru · Admin” identity. There is one workspace, so there is no switcher and no
  chevron. The sidebar header is as tall as the top bar (62), so their hairlines run as one line.
- **Search:** under the workspace, as in the reference: the FRM-01 field (40 high, “Search posts, users”, a `⌘K`
  hint, `Ctrl K` off macOS) drawing Filament's global search through `App\Livewire\Admin\GlobalSearch`. Which
  resources are searched and what each user may find stay Filament's. `⌘K` / `Ctrl+K` focuses the field from
  anywhere except a rich text editor, which keeps the shortcut for links, and an open dialog or drawer, which keeps
  focus inside itself; below 1280px it opens the sidebar first.
  Results open beneath the field as in the reference — rows 44 high, a 28 icon of the record's kind, title
  14/500, kind 12 tertiary — with the first one highlighted: Enter opens it, Down and Up move through the list,
  Escape closes it.
- **Account:** avatar initials, name and role of the signed-in user (no query; the user is already
  authenticated). The button opens a small menu with the name, email and **Sign out**, which posts to Filament's
  own logout route. There is no profile page, so none is offered.
- **Breadcrumb:** section › destination, taken from the active navigation item without loading any record. On a
  create or edit page the destination is the last step, linked back to its list.
- **Top bar right side:** the `TOPBAR_END` render hook, scoped to the current page and its resource, where a
  migrated page puts its actions. A page without a permanent action, such as Languages, leaves it empty.
- **Toasts (FBK-01):** the sidebar component carries the one toast stack of every admin page
  (`x-admin.ui.toast-stack`). It is not a stacking context and it knows the width the sidebar takes, so the stack
  centres on the main column at every width. A page raises a toast with a browser event; see
  [Overlays and feedback](#overlays-and-feedback).
- **Light only:** the panel's dark mode is off. Admin v2 defines no dark theme, and with Filament's user menu
  gone a dark page inside a light shell would leave no way back.
- **Layers:** top bar and sidebar 30, scrim 35, open drawer 36, rail tooltip 37 — all below Filament's modals (40)
  and notifications (50). The search results list sits above the navigation inside the sidebar. The Admin v2
  overlays of a migrated screen follow the reference ladder above all of these: content drawer 200 (its scrim
  included), confirmation dialog 300, toasts 400. A migrated screen has no Filament modal left for them to meet.

### Transitional omissions

These are the current migration state, not changes to the target design:

- **Global search.** The sidebar search runs Filament's global search, so it finds records of the resources the
  user may search: posts, comments, users, tags, categories and rating groups. The reference's index also lists
  settings, static pages, languages and media assets; those wait for a product-wide admin search contract rather
  than a partial imitation.
- **Operational counts** (posts pending, comments reported, reports open, missing translations, media critical)
  are not shown. The shell draws a badge whenever a navigation item provides one, but adds no count queries of
  its own; each count arrives with the migration of its screen.
- **Translation Center's missing count.** The reference badges Translation Center with the missing translations
  (neutral). Counting them means reading every unit of project content, and the navigation is drawn on every
  admin page, so the item has no badge for now rather than a full completeness read per page or a counter kept
  for it alone. Translation Center itself always shows the exact figures, and so does Languages.
- **Legacy page headers, breadcrumbs and actions** stay inside each page until that page is migrated; page
  actions are not moved into the top bar. Languages is migrated and has none of them left.
- **Translation editors in place.** Translation Center edits every unit of project content, but until the
  translation cutover (Phase 7 of the [migration plan](migration-plan.md)) the Categories, Tags, Rating groups
  and options, Project settings and Static pages editors keep their translation tabs. Both write the same stored
  value, so either one shows what the other saved; there is no second copy to keep in step.

## Page anatomy

- **Page header (LAY-01):** band padding 22 28 20, white, bottom hairline. Title 24/32 500; one sentence of
  description 14/20 gray-600, max 640; two to four operational stats on the right, separated by vertical
  hairlines with 24 padding. No decorative charts or vanity totals. On a phone the stats stay on one line, sharing
  the width: a label wraps between words, never inside one, and the figures line up at the bottom; only a screen
  narrower than 360 px puts them in two columns.
- **A migrated screen in production:** its Filament page draws the whole view in Admin v2 (`.rg-admin-screen`)
  instead of inside Filament's page wrapper, so Filament's heading, breadcrumbs and action modals are not on it and
  the LAY-01 band is the page's only heading. It sets `$maxContentWidth = 'rg-admin-main'`, the class Filament puts
  on its `<main>`, so the band runs edge to edge under the top bar and the content sits on the app ground with the
  28 gutter (16 on a phone). Nothing restyles a `.fi-*` class for it.
- **Status tabs (NAV-03):** 40 high above the table card; 15px labels with a 12/500 count; 2px ink underline on the
  active tab. Counts are totals across all pages. Default tab: Posts → Pending, Reports → Open, others → All.
- **Card (LAY-02):** white, hairline, radius 16, no shadow. Header 16 20 with title 15/500 and subtitle 13
  tertiary; sections split by full-width hairlines; a sunken footer for read-only notes. Edit screens use two
  columns: fluid plus a 448 panel, gap 24.
- **Detail rows (LAY-03):** min 36; icon 16 tertiary, label 14 gray-600, value 14/500 right-aligned; IDs and paths
  in 12px mono with ellipsis and a title. Section labels are 11px overlines with an optional rule and a 13px
  trailing value.

## Tables

- **Structure (TBL-01):** CSS grid rows; header and rows share one `grid-template-columns`, which each screen sets
  for itself. Production renders `role="table"`, `row`, `columnheader` and `cell` on the grid.
- **Header:** 40 high, 11px overline tertiary, bottom hairline.
- **Rows:** min 56–64, bottom hairline, hover gray-50; a selected row is sunken and shows a checked box.
- **Cells:** padding 0 12, first cell 16; two lines: 14/500 primary and 12–13 tertiary meta; actions cell
  right-aligned with padding 0 16 0 8 and 6 between buttons.
- **Overflow:** the grid has a `min-width`; the card scrolls horizontally. Never hide a column the moderator needs.
  The scroll container is positioned, so screen-reader-only text in the cells scrolls with it instead of widening
  the page.
- **Where a search runs:** a bounded list that is already on the page (installed languages, the units of one
  target language in Translation Center) is searched in the browser; a large or paginated list (posts, users, comments) is searched by a server query; the sidebar's global
  search keeps its own backend.
- **Toolbar (TBL-02):** padding 14 16, gap 10, wraps. Order: search (320) · filters (button + chevron-down, “Field:
  value”) · segmented control · active filter chips (28 high, radius 8, sunken, removable) · result count on the
  right (13 tertiary, “3 posts match”).
- **Bulk bar (TBL-03):** min 52, sunken, under the toolbar; “3 selected”, one primary sm action with the eligible
  count (“Approve selected (2)”), a note about skipped rows, “Clear selection” on the right.
- **Row actions (TBL-04):** the one or two most used actions for the row's status inline as secondary sm buttons;
  everything else in an ellipsis menu (ghost sm). Menus are 248–272 wide, radius 12, padding 6; items carry a 16
  icon, a 14px label and an optional 12px hint. Unavailable actions stay visible, disabled, with the reason as a
  hint. Destructive items are red-950 after a separator. Menus flip up near the bottom and close on outside
  click, scroll and Escape.
- **Pagination (NAV-04):** footer padding 12 16, 13 gray-600; “1–25 of 1,231” tabular; pages 32 min with the
  current one sunken, hairline and 500; ghost sm arrows disabled at the ends. Page size 25.
- **Empty states (TBL-05):** 40 circle icon, title 15/500, body 13/18 tertiary max 380. A finished queue (“The
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

- **Search (FRM-01):** 40 high, radius 10, search icon 18 tertiary, 15px text; filters as you type, no submit
  button; the placeholder names what is searched (“Search username, name or email”). `⌘K` only on global search.
  A list's search is clearable: while the field holds text, an `x` 16 button inside it (“Clear search”) empties it
  in one click and leaves focus in the field.
- **Text field (FRM-02):** label 13/500 6 above; field 40 high, radius 10; hint 12 tertiary 6 below; an error
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

In production the segmented control, the filter dropdown and the combobox are Blade components, each with the
keyboard behaviour its ARIA role promises and its value exposed to `x-model` (`x-modelable`):

- **`x-admin.ui.segmented` (FRM-08):** a `radiogroup` of two to four `radio` buttons; only the checked one is a tab
  stop, the arrow keys, Home and End move the choice with the focus, and the checked option is raised and 500,
  not only differently coloured.
- **`x-admin.ui.filter-dropdown` (FRM-10):** a menu button (“Section: All”, `aria-haspopup="menu"`) over a 240 wide
  menu of `menuitemradio` items with a check and an optional count, which may be an Alpine expression kept live
  by the screen. Enter, Space or Down open it on the checked item; Up, Down, Home and End move; Escape returns to
  the button; a choice, Tab or a click outside closes it.
- **`x-admin.ui.combobox` (FRM-11):** the trigger (`aria-haspopup="listbox"`) opens the list with focus in its
  search, an ARIA `combobox` over a `listbox` whose active option is its `aria-activedescendant`; the search
  filters in the browser, Up and Down move, Enter chooses, Escape returns to the trigger, and an always-present
  status region says when nothing matches. Choosing dispatches a cancelable `choose` event before the value is
  taken, so a screen can ask first or make the change on the server instead.

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
  errors, gray-400 for info (on ink); Undo only for reversible actions.
- **Inline notice (FBK-02):** radius 10, padding 10 12, 13/18, icon 16. Info (`info`), warning (`circle-alert`),
  danger (`triangle-alert`, 500, irreversible dialogs only), success (`circle-check`); a full-width strip variant
  under a card toolbar.

In production the confirmation dialog, the drawer and the toast stack are Blade components:

- **`x-admin.ui.confirm-dialog` (OVL-01)** and **`x-admin.ui.drawer` (OVL-02)** are open for as long as they are
  rendered; the screen decides when to draw them, usually from Livewire state, and they know nothing about what
  they confirm or list. Escape, the scrim, the close button and a Cancel that calls `dismiss()` hide them at once
  and dispatch a `dismiss` event for the screen to forget them (`x-on:dismiss="$wire.closeConfirmation()"`); an
  action that replaces one overlay with another calls `hide()`. Both are `role="dialog"` with `aria-modal`, named by
  their title (the dialog also described by its body). While one is open, Alpine's focus trap (`x-trap`, already
  in the Livewire runtime) moves focus inside, keeps it there and returns it to the trigger on close; the rest of
  the page is hidden from assistive technology and does not scroll. When the trigger has left the page by then —
  its row filtered away by the change it confirmed — focus goes to the page's heading. Both sit on
  `x-admin.ui.overlay`, the layer that holds this shared behaviour; it is not used on its own. The dialog has the `default` (light) and
  `warning` tones; a blocked dialog is one with no confirm action. The drawer is 448 wide, or 480 with `wide`,
  never wider than the screen, with an optional `leading` and `footer`.
- **`x-admin.ui.toast-stack` (FBK-01)** is drawn once per page by the shell. A page raises a toast with a browser
  event — `$this->dispatch('rg-admin-toast', message: '…', tone: 'success')` from Livewire, or `$dispatch` from
  Alpine — with the tone `success`, `error` or `info`. At most three stay on screen, each for 5.2 s, waiting while
  the pointer or keyboard focus is on the stack. A status region (success, info) and an alert region (error), always
  present, announce each message once; the toasts themselves are not live regions. A toast is never the only record
  of a lasting error: the screen shows it too.

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

**Languages** (production, migrated): `/admin/languages` (`App\Filament\Pages\LanguagesPage`) is drawn entirely in
Admin v2, without Filament's table, actions, modals or notifications. Business rules stay in
`UpdateProjectLocaleSettingsAction`, `LocaleManager`, `TranslationCatalogInspector` and
`ProjectTranslationCompleteness`; every Livewire method checks the language against what is installed and offered
now, and the action remains the final safeguard.

- Header stats: Installed (installed languages), Enabled (offered languages, English included), Project translations
  (translated ÷ required over every installed language except English, disabled ones included, rounded down; 100%
  when nothing needs translating) and Missing (the missing project translations of the same languages).
- Tabs All · Enabled · Disabled · Incomplete, counted over every installed language; Incomplete is an application
  catalog that breaks the contract or project content without a translation. The tab is in the query string
  (`?status=…`, none for All) and in the browser history.
- The table card's toolbar (TBL-02) has a clearable search over the English name, native name and locale code
  within the open tab (“Search language or locale code”) and counts the result (“4 of 4 installed”); a search with no result offers
  Clear search. The search runs in the browser over the rows of the open tab already on the page: typing sends no
  Livewire request, so it never re-reads the catalogs or the project's content. The query is kept in the address
  (`?q=…`) with `history.replaceState`, opens filtered from a link, travels with the tab links and comes back with
  Back; only the tab is decided on the server. No pagination: installed languages are a bounded configuration
  list.
- The name of every language but English opens Translation Center on that language (`?locale=de`), complete or
  not: a stored translation can always be improved. English, the reference, has nothing to translate.
- The table fits its card at every width. From the reference's 1060 the columns tighten to fit a card beside the
  rail (1280 and 1024 keep the table); below 876 each language becomes a block — names, code and action first,
  then status, application, project content and missing, each under its own label — four across on a tablet and
  two on a phone. The screen never scrolls sideways.
- Rows as in the prototype, with these differences. Status shows Enabled (success, dot) or Disabled (neutral) with
  “Offered to visitors” / “Not offered to visitors”; English shows Default (success, dot) and “Reference language”,
  two lines like every other row.
  Application prints “100% · valid”, or “N% · catalog invalid” on a red bar for any catalog with issues, whatever
  its percentage. Missing opens the drawer from “N missing ›”, or from “Catalog issue ›” when only the catalog is
  wrong. Enable for a broken catalog stays visible, disabled, with “Fix the release first.”
- Confirmations: enabling a complete language is light; enabling with missing project content is a warning with
  Review missing (which opens the drawer and enables nothing) and Enable anyway; disabling is a warning that says
  where visitors go and that their preference and the stored translations are kept. No reason is asked for.
- The drawer is headed “Missing in German — Deutsch” with “18 of 104 project strings missing · disabled”. It lists
  the catalog's issues first (the first 50, then “… and N more”), then the missing content by section in the
  domain's order. Each item reads “Entity · Field” (the field left out where it would repeat the entity, as for a
  project setting) over “EN “…”” — the start of the English text it is translated from — with Edit source, which
  opens the editor of its English text, and Translate, which opens Translation Center on that item: its language,
  its section, Missing only and the item itself (`?locale=de&section=categories&mode=missing&unit=categories:17:name`).
  The footer's Translate all missing opens Translation Center on everything the language is missing
  (`?locale=de&mode=missing`), and is left out when no project content is missing. Catalog issues are the release's
  to fix: nothing sends them to Translation Center, which edits project content only. The items are
  `ProjectTranslationCompleteness`'s, counted over the same catalog Translation Center edits.
- Results are Admin v2 toasts: “German enabled”, “German disabled”, and an error toast for a refusal.

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

**Translation Center** (production): `/admin/translation-center` (`App\Filament\Pages\TranslationCenterPage`) is
drawn entirely in Admin v2, without Filament's table, actions, modals or notifications, and is the second item of
the Localization section, after Languages, with the `languages` icon. It opens to whoever may manage project
settings (`manage-project-settings`), the boundary Languages uses.

- **One list of what is translatable.** `App\Support\Translations\ProjectTranslationCatalog` lists every
  translatable field of the project as a `ProjectTranslationUnit`: the translatable project settings, the title
  and content of every built-in static page, active categories, active rating groups, active unarchived options of
  active groups, and every tag — what a visitor can see. A unit carries a stable id built from the record id, not
  from anything an administrator can rename (`project_settings:site_tagline`, `static_pages:about:title`,
  `categories:17:name`, `rating_options:18:description`), its section, record and parent, its business key (slug,
  key, `group.option`, setting or page), the English reference, what each language stores, the maximum length and
  single line or multiline its editor enforces, the placeholders of the English text, and where it appears.
  `ProjectTranslationCompleteness` counts over the same units, so Languages counts exactly what Translation Center
  edits; it lists nothing of its own any more, and what it counts is unchanged.
- **Storage stays where it is.** A unit is read from and written to the place its content already keeps
  translations: the `{field}_translations` column of the settings row, a category, a tag, a rating group or option,
  or a language's entry in `project_settings.static_pages[page][locale][field]`. There is no translation table and
  no migration; repository config only names the built-in pages and seeds a project without a settings row.
- **One target language.** Every installed language but English, enabled or not — a language can be prepared
  before it is offered — in the FRM-11 combobox, kept in the URL (`?locale=de`, replaced, never pushed). Without a
  language in the URL, or with one that is not a target, the page opens on the first enabled target, else the
  first installed one; with no language besides English it says so. Choosing another language is the page's one
  re-render: it reads that language afresh. If that request fails, the page stays usable on the language it shows
  and says so in an error toast.
- **Header:** the combobox, then Total items, Translated, Missing and a completion bar for the target language —
  the same figures Languages shows for it, whatever the filters show — kept current as rows are saved.
- **Filters in the browser.** The toolbar (TBL-02) has the clearable search (“Search source, key or translation”:
  English text, the content's name, its field, its keys and the stored and drafted translation), Section (FRM-10, with
  each section's missing count) and Missing only / All (FRM-08), and the result count. They run over the units
  already on the page — a bounded list — and send nothing to the server; the URL keeps them with replaceState
  (`q`, `section`, `mode=missing`), and a value the page does not know is dropped.
- **Rows** (`240px minmax(0,1fr) minmax(0,1fr)`): the item (section badge, entity, field, mono key, constraint
  chips — “Max 80”, “Single line” or “Multiline”, each placeholder — and Context), the English reference in a
  sunken box with its length and Edit source ↗ to the editor of its English text, and the target field — an input
  for a single-line unit, a textarea for a multiline one — level with the English text, with the error right under
  it and then one line, as the length is under the English text: the counter, the DOM-01 state badge and its note,
  and Discard, Save and Save & next at the end (wrapping under them where the column is narrow). When the list's own width leaves the fields too narrow, each row stacks Item,
  English and the target in that order; the page never scrolls sideways.
- **Drafts live in the browser.** Typing changes nothing stored and sends nothing: Saved or Missing becomes
  Edited · not saved (“Saved version is kept until you save”), an info strip counts the drafts (“2 AI suggestions
  and 1 edit not saved yet. Nothing changes for visitors until you save.”, with sparkles once one of them is an AI
  suggestion) with Discard all, and Discard goes back to what is stored. A draft hidden by a filter stays a draft,
  and an unsaved AI suggestion stays in Missing only. Choosing another language with drafts asks first (OVL-01,
  “Discard unsaved translations?”, Keep editing / Discard and switch); leaving the page with drafts gets the
  browser's own question. AI suggestions are drafts like any other in all of this.
- **AI suggestions.** A Missing row offers AI translate (sparkles), a Saved row Suggest alternative — another
  version of its translation, for when the saved one may not be right. Either sends the unit id, the language and
  the stored text the row shows (none for a missing one), nothing else; the stored text is only compared, never
  sent for translation. `GenerateProjectTranslationSuggestionAction` finds the unit again and refuses when the
  stored translation is no longer the one shown (“This translation was saved by someone else. Reload the page to
  review it.” for a missing row, “This translation was changed by someone else. …” for a saved one), builds a
  batch of one through `ProjectTranslationRequestFactory` — English source, the unit's limits, placeholders and
  usage, the other installed languages as context (never the target's own translation), public content — and asks
  the translation engine. Nothing is stored: the text lands in the row's field as AI suggestion · not saved (info
  badge with dot, info field and cell, “Generated 09:41 · AI suggestions are drafts until saved.”, or “… · Saved
  version is kept until you save.” for an alternative, whose saved version stays stored and served until the
  alternative is saved), and the figures, which count stored translations, stay as they are. An alternative that
  is the saved text word for word changes nothing and says so in an info toast.
  While it is on its way only that row waits: its field is read-only, its target cell `aria-busy`, AI translate
  paused (`aria-disabled`, keeping focus) and Save disabled, with “Generating a suggestion from context…” and the
  page's status region saying so; the rest of the screen stays usable. On success focus moves to the field. An AI
  suggestion offers Regenerate, which replaces it only once the new one has arrived — a failed Regenerate keeps it
  — and Discard, which goes back to what is stored: Missing, or the saved translation. Typing in it turns it into Edited · not saved: the AI mark and
  Regenerate go, so nothing can overwrite what was typed. It is saved by the ordinary Save, which turns the row
  Saved and only then changes the figures. A failure — not configured, the provider unavailable, a suggestion
  that broke the field's limits — is a toast in our words, and the row is exactly as it was; manual translation
  never depends on the provider. Nothing is retried automatically.
- **Save** sends the unit id, the language and the text, nothing else. `UpdateProjectTranslationAction` finds the
  unit again in the catalog under a lock on its row, holds the text to that unit's limits — its maximum length in
  characters, a single line, every placeholder of the English text (“Keep {contact_email}”, “4 over the limit”) —
  and writes that language's entry only; blank text removes it and the language is missing again — also once the
  English text has been cleared, so a stale translation can always be taken away, while new text for it is
  refused. The browser
  checks the same limits as you type and keeps Save disabled while one is broken; a refusal from the server is
  shown at the field too. Save turns the row Saved (or Missing), updates the figures and raises a toast (“German
  translation saved”); Save & next moves focus to the next item the filters show — in Missing only the saved one
  leaves the list — and on the last item simply saves.
- **Context** opens the OVL-02 drawer for one unit, read from the server when it opens: where the text appears,
  the item (section, entity, field, key, target language), the English reference, the constraints and the other
  languages' stored translations — read only, “AI context only”: they help AI with terminology and tone, and
  English remains the authoritative source — with Edit source in the footer. Last, on the sunken ground, **What AI
  translate sends**: the target, the source language (English, authoritative), the source text, the content type,
  the context, the maximum length, the format, the placeholders, the other translations supplied as context and the
  glossary (none yet). It is read from the very request the suggestion is made from, never written out separately,
  and shows content only — never a provider, a model, a credential or a raw request.
- **From Languages.** A language's name opens Translation Center on that language. Translate on a missing item
  opens Translation Center on that item (`locale`, `section`,
  `mode=missing` and `unit`): the row is shown — the filters loosened if they would hide it — scrolled to,
  focused and marked, and the URL drops `unit` once it has been followed. The unit in a link only decides where the
  page opens, never what is written. Translate all missing opens the language in Missing only.
- **Not in this step.** AI suggestions are interactive, one row at a time, on top of the reusable translation engine
  (`App\Support\TranslationEngine`, see `docs/architecture/translation-engine.md`). Not yet: Generate missing,
  Save all generated, background generation and suggestions that survive a reload — they arrive with background and
  bulk generation (Phase 5C). No review states, no translation history and no source hashes. The editors that
  translate in place keep doing so until the translation cutover (Phase 7).

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

In production the field comes first, level with the English text beside it; under it come the counter, then the
state badge and its note, then the actions (see the deviations below).

## Responsive rules

- Verified widths: 1440, 1280 and 1024.
- At 1280 px and wider the sidebar is 300; below it collapses to the 68 rail.
- Tables keep every column and scroll horizontally inside their card — except Languages, whose rows tighten and then
  stack into labelled blocks (see Localization), so it never scrolls at all.
- A grid without a minimum width — Translation Center's rows — stacks its cells in their own order once its own
  width (a container query, not the window's) leaves them too narrow, rather than scrolling or reshuffling.
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
| Tertiary text | gray-400 `#99A0AE` (2.63:1 on white) | `--rg-admin-text-tertiary` `#68707D` (5.00:1 on white, 4.67:1 on the app ground) | WCAG AA for normal text; see below |
| Dark mode | not defined | the admin is light only; the kit draws its own light canvas | no reference to follow |
| Row menus | live in the prototype | specified here; built when the first screen needs them | no production screen uses them yet |
| Disable language confirmation | *firm*: a required reason | a warning confirmation with no reason field | nothing stores a reason for a language change; asking for one and discarding it would be for show |
| Confirmation footnote | “Recorded in the … log as …” | none on Languages | no log records a language change |
| Languages status notes | “Disabled 21 Sep”, “Never enabled” | “Offered to visitors” / “Not offered to visitors” | no date of a language change is stored |
| Languages top bar and drawer actions | “Open Translation Center”, Translate per item, “Show all in Translation Center”, “Translate all missing” | Translate per item and Translate all missing; no “Open Translation Center” in the top bar and no “Show all” | the navigation already opens Translation Center, and Translate all missing is the drawer's one way into it |
| Translation Center AI | Generate missing and Save all generated in the top bar, AI translate and Regenerate in each row, the AI suggestion state, “What AI translate sends” in the context drawer, sparkles on the unsaved strip | AI translate on a Missing row and Regenerate on an AI suggestion, the AI suggestion state, “What AI translate sends” in the context drawer, sparkles on the strip while an AI suggestion is unsaved (the info icon otherwise); no Generate missing or Save all generated, so the top bar has no actions | interactive suggestions come first (Phase 5B); bulk generation and saving arrive with background generation (Phase 5C), and no control is drawn that does nothing |
| Translation Center AI button | AI translate shown on every row, labelled Regenerate once a suggestion exists | AI translate on a Missing row, Suggest alternative on a Saved row, Regenerate on an AI suggestion; none on a typed draft | the label says what the suggestion will do to the row, and a suggestion never overwrites text somebody typed |
| Translation Center while generating | the target field is replaced by a box: spinner and “Generating a suggestion from context…” | the field stays where it is, read-only, its cell `aria-busy`; the spinner and the same words take the note's place under it | a field that disappears takes focus and the suggestion being regenerated with it |
| Translation Center figures | the result count reads “… on this page” and the footer “1–N of M missing items” | the toolbar counts “N of M items”; the footer keeps only the note about interface strings | every unit of the language is on the page; there is no pagination |
| Translation Center title | none: the header band holds the combobox and the figures | the same, with the page's `h1` visually hidden | the screen still needs a heading for assistive technology and for focus to return to |
| DOM-01 placement | the state badge and note above the target field | under the field, after the counter and before the actions | the target field starts level with the English text it translates |
| Languages table on narrow widths | the card scrolls the grid horizontally | tightened columns down to 876, then one labelled block per language | the screen never scrolls sideways; the locale code moves beside the names, so no column is lost |
| Translation Center and Languages search | a plain search field | a clearable one, with × while it holds text | a query is cleared in one press |
| Toast Undo | reversible actions toast with Undo | no Undo in the stack yet | no migrated action is reversible without confirmation; it arrives with the first one |
| Global search | sidebar search over records, settings, pages, languages and media | the same field and results, over the records Filament's global search finds | see [Transitional omissions](#transitional-omissions) |
| Sidebar header | ~73 high, its hairline below the top bar's | 62, as tall as the top bar | the two hairlines run as one line |
| Workspace switcher | a switcher button with a chevron | a static identity block | there is only one workspace |

### Tertiary text contrast

The reference draws tertiary text — meta lines, hints, overlines, table headers, placeholders and the icons
beside them — in gray-400 `#99A0AE`. That is 2.63:1 on white and 2.45:1 on the `#F6F7FB` app ground, below
WCAG AA's 4.5:1 for normal text.

Production deliberately draws it in `#68707D` instead: `--rg-admin-text-tertiary` is `#68707D`, which reaches
5.00:1 on white and 4.67:1 on the app ground. It stays visibly lighter than secondary text (`#525866`, 7.13:1),
so the three-step hierarchy of strong, secondary and tertiary text is kept.

The reference palette is unchanged: `--rg-admin-gray-400` is still `#99A0AE` and is used where contrast rules
for text do not apply (the edited-field border, icons on the ink toast). Accessibility takes precedence over an
exact reproduction of the reference for functional text. A test checks both contrast ratios and the palette
value.

## Reference ID registry

The Dev UI kit reference names 42 elements. IDs never change and are never reused. The production kit at
`/admin/dev/ui-kit` shows the elements built so far under the same IDs; nothing is shown under an ID that is not
built.

| Group | IDs | In the production kit |
|---|---|---|
| Foundations | FND-01 Colour tokens · FND-02 Typography · FND-03 Spacing, radii, elevation · FND-04 Icons | all |
| Actions | ACT-01 Button · ACT-02 Icon button · ACT-03 Links and text actions | all |
| Status | STS-01 Status badge · STS-02 Counters · STS-03 Progress bar · STS-04 Active range slots · STS-05 Locale chips | all but STS-04 |
| Forms | FRM-01 Search field · FRM-02 Text field · FRM-03 Textarea with counter · FRM-04 Locked identifier · FRM-05 Number stepper · FRM-06 Toggle switch · FRM-07 Checkbox · FRM-08 Segmented control · FRM-09 Radio cards · FRM-10 Filter dropdown and chips · FRM-11 Searchable combobox | FRM-01–03, FRM-08, FRM-10, FRM-11 |
| Navigation | NAV-01 Sidebar · NAV-02 Top bar and breadcrumb · NAV-03 Status tabs · NAV-04 Pagination | all; NAV-01 and NAV-02 also run as the production shell |
| Layout | LAY-01 Page header · LAY-02 Card and sections · LAY-03 Detail rows and section labels | all |
| Tables | TBL-01 Table row · TBL-02 Table toolbar · TBL-03 Bulk action bar · TBL-04 Row actions and menu · TBL-05 Empty states · TBL-06 Loading skeleton | all but TBL-03 (TBL-04's menu as a static surface) |
| Overlays & feedback | OVL-01 Confirmation dialog · OVL-02 Drawer · FBK-01 Toast · FBK-02 Inline notice | all; OVL-01, OVL-02 and FBK-01 as live, reusable components |
| Admin-specific | DOM-01 Translation field states · DOM-02 Report chain | DOM-01 |

The [migration plan](migration-plan.md) says when the remaining elements are built.
