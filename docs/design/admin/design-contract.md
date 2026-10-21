# RateGuru Admin v2 Design Contract

This contract defines the visual and interaction rules of the RateGuru admin (Admin v2). It is the admin's
counterpart of [`docs/design/design-contract.md`](../design-contract.md), which governs the public site. The two
are separate design systems on purpose: they share the Inter typeface and nothing else.

Every value below was taken from the reference files, not invented — for the screens migrated so far, from the UI
kit and the earlier deliveries of the admin prototype. Where production deliberately departs from them, the departure
is listed under [Deviations from the reference](#deviations-from-the-reference).

## Source of truth

The authoritative visual and UX references are:

| File | What it defines |
|---|---|
| [`reference/original/RateGuru-Dev-UI-Kit.html`](reference/original/RateGuru-Dev-UI-Kit.html) | Foundations, every component with a stable reference ID, measurements, tokens and rules |
| [`reference/original/RateGuru-Admin.html`](reference/original/RateGuru-Admin.html) | The full admin prototype: shell, navigation and every screen (Dashboard, Posts, Comments, Reports, Users, User edit, Categories, Tags, Rating groups, Rating group edit, Project settings, Languages, Translation Center, Translation AI, Media diagnostics, the Content Studio section: Publications & plans with the publication editor, Personas with the persona editor, Reply rules, Reply log and Action queue, and the Growth section: Games with the game editor and its playable previews, Experiments with the experiment editor, Analytics, and the media picker) |
| [`reference/original/RateGuru-Admin-v2.html`](reference/original/RateGuru-Admin-v2.html) | The second delivery of the admin prototype, which Categories, Tags, Rating groups, Project settings, Posts, Comments, Reports, Users and Media diagnostics were built against. Superseded; kept as it was delivered, so a screen built on it can still be compared with what it was built from |
| [`reference/original/RateGuru-Admin-v1.html`](reference/original/RateGuru-Admin-v1.html) | The first delivery of the admin prototype, which Languages and Translation Center were built against. Superseded; kept as it was delivered, so a screen built on it can still be compared with what it was built from |

Rules for the reference files:

- They are kept byte for byte as delivered. Do not format, minify or edit them; a test pins their checksums. A
  new delivery replaces a file and its checksum together, in a change of its own; the delivery it replaces is
  kept beside it under its own name.
- They are self-unpacking bundles. Open them in a browser to view them; the readable source is inside, gzip
  compressed. They are references, not code: nothing from their runtime, data or React-like structure is used
  in production.
- When this document and a reference disagree, the reference wins unless the difference is listed as a
  deliberate deviation below.
- A screen already migrated keeps the delivery it was built from as its reference until it is reviewed against the
  current prototype; a screen migrated from now on is built from the current one.

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
holds the only rules that touch Filament's own elements, all unlayered: a fix carried over from the old inline
stylesheet (pointer cursor on toggles, selects and file pickers — the frameless records-per-page chooser it
also carried went with the last Filament table), and one that fits Filament into the Admin v2 shell (hiding Filament's own sidebar overlay, which the shell
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
unsaved AI draft's field) and `--rg-admin-status-info-cell` `#FAFBFE` (its cell). The danger tint adds
`--rg-admin-status-danger-row` `#FFFBFB`, the faint ground of a critical row (Media diagnostics). Data colours: track
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
| Detail panel | 448 (drawer, docked panel); 480 for long lists; 680 for a record's editor |
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
  Translation Center · `bot` Translation AI · `settings-2` Project settings · `hard-drive` Media diagnostics.
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
  | Content Studio | Publications & plans · Personas |
  | Localization | Languages · Translation Center (missing count, neutral) · Translation AI |
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
  bottom save bar. A record edited in a drawer over its list — a persona, a person — saves from the drawer's
  footer instead, which counts its own unsaved changes.
- **Toasts (FBK-01):** bottom centre of the main column, 24 from the bottom, at most three stacked, 5.2 s.

### Production shell

Every admin page except the developer kit is drawn inside the Admin v2 shell. Page content is still Filament's
until each page is migrated, so a page can show both the shell's breadcrumb and its own legacy heading for now.
Languages, Translation Center, Translation AI, Posts, Comments, Reports, Users with a person's editor, Categories,
Tags, Rating groups, Project settings and Media diagnostics are migrated: their content is Admin v2 too. Content
Studio's Publications & plans and Personas were built in Admin v2 from the start.

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
  migrated page puts its actions: Categories and Tags put their Create action there, registered with the panel,
  which asks the page by a browser event to open its drawer; Rating groups puts Create group there, a link, and
  the group editor its unsaved state, Discard and Save; Project settings puts its count of unsaved changes, Discard
  and Save changes there; Posts puts when its list was read (“Updated 09:41”) and Refresh; Reports the keys that
  step through reports (J, K); Media diagnostics when it was read, Refresh and Run full audit; Publications & plans
  Create publication, a link; a new publication's page and a publication's editor their count of unsaved changes,
  Discard, and Create draft — which runs with nothing changed, since a draft may be saved empty, and becomes Save
  draft once the draft exists — or Save draft; Personas Create persona, which asks the page by a browser event to
  open its drawer. The editors share one top bar (`filament.pages.editor.topbar`), which reads what is
  unsaved from the page through the `adminEditor` Alpine store. A page without a permanent action, such as
  Languages, leaves it empty.
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
- **Operational counts** arrived with their screens: Posts shows its pending count, Comments its reported comments
  still visible and Reports its open reports (warning); Media diagnostics the masters the last completed audit
  found missing (danger), read from that audit's own tally. The shell draws a badge whenever a navigation item
  provides one, but adds no count queries of its own. Only Translation Center's is still missing, below.
- **Translation Center's missing count.** The reference badges Translation Center with the missing translations
  (neutral). Counting them means reading every unit of project content, and the navigation is drawn on every
  admin page, so the item has no badge for now rather than a full completeness read per page or a counter kept
  for it alone. Translation Center itself always shows the exact figures, and so does Languages.
- **Legacy page headers, breadcrumbs and actions** stay inside each page until that page is migrated; page
  actions are not moved into the top bar. Languages is migrated and has none of them left.

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
  tertiary, and a status such as a badge beside them when there is one (`aside`); sections split by full-width
  hairlines; a sunken footer for read-only notes. Edit screens use two
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
  count (“Approve selected (2)”), a note about skipped rows, “Clear selection” on the right. In production it is
  `x-admin.ui.bulk-bar`: the count a status region, the actions its slot, unavailable while none of the selected
  rows qualifies. A selection never outlives the list it was made on: another tab, search, filter or page clears
  it.
- **Row actions (TBL-04):** the one or two most used actions for the row's status inline as secondary sm buttons;
  everything else in an ellipsis menu (ghost sm). Menus are 248–272 wide, radius 12, padding 6; items carry a 16
  icon, a 14px label and an optional 12px hint. Unavailable actions stay visible, disabled, with the reason as a
  hint. Destructive items are red-950 after a separator. Menus flip up near the bottom and close on outside
  click, scroll and Escape. In production it is `x-admin.ui.row-menu` with `x-admin.ui.menu-item`: a menu button
  named for its row (“More actions for Dogs”) over a 264 menu fixed to the window, so a card that scrolls
  sideways never clips it. Enter, Space or Down open it on the first item, Up on the last; Up, Down, Home and End
  move; Escape closes it back to the button; a choice, Tab, a click outside, a scroll that moves its button or a
  resize close it. An item is a link or a button; an unavailable one keeps its place in the menu and in the
  keyboard order, marked `aria-disabled` as a menu's items are, so its reason is heard, and does nothing.
- **Pagination (NAV-04):** footer padding 12 16, 13 gray-600; “1–25 of 1,231” tabular; pages 32 min with the
  current one sunken, hairline and 500; ghost sm arrows disabled at the ends. Page size 25. In production it is
  `x-admin.ui.pagination` (NAV-04), over a length-aware paginator: the range with an optional note (“newest
  first”), the first and last pages and those either side of the current one, a gap where pages are left out;
  each page is a link to the paginator's URL, or, given a Livewire method, a button that calls it with the page.
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
| Media diagnostics | `96px 170px 88px 132px 80px minmax(160px,1fr) 120px 210px` | 1056 |
| Translation AI recent calls (production) | `96px 172px 180px 96px 52px 116px 92px 72px minmax(200px,1fr)` | 1076 |

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
- **Radio cards (FRM-09):** padding 12, radius 12; selected with an ink border, sunken fill and a 5px ink ring. In
  production `x-admin.ui.radio-cards`: a radiogroup as the segmented control is, each card a label and a line of
  explanation, the choice exposed to `wire:model` and `x-model`.
- **Filter dropdown (FRM-10):** popover radius 12, padding 6, 200–240 wide; items 36 high, radius 8, check on the
  selected one, optional count.
- **Searchable combobox (FRM-11):** 52-high trigger, radius 12, border gray-300, overline plus value; a 420-wide
  list with search first and a 340 max height. Built for the target language with 30+ languages.

In production the locked identifier, the number stepper and the switch are Blade components too:

- **`x-admin.ui.locked-identifier` (FRM-04):** label, the chip while generated, or a lock with “Locked” and the
  `unlock` slot (“Change slug”) once saved; the value in mono, read-only but focusable while locked; the hint or
  the error, and the warning notice once unlocked, all tied to the input. Attributes go to the input.
- **`x-admin.ui.number-stepper` (FRM-05):** minus and plus named after the field (“Decrease sort order”) that step
  within min and max and tell the input it changed, so `wire:model` and `x-model` see a step as typing; what is
  typed is the server's to validate.
- **`x-admin.ui.switch` (FRM-06):** a real button with `role="switch"` and `aria-checked`, named by its label, the
  state in words beside it and a hint for each state; really disabled when disabled; its value exposed to
  `wire:model` and `x-model` (`x-modelable`).
- **`x-admin.ui.checkbox` (FRM-07):** a real `input type="checkbox"` under the drawn box, so the keyboard, a form,
  `wire:model` and `x-model` reach it; named by its label, which `hide-label` keeps for assistive technology only
  (a row's selection box); `mixed` is the input's indeterminate state, which a select-all box keeps live itself.
- **Preset chips (FRM-03):** `x-admin.ui.textarea` takes `presets`, texts offered above the field; choosing one
  fills the field as typing would and marks the chip chosen (`aria-pressed`) while the field holds it.

The segmented control, the filter dropdown and the combobox are Blade components, each with the keyboard
behaviour its ARIA role promises and its value exposed to `x-model` (`x-modelable`):

- **`x-admin.ui.segmented` (FRM-08):** a `radiogroup` of two to four `radio` buttons; only the checked one is a tab
  stop, the arrow keys, Home and End move the choice with the focus, and the checked option is raised and 500,
  not only differently coloured.
- **`x-admin.ui.filter-dropdown` (FRM-10):** a menu button (“Section: All”, `aria-haspopup="menu"`) over a 240 wide
  menu of `menuitemradio` items with a check and an optional count, which may be an Alpine expression kept live
  by the screen. Enter, Space or Down open it on the checked item; Up, Down, Home and End move; Escape returns to
  the button; a choice, Tab or a click outside closes it.
- **`x-admin.ui.combobox` (FRM-11):** an option leads with a flag, or, for a list of things rather than languages,
  with an icon in a sunken square (28; 32 in the trigger), and an identifier such as a model name is set in mono.
  The trigger (`aria-haspopup="listbox"`) opens the list with focus in its
  search, an ARIA `combobox` over a `listbox` whose active option is its `aria-activedescendant`; the search
  filters in the browser, Up and Down move, Enter chooses, Escape returns to the trigger, and an always-present
  status region says when nothing matches. Choosing dispatches a cancelable `choose` event before the value is
  taken, so a screen can ask first or make the change on the server instead. The list opens under the trigger,
  or over it when there is more room there — measured between the bars kept at the window's top and foot
  (`data-admin-ceiling` on the top bar, `data-admin-floor` on the Content Studio editor's footer) — and is never
  taller than its room, so all of it can be reached. The Post step's author panel opens the same way.

## Status

- **Badge (STS-01):** 24 high, padding 0 8, radius 6, 12/500; a 6px dot only on live healthy states, running
  work and unsaved AI output. Statuses map to tones in one place per screen; the badge knows tones, never
  statuses.

  | Tone | Labels |
  |---|---|
  | success | Published, Visible, Active, Enabled, Completed, Saved, Resolved, Healthy, Current |
  | warning | Pending, Open, Limited, Missing, Warning, Degraded, Burst |
  | danger | Rejected, Banned, Failed, Critical, Not configured, Ongoing, Systemic issue |
  | neutral | Hidden, Ignored, Disabled, Inactive, Shadowbanned, Removal finalized, Configured, Isolated, Not current |
  | outline | Draft, Deleted by author, Archived, Default |
  | info | AI suggestion · not saved, Running, Info |

  When time matters, a 12px note sits under the badge (“Restorable until 16 Oct”).
- **Counters (STS-02):** navigation pills as above; the report chip in rows is 24 high, radius 6, `flag` 12,
  warning for 1–2 and danger for 3+; zero shows “—” in gray-300.
- **Progress (STS-03):** one bar, no charts. 6 high in rows, 8 in summaries; ink progress, green only at 100%,
  red for an invalid catalog, info blue while running. The figure is always printed next to it.
- **Range slots (STS-04):** a rating group's active options against its minimum and maximum (12×12 slots in
  tables). In production it is `x-admin.ui.range-slots`:
  one picture named in words (“3 active, 2 to 4 allowed”) that the screen also says beside it, at table or editor size.
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
- **Drawer (OVL-02):** 448 wide (480 for long lists, 680 for a whole record's editor in sections — a persona, a
  person), full height on the right, drawer scrim; header 62 high with title 15/500, 12px subtitle and close;
  scrolling body split by hairlines; footer with actions on the right and destructive actions on the left. Escape
  or the scrim closes it. Reports docks the same 448 panel inside the layout. A confirmation asked from an
  editor's drawer opens over it, as the reference draws it, and closing the confirmation leaves the drawer open.
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
  `x-admin.ui.overlay`, the layer that holds this shared behaviour; besides them only the Posts image viewer
  is drawn on it. The dialog has the `default` (light),
  `warning` and `danger` tones — danger for a deletion that cannot be taken back, its confirm the danger button; a
  blocked dialog is one with no confirm action. In its `details`, `.rg-admin-dialog-subject` shows the record it
  acts on (an icon, its name, one line about it) and `.rg-admin-dialog-facts` what an alternative does, under a
  section label. The drawer is 448 wide, 480 with `wide`, or 680 with `editor` for a whole record's editor,
  never wider than the screen, with an optional `leading` and `footer`.
- **`x-admin.ui.toast-stack` (FBK-01)** is drawn once per page by the shell. A page raises a toast with a browser
  event — `$this->dispatch('rg-admin-toast', message: '…', tone: 'success')` from Livewire, or `$dispatch` from
  Alpine — with the tone `success`, `error` or `info`. At most three stay on screen, each for 5.2 s, waiting while
  the pointer or keyboard focus is on the stack. A status region (success, info) and an alert region (error), always
  present, announce each message once; the toasts themselves are not live regions. A toast is never the only record
  of a lasting error: the screen shows it too. A reversible action adds `action` — Undo, and the browser event the
  button dispatches with its params, which the screen listens for (`#[On('activate-category')]`); pressing it
  takes the toast away.

## Localization

Localization is the first production vertical of Admin v2:

```text
Localization
├── Languages
├── Translation Center
└── Translation AI
```

**Invariant.** Translation Center is the single admin editor for DB-owned translated content. Categories, Tags,
Rating groups and options, Project settings and Static pages edit their English reference content only — never
an editor for every language, and no note where one was. Their editors keep no copy of a translation, so saving
one never changes or overwrites a translation (`SaveProjectSettingsAction` refuses translations, and takes a
static page's English alone, keeping its other languages as stored). Translation Center lists what visitors can
see: the translations of inactive and archived content are kept, edited nowhere while it is off, and come back
with it.

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
  and Discard, Save and Save & next at the end (wrapping under them where the column is too narrow for the counter,
  the badge and the actions together). The note stays beside the badge when it fits (“Missing · Visitors see the
  English text”) and otherwise moves whole onto the next line, starting under the badge — never under the counter —
  so a long note (“Generated 09:41 · AI suggestions are drafts until saved.”) never pushes the actions aside. When
  the list's own width leaves the fields too narrow, each row stacks Item, English and the target in that order;
  the page never scrolls sideways.
- **Drafts live in the browser.** Typing changes nothing stored and sends nothing: Saved or Missing becomes
  Edited · not saved (“Saved version is kept until you save”), an info strip counts the drafts (“2 AI suggestions
  and 1 edit not saved yet. Nothing changes for visitors until you save.”, with sparkles once one of them is an AI
  suggestion) with Discard all, and Discard goes back to what is stored. A draft hidden by a filter stays a draft,
  and an unsaved AI suggestion stays in Missing only. Choosing another language with drafts asks first (OVL-01,
  “Discard unsaved translations?”, Keep editing / Discard and switch); leaving the page with drafts gets the
  browser's own question. AI suggestions are drafts like any other in all of this. The one exception is a
  suggestion Generate missing made: the server keeps it (a persisted draft, below), so only the drafts this page
  alone holds — typed, interactive, or a generated one edited since (volatile drafts) — make it ask.
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
- **Generate missing.** In the header band, after the figures: Generate missing (n) (sparkles) counts every
  translation the language is missing, whatever the filters show, and asks first (OVL-01, “Generate n
  suggestions?”, Cancel / Generate suggestions). It sends the language and nothing else:
  `StartProjectTranslationGenerationAction` reads what is missing from the catalog, plans it into provider-sized
  chunks and queues one job per chunk — the request never waits for a provider (see
  `docs/architecture/translation-engine.md`). An info notice (`role="status"`) shows the progress (“Generating 24
  of 39 translations…”, “You can leave this page. Generation will continue in the background.”); the missing rows
  wait, read-only and `aria-busy`, with “Generating in background…”, while every other row stays usable. The page
  polls about once a second while generation runs — never from a hidden tab — and stops when it is complete.
  Each suggestion lands in its row as AI suggestion · not saved, only while the row is still missing, untouched
  and showing the English it was generated from; a suggestion for English that has changed since is not shown
  (“English changed after this suggestion was generated. Generate a new translation.”), and nothing typed is ever
  overwritten. Then the notice sums up (“36 AI suggestions ready · 2 failed · 1 skipped”, “Generated drafts are
  temporary.”) with Discard generated, a white (secondary) button so it stands out on the info strip; a failed or
  skipped row stays Missing, says why under its field and stays fully usable. The figures change only when
  something is saved.
- **Persisted drafts.** Generated suggestions are kept on the server for 48 hours from when generation started,
  whoever reads them: a reload or a new visit restores them, leaving the page or switching language loses none
  of them and so asks about none. Each administrator has their own per language; one language is generated by
  one administrator at a time (“German translations are already being generated by another administrator. Try
  again when that generation finishes.”). Generate missing is offered again once nothing is running and nothing
  is waiting for review; until then it is replaced by the primary Save all generated (n).
- **Saving and discarding generated suggestions.** Save on an untouched generated suggestion, and Save all
  generated, send which suggestions — never their text: `SaveProjectTranslationGenerationAction` saves the text
  the server holds, each one on its own through `UpdateProjectTranslationAction`, and skips one whose English
  changed or whose translation someone saved meanwhile. Save all generated asks first (OVL-01, “Save n generated
  translations?”, “This publishes these AI suggestions to visitors. Anything whose English source or stored
  translation changed will be skipped.”, Cancel / Save n translations) and reports what it did in one toast.
  Typing in a generated suggestion makes it an ordinary edit, saved as typed and left out of Save all generated;
  saving it lets the server's copy go. Discard and Discard generated let suggestions go on the server too; if
  the server cannot be told, the draft stays.
- **Not in this step.** No review states and no translation history; source fingerprints exist only to keep a
  generated suggestion from outliving the English it was made from, and go with it.

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

**Translation AI** (prototype):

- Top bar: “Updated 09:41”, the period (24 h · 7 days · 30 days, a segmented control) and Refresh.
- Header: title, one sentence — technical monitoring for AI-assisted translation, read-only, translations are edited
  in Translation Center (a link) — and the AI model combobox (FRM-11 style: “AI model”, All models or one model, a
  status badge, a list with each model's calls in the period).
- One card of three sections side by side, each at least 320 wide: **Provider health** (status badge; provider,
  API key, last success, response time, success rate, retry policy, fallback provider), **Usage and cost**
  (provider calls, translated items, tokens in · out, estimated cost two by two; calls per hour or day as stacked
  bars, succeeded in ink under failed in red, with a legend), **Failures and issues** (a tinted line saying whether
  anything is systemic; each error type with its count and Isolated, Burst or Ongoing; “Burst: 5 or more of one
  type within 10 min. Ongoing: still failing in the last 15 min.”).
- **Recent provider calls**: All / Failed, then Time · Usage · Language · Items · Tokens in / out · Cost · Latency ·
  Result, newest first, 20 a page, with “Metadata only. Source text, prompts, translations and API keys are never
  shown here.”

**Translation AI** (production): `/admin/translation-ai` (`App\Filament\Pages\TranslationAiPage`) is the third item
of the Localization section, after Translation Center, with the `bot` icon, drawn entirely in Admin v2 for whoever
may manage project settings (`manage-project-settings`). It is read-only: nothing on it edits a translation or
changes a setting, and nothing is sent to a provider — not on opening, not on Refresh.
`App\Filament\Support\TranslationAiDiagnostics` works out everything it shows; the view queries nothing.

- **State in the URL.** The period (`?period=24h`, `30d`; 7 days by default), a model (`?model=`), All or With issues
  (`?calls=issues`) and the page of calls (`?page=`). Each change is a Livewire round trip that draws the page again;
  a new period, model or call filter goes back to the first page; a value the page does not offer is read as its
  default. A period is whole hours or days ending with the current one, so its chart has exactly 24, 7 or 30 points
  and covers the calls its figures count. Times are UTC, as the application's are.
- **Header band:** title, the sentence with the Translation Center link, then on the right the AI model combobox
  (FRM-11, icons instead of flags, model names in mono) and under it “Updated 09:41 UTC”, the period (FRM-08) and
  Refresh, which reads the records, the configuration and the background generations again and says so in a toast.
- **The model filter** narrows the history, never what translates: its options are All models, then every model
  recorded — by the name its provider reported, a dated snapshot included — with its calls in the period, the
  configured model (or its snapshot) first and badged Current, the others Not current with their last call. The
  configured model is listed before it has any call, “Configured now · no calls recorded”. Nothing is a fixed list.
- **Provider health** is the configuration translations run on now — the configured provider and model, with the
  model's dated snapshots — whatever the filter shows: Not configured (danger) when a public-content batch would be
  refused before sending, asked the way Generate missing asks before it plans; Degraded (warning) when the last call
  that tested the API key — a success, or a rejection of it (`authentication_failed`) — was a rejection, however few
  failures that makes, or while a series of one provider failure of the configured model is ongoing; Healthy
  (success, dot) after a successful call within 24 hours with neither; Configured (neutral) otherwise — a key alone
  is never Healthy. Rows: provider, model, API key (Configured, Not configured or Not required — never the key), last
  success, average response, p95 latency and success rate over the period, retry policy and fallback provider (None:
  the engine has neither). The reason sits in the section's sunken footer.
- **Usage and cost:** provider calls (and how many failed), translated items — succeeded, not sent — tokens in · out
  (and calls without reported usage) and the estimated cost: the sum of known costs, to the cent or “< $0.01”,
  with “n calls with unknown cost” in the due colour whenever any is unknown, and Unknown when all are — never $0
  for an unknown cost. Under them calls per hour (24 hours) or per day, succeeded in ink under failed in red, each
  bar named for screen readers; with no calls, an empty state instead of a chart.
- **Failures and issues** counts failed provider calls and failed items apart and never adds them up — a timeout of
  ten items is “1 failed call · 10 failed items”. Six types are always listed (constraint_violation,
  provider_timeout, invalid_provider_response, rate_limited, authentication_failed, provider_unavailable), any
  other error code once it happens. Failed calls have a pattern: Isolated; Burst — five or more with the same code
  within a sliding ten minutes; Ongoing — such a series reaching into the last fifteen minutes. An item failure
  only (“Provider answered”) has none, so a refused translation never reads as an outage. The line at the top says
  “No issues in this period.”, an isolated count, the latest burst and whether anything followed, or the ongoing
  series as a systemic issue.
- **Recent provider calls** (TBL-01, NAV-04): Time · Usage (Interactive AI or Generate missing) · Model · Language
  (and the tag the provider was sent, “as sr-Cyrl”) · Items · Tokens in / out · Cost · Latency · Result, newest
  first, 25 a page read by the server. A failed call shows its code and HTTP status, a successful one with failed
  items “9 of 10 translated” and the codes; an unknown token count or cost is “—”, named for screen readers. All /
  With issues counts both. Only what a call records is shown: no batch id, no item, no text.
- **Background generations** (`App\Livewire\Admin\TranslationAiGenerations`) is Generate missing as the temporary
  store has it now: the generation of each installed language, through the store's running pointers, and the
  viewer's own for each language until it expires, through theirs — a known list of keys read together, never a
  scan. Each shows the language, Queued, Running or Completed, a progress bar, processed of total, ready, failed,
  skipped, saved and discarded, who started it, and when its results expire; only counts and times reach the
  browser. It is read again every 5 seconds while one is queued or running and on screen, and not at all once
  none is. When the store cannot be reached it says Temporarily unavailable and the rest of the page is unaffected.
  There is no history beyond the 48 hours a generation's results are kept.
- **Configuration and guardrails**, folded away (a disclosure button): provider, model, pricing, provider timeout,
  translation job timeout, queue and its retry_after, queue worker timeout (Not available — set where the worker
  is started), temporary results store, batch TTL, retry policy, fallback provider, hourly limit and budget (Not
  configured — not built yet) and telemetry retention, each read where it is set.

## Content

```text
Content
├── Categories
├── Tags
└── Rating groups
```

Categories and Tags are drawn entirely in Admin v2 (`ManageCategories`, `ManageTags`, one screen each). Their
resources stay for routing, navigation, the policies and global search; the list, the editor and every
confirmation are the one screen's.

- **Header band (LAY-01):** the title and one sentence — the names are the English reference, other languages
  are managed separately — followed by “Open Translation Center” on that section, for whoever may open it. Stats:
  Categories show Active, Inactive and Missing translations; Tags show Tags, Unused and Missing translations.
- **Top bar:** the primary Create category / Create tag, through the `TOPBAR_END` hook, for whoever may create.
  It asks the page by a browser event to open its drawer.
- **Table (TBL-01, TBL-02):** the grids of the [table above](#tables). The list is bounded and already on the
  page, so the clearable search (“Search name or slug”, “Search tags”) runs in the browser and keeps `?q=`; the
  toolbar counts “N of M”. A search that finds nothing offers Create “…”, which opens the drawer with that name.
  Categories are in their sort order, tags by number of posts, then by name. The footer says so (“Sorted by sort
  order. Inactive categories stay attached to their posts.” / “Sorted by number of posts.”).
- **Name cell:** the English name — a real link to the record's drawer (`?edit=`), which a click opens in place;
  an inactive category's in secondary ink — and under it the locale chips (STS-05) of the languages the project
  offers besides English, each translated or missing, with “n missing” in the due colour. Missing translations,
  in the chips' count and the stat, are counted over offered languages only, and for categories over active ones
  only: an inactive category is not in Translation Center while visitors cannot see it, so it shows its chips but
  is not due.
- **Slug** in mono, as stored; **Active** (categories) a success badge with the live dot or a neutral Inactive;
  **Sort order** and **Posts** right-aligned and tabular.
- **Row actions (TBL-04):** Edit inline; the menu holds, for a category, Deactivate (“Hide from navigation and
  uploads”) or Activate (“Show to visitors again”), Translate, and after a separator Delete (“Has posts · deactivate
  instead” or “Unused”); for a tag, Merge into… unavailable (“Not available yet”), Translate, and Delete (“In use ·
  remove it from its posts first” or “Unused”). Translate opens Translation Center on the record — its section and
  unit, in the first offered language it is missing from — and is unavailable, with the reason, for an inactive
  category and for whoever may not open Translation Center. On Tags, Delete is unavailable to a moderator.
- **Activate and deactivate** apply at once, with no confirmation, and toast with Undo (FBK-01); Undo restores the
  previous state and says so (“Dogs is active again”).
- **Drawer (OVL-02):** “New category” / “Edit category” (tags alike), subtitled “Created in English. Translate it
  afterwards.” or “n posts · ID n”. Name, tagged “English · reference”. Slug (FRM-04): on create it follows the
  name — chip “Generated from name” — until the slug itself is typed; once saved it is locked, and Change slug
  unlocks it with a warning: changing it breaks existing links, and, once it differs, “Feed links filtered by
  “old” stop working once you save.” The server keeps a locked slug as stored whatever the browser sends. A tag's
  slug may be left blank: it is then made from the name. Categories add Sort order (FRM-05; a new one goes last)
  and Active (FRM-06, “Shown to visitors” / “Hidden from visitors”). Other languages lists, read-only, each offered
  language with its stored translation, or the English fallback a visitor gets, or “Not translated yet” on create,
  with a Missing badge, and Translate — unavailable, with the reason, until the record exists or while a category
  is inactive. The footer has Delete on the left (it replaces the drawer with its confirmation), Cancel, and
  Create category / Create tag or Save.
- **Errors** say what to do: “Enter a name.”, “This slug is already used by another category. Choose another
  one.”, “Use lowercase letters and numbers, with single hyphens between words: small-pets.”, “Enter a whole number
  from 0 to 999,999.” The English name is held to the length its translations are held to.
- **Delete (OVL-01):** read when the dialog is drawn. Unused, a danger confirmation — the record in a subject row,
  “Its stored translations are deleted with it.”, Delete category / Delete tag. A category any post holds — a
  deleted post that is kept included, which the database still counts — cannot be deleted: a warning dialog says
  how many posts are filed under it and offers Deactivate instead, with what that does; already inactive, it has
  nothing to confirm. A tag posts carry is a blocked dialog: remove it from those posts first. A delete that finds
  posts after the dialog was drawn shows that dialog instead of deleting.
- **Toasts:** “Dogs created. Translations are missing for n languages.” (n the offered languages besides
  English), “Dogs saved”, “Dogs deleted”, “Dogs deactivated” with Undo; tags as “#zoomies …”.
- **Access:** categories are an administrator's. Tags are curated by moderators and administrators; deleting a
  tag, and Translation Center, are an administrator's. Every write goes through its action, which checks the
  policy again.

### Rating groups

Rating groups are drawn entirely in Admin v2 too: the list (`ManageRatingGroups`), a new group
(`CreateRatingGroup`) and one group with its options (`EditRatingGroup`). The resource keeps the routes, the
navigation item, the policy and global search, whose results open a group's editor.

- **List header band:** “Voters pick one option in every active group. Each group must keep its number of active
  options inside its allowed range.” Stats: Active groups, Active options (over active groups) and Votes. Create
  group in the top bar opens the new group's page.
- **List table (TBL-01):** groups in the order post pages show them (sort order, then id), no search — the list is
  short. Label (a link to the editor) with its description; Key in mono; Options — “n active” and the inactive and
  archived ones; Votes; Active range — the slots (STS-04, one past the widest range for every row alike) and “2–4
  allowed”, “· at maximum”, or “· needs n more” in the due colour; Active; Sort; Edit and the row menu. A legend
  of the slot states sits in the card's footer.
- **List row menu (TBL-04):** Manage options; Deactivate (“Hide from voters, keep the votes”) or Activate (with
  “Needs n more active options” when its range does not hold them); Translate — Translation Center on the group's
  first missing label or description, unavailable for an inactive group; and Delete, unavailable (“Groups are
  kept · deactivate instead”): a group is never deleted, since its votes are the project's results.
- **Activate and deactivate:** activating a group whose range holds its active options is immediate, with Undo;
  one whose range does not is a blocked dialog — “Tricks can’t be activated yet”, what it has against its range,
  what to do (activate or create n more, or lower the minimum when that is allowed) and Open Tricks. Deactivating
  is a warning confirmation (its votes stay stored) with Undo after it.
- **Editor header band:** the saved label with its Active or Inactive badge, then the key in mono, the votes and
  “Shown second on post pages” (its place among the active groups) or “Not shown on post pages”; stats Active
  options and Allowed range, as saved. A new group reads “New rating group”, inactive.
- **Save from the top bar (NAV-02):** the settings are a draft; the top bar shows “Unsaved group changes” in the due
  colour, Discard and Save group (Create group on a new one) while the draft differs from what is saved, compared in
  the browser against the server's `saved` copy through one Alpine store, since the top bar is a component of its
  own. Save also waits while the draft's range cannot hold the group's active options and the group would be active.
- **Group card (LAY-02):** “English reference text.”; Key (FRM-04) — made from the label on create until typed,
  then “Fixed after creation”, with nothing to unlock it; Label (“English · reference”); Description (FRM-03,
  counted to its translation limit); Minimum and Maximum active options (FRM-05, 2 to 10) and Sort order; Active
  (FRM-06), unavailable on a new group, which has no options yet. Minimum, Maximum and Active are sent as they
  change, so their errors — “3 options are active, fewer than this minimum.”, “3 options are active. Deactivate or
  archive 1 first.”, “Minimum can’t be higher than the maximum.” — show before Save.
- **Active options check:** the slots at editor size (STS-04, up to 10) for the draft's range, “3 active · 2–4
  allowed”, and a notice: valid with room for n more (success), at the maximum (info), or outside the range
  (warning — saving blocked while the group would be active, otherwise what to do before activating it).
- **Translations of this group:** each offered language besides English with “Label and description”, “Description
  missing” or “Label and description missing”, and Open in Translation Center on what is missing — unavailable,
  with the reason, for an inactive group.
- **Options card:** “n active · n inactive · n archived” and Create option; at the maximum an info strip says new
  and unarchived options stay inactive; the table (Label with description · Key · Active, Inactive or Archived ·
  Sort · Votes · Archived date · Actions) on the sunken ground for archived rows; the footer note that options with
  votes are archived, not deleted.
- **Option row menu (TBL-04):** Activate or Deactivate — with “Blocked · minimum is 2” or “Blocked · maximum is 4”
  in its hint when the range will refuse it — and Archive (“Hide from voters, keep votes”); for an archived option
  Unarchive (“Returns as inactive”); after a separator Delete unused option (danger, “Never voted on”, or “Has votes
  · archive instead”).
- **Option drawer (OVL-02):** “New option in Vibe” / “Edit Goofball”; Label; Key (FRM-04, made from the label on
  create, fixed after); Description; Sort order; Active (FRM-06) — unavailable, with the reason in an info notice,
  for an archived option, in a group at its maximum, or for an active option the minimum cannot spare; Archived at,
  read-only. A new option is active while the group has room.
- **Option dialogs (OVL-01):** Archive (warning, the option in a subject row, its votes stay); Delete (danger, never
  voted on, deleted with its translations); “has vote history” — only archived, offering Archive instead; “can’t be
  deactivated / archived / deleted” at the minimum, with nothing to confirm; and “can’t be activated” at the
  maximum, offering “Raise maximum to n and activate”, one step under the group's lock, while the group may allow
  one more (10 at most). Activating and deactivating an option toast with Undo.
- **The range:** the option actions keep every group's active options inside its range, active or not, as they
  always have; saving a group's range or making it active is refused when an active group's options would fall
  outside it. A new group is created inactive.
- **Access:** rating groups are an administrator's; every write goes through its action, which checks the policy
  again.

### Project settings

Project settings (`ProjectSettingsPage`) is drawn entirely in Admin v2: one draft over six tabs — Site identity,
Object labels, Defaults, Feature flags, Sign-in providers and Static pages — saved together from the top bar
through `SaveProjectSettingsAction`. Static pages are its last tab; they have no screen of their own.

- **Header band:** “Project settings”, the saved site name and host, and “English is the reference language”;
  beside it the installation preset.
- **Installation preset:** a button naming the preset applied at installation, with “Matches preset” (success,
  dot) or “Customized · n” (warning) as the draft is typed, or “Not applied”. Its panel says when the preset
  was applied, or that none was, and that presets are applied once from the server; it lists each setting the preset controls that the
  draft differs from — “Preset “Pet” → now “Critter”” — with Revert, which puts the preset's value back in the
  draft. Nothing here applies a preset: that is `php artisan rateguru:setup`. The site's identity is the project's
  own and is not compared.
- **Tabs (NAV-03):** links, so a tab has an address (`?tab=features`, `?tab=pages&page=terms`), switched in place.
  Edit source in Translation Center opens a setting or a static page on its tab. What is typed on one tab stays in
  the draft while another is open.
- **Save from the top bar (NAV-02):** “n unsaved changes” in the due colour, Discard and Save changes, counted in
  the browser — every changed setting and every changed static page field — against the server's `saved` copy.
  Save validates every tab; a refused field shows its error on its tab, and a warning notice above the tabs names
  the tabs that hold one (“Some settings can’t be saved yet: Object labels and Static pages.”). Saved: “Project
  settings saved. Visitors see the changes on their next page load.”
- **Site identity and Object labels (LAY-02):** each English text (FRM-02, the description FRM-03 counted to its
  translation limit) with what it is used for and “Differs from preset · “…”” while it does; in the card's sunken
  footer which offered languages miss which texts — “Translations: Русский complete · Български missing the
  description.” — and Translate into Translation Center on the first missing one. Beside the card, “Where visitors
  see it”: the site header, the feed's title and count and a post's links drawn from the draft as it is typed.
- **Defaults:** Default theme (System, Light, Dark) and Default sort (Hot, New, Top), each a segmented control
  (FRM-08) with an icon per option and what the chosen one means under it.
- **Feature flags:** one settings row each (FRM-06, `layout="row"`) in a flush card: what it does, On or Off,
  “Differs from preset” while it does. Switching off Allow user uploads (“Pause all uploads?”) or Allow guest
  viewing (“Require sign-in for everyone?”) asks first (OVL-01, warning); the others switch at once. Every switch
  applies when the settings are saved, as the dialogs' footnote says.
- **Sign-in providers:** “Email and password sign-in is always on.” and one row per provider — Configured or Not
  configured (with the server keys it needs), how many accounts sign in with it, Enabled or Off. Switching one off
  asks first: its buttons are hidden and its sign-ins refused, the accounts stay linked, and people who only ever
  signed in with it are shown on the login page how to set a password.
- **Static pages:** the list — each page's title, “n translations missing” or “Translated”, “· edited” while its
  draft differs — and the open page: its path, View page, the English Title and Content (FRM-03, “Plain text.
  Line breaks are kept.”), and its translations in the footer with Translate.
- **Access:** project settings are an administrator's (`manage-project-settings`); every write goes through
  `SaveProjectSettingsAction`, which refuses translations and the offered languages.

## Content Studio

```text
Content Studio
├── Publications & plans
└── Personas
```

Content Studio is where administrators run the accounts the site's editorial content comes from and prepare what
they publish. Its section holds only screens that work: the prototype's Reply rules, Reply log and Action queue
arrive with their own screens, never as items before them.

### Publications & plans

The list (`ListStudioPlans`), a new publication (`CreateStudioPlan`) and a publication's editor
(`EditStudioPlan`) are drawn in Admin v2 over `StudioPlan` (`StudioPlanResource`). A plan is a draft of a post, never
a post: nothing on these pages publishes, schedules or queues anything, and every write goes through the plan's
actions (`docs/architecture/studio-plans.md`).

- **List header band:** “Prepare publication drafts before reviewing and publishing them.” Stats: Total plans,
  Drafts, With image and With author — figures that can be counted now; nothing about scheduled or published posts,
  comments or votes, which no plan has yet.
- **Toolbar (TBL-02):** the search over the title, the id (`#123` or `123`) and the author's username and names —
  never a deleted author's; State (All, Draft) and Image (With image, Without image) as filter dropdowns with their
  chips, in the URL, a value the list does not offer dropped; 25 a page, the most recently saved first
  (`StudioPlansQuery`, a registered stable pagination boundary); a page past the end shows the last one.
- **Table (TBL-01):** the title — a link to the editor — or “Untitled draft #ID”, with the id; the author with
  avatar and username, “No author selected”, or “Deleted user” and nothing else of a deleted account; the photo at
  thumbnail size from its smallest variant, or a placeholder; the publication date — “When approved” for a plan
  published once it is approved, the date it will carry, or “Date not selected”; the state (STS-01, Draft
  outline); Last updated. A draft has Edit and the row menu — Edit draft and Delete draft; a plan past its draft
  has the two disabled with the reason.
- **Empty states (TBL-05):** “No publication plans yet” with Create publication; “No plans match” with Clear
  filters.
- **The editor (NAV-02):** one Admin v2 page for a new publication and for an existing one, saved from the top bar
  with the count of unsaved changes, Discard and Create draft or Save draft, and the browser's question before a
  page with unsaved changes is left. Four steps under the title — Image (Upload & analysis), Post (Texts, author &
  votes), Discussion (Comments & their votes), Publication (Plan & timeline) — with Back (disabled on the first),
  “Step n of 4” and Continue in a footer kept at the foot of the window; moving between them saves nothing and
  loses nothing, and none is locked by an incomplete draft. Continue waits only while a photo is on its way,
  saying “Wait for the photo to finish uploading.”
  - **Image:** nothing waits for Save. While there is no photo, its sources (FRM-08): Upload — Upload image, or a
    file dropped on the drop zone, the limit read from `uploads.images.max_kilobytes` —, From link — a direct link
    to an image — and From social post — a public post's link, with the sites read directly (Instagram, X,
    Facebook, Pinterest) and the one the link is for marked. A file is previewed at once in place of the
    sources, which stay out of the way until it is stored, uploaded (“Uploading photo…” with its progress),
    stored (“Processing photo…”) and analysed, its preview kept until the saved photo has loaded in its place so
    the photo never blinks out; a link is imported (“Importing
    photo…”) once it is a whole address and typing has paused, never twice running. On a new publication the
    first photo makes its Draft, and the top bar's Create draft becomes Save draft. Once there is a photo, the
    sources give way to it: the photo, its file name, dimensions, size and type, a Saved or Replaced badge, Replace
    — the sources again, with Cancel — a file dropped on the photo to replace it, and Remove from draft, taken off
    at once. A failed upload or import is said by its source (FBK-02 warning, “Upload failed.”) with Try again,
    apart from the analysis. Beside it, Photo analysis of the saved photo, its own component, with a badge (Not
    started, Analysing, Done, Failed, Not configured): “The analysis starts as soon as a photo is added.”;
    “Uploading photo…”, “Processing photo…” or “Importing photo…” while a new one is on its way, nothing of the
    old one shown; “Waiting for analysis…” and “Analysing photo…” (FBK-02 info, read again every few seconds, and
    only then); the description under Scene summary, Subjects, Actions, Setting, Composition, Notable details,
    Visible text and Uncertainties, with the date and the model in a hint and Re-run analysis, “a new, paid
    request”; a re-run shown as the first run was — the result before it gone from the card at once, three
    placeholder lines over “Waiting for analysis…” or “Analysing photo…” —, and a failure in its own words (FBK-02
    warning) with Try again, nothing of the result before it shown; “This
    photo has not been analysed yet.” with Re-run analysis for a photo stored before analysis was on; “Photo
    analysis is not configured.”; and the photo's File, Dimensions, Size and Format (LAY-03). Neither is part of
    Save draft.
  - **Post:** laid out as the prototype's Titles & texts. First, AI text suggestions, a component of its own about
    the saved photo, in three cards side by side until they no longer fit. Context: the photo, the analysis's
    summary under Photo analysis, Tone of new variants (Playful, Humour, Cheeky — chosen at first —, Irony,
    Neutral; toggle buttons whose choice changes nothing until the next batch, a choice other than the tone the
    lists were written in said in the hint) and Generate suggestions, “One request to OpenAI for 10 titles and 10
    descriptions — a paid call.”; then Generate 10 more — Generate 10 in the tone chosen, when it is another —, or
    Try again after a failure. Then 10 titles and 10 descriptions, each card the newest batch for the photo and its
    analysis as they are now under “Set n · written in tone”, numbered; choosing one puts it in Final title or
    Final description as typing would — unsaved, counted in the top bar, editable, saved only by Save draft — and
    marks it in use. A new batch takes the place of the one before: while it is on its way both cards wait for it
    (“Waiting for text suggestions…” or “Generating suggestions…” over ten placeholders, the button disabled, read
    again every few seconds, and only then), and a failure is said in their place (“Generation failed”, its own
    words, “The final title and description are unchanged.”, Try again). Nothing is generated or saved by itself.
    Until the photo's analysis is complete, “Text suggestions will be available when photo analysis is
    complete.”; with no photo, “Add a photo on the Image step first: suggestions are written from its analysis.”;
    unconfigured, “Text suggestions are not configured.”, the step working as before. Batches written for an
    earlier photo or analysis are neither shown nor used. Under them, Final texts: Final title (3 to 120, with its
    counter), Final description (up to 2,000) and Post author side by side — the persona chosen on a trigger, the
    living personas found on the server in a panel under it (a restricted one with “This persona cannot publish
    while restricted.”) —, then Source URL, never the link a photo is imported from. Last, Category and tags — the
    category (an inactive one kept and marked) and up to ten tags — beside the author's answer in each active
    rating group. The steps take the whole width, as the prototype's do.
  - **Discussion:** laid out and working as the prototype's Discussion step, in a component of its own drawn once with
    the page and hidden on the other steps; a new publication says “Save the draft first to prepare its discussion.”
    until its Draft exists. First, Discussion settings, in the first of three columns: Total comments (0–40, with
    “No discussion: the post publishes without automated comments.” at none), Top-level comments (“of n”, “First-level
    comments. The rest are replies.”), Number of personas (“of n active”) while Random is chosen, and Personas —
    Random, picked among the active personas only when the tree is generated and then shown as chips, “Picked for the
    current tree. Generate again to reshuffle.”, or Manual, every active persona matching “Search by login or name”
    checked one by one, with “n of n active personas selected · up to 8 take part”, Select all (Select n shown while
    searching, Select first 8 when more are shown than take part), Clear and the chosen ones as removable chips; a
    persona restricted after it was checked stays checked but takes no part. Then a strip of its own, “Generate from the settings above”, with what they ask for (“14 comments · 5
    top-level · 6 personas”, or “No discussion · the post publishes without automated comments”), a hint — “Select at
    least one persona.”, why generation waits, “The tree has comments. You’ll be asked before it’s replaced.”, or “One
    request to OpenAI — a paid call.” — and Generate tree, or Regenerate tree once there is one. It waits while the
    post has unsaved changes or a generation is on its way; a tree that would replace comments asks “Replace the
    discussion?” (OVL-01) first. One asked for earlier, in another tab or by another administrator, is waited for as
    what it is, a whole tree or a comment written again, with an info toast saying nothing new was asked for; one
    finished while the editor was closed is taken in when it opens. While the tree is generated, six placeholder rows stand in for it; it is taken in the
    moment it is ready, and a failure is said over the tree with Try again. A tree taken in that was written for an
    earlier title or description says so over the tree (info): “The discussion was generated for an earlier title or
    description. It isn’t regenerated automatically.”, with Regenerate and Keep as is. Then Discussion tree, “n comments · n
    top-level” and + Comment, in two tabs. Comments: each comment on the post by its time, its replies indented under
    it on a guide, each a box with the persona's avatar, name, an Automated tag, its label (C1, C2, … in the order
    shown), “↳ C1 · name” for a reply, a deleted, non-persona or restricted persona marked, the time after the post
    (“+8 min”), its text, and Reply (comments on the post only), Edit, Regenerate — “Rewriting…” with the text faded,
    then the new text and a toast “New text for C3 · same author and time” with Undo, which gives that comment its
    text back — and Delete, which asks first
    (OVL-01, danger): “Delete C3?” or “Delete C1 and its 2 replies?”, “Replies can’t stay without their parent, so
    the whole branch leaves the plan.”, the comment, and “3 comments removed: C1, C2, C3”, then a toast with Undo,
    which brings them back; the next change, or a Reload, leaves nothing to undo.
    Timing: every comment by its time — #, Action, Executor, Parent, Offset from post (a number with min, h or d,
    changed in place) and Dependency (“After the post”, “After C1 at +8 min”). A comment is written in a dialog, 560
    wide — “New top-level comment”, “Reply to C1” or “Edit C3” — with the persona as chips found by search (active
    ones, and the comment's own author whatever its status), Replies
    to, Text (1 to 1,000, with its counter) and “Appears after the post” in min, h or d with “+X after the post · Y
    after C1”, or a warning when a reply would come before its comment; Save writes it at once, “Saved to this draft
    at once. Nothing is published.”, each refusal kept by its field.
  - **Publication:** Publish now or On a chosen date (FRM-09) with the date and time in the application's time
    zone, “Publication date not selected” while there is none, and the publication card as the draft stands,
    saved or not, with “This is a draft. Saving it does not publish or schedule anything.”
- **Saving:** the texts through `CreateStudioPlanAction` or `UpdateStudioPlanAction`; the photo the moment it is
  chosen through `SetStudioPlanImageAction` or `RemoveStudioPlanImageAction` — each handed the version the editor
  last read or wrote, so neither is in conflict with the other, and the texts typed stay unsaved until Save. Each
  error stays by its field, and Save shows the step it is on. A save or a photo drawn before somebody else's save
  is refused with a toast and a warning notice whose Reload draws the plan as it is, keeping what was typed until
  then; a draft deleted meanwhile can no longer be saved; a plan past its draft opens closed. A new publication's
  editor opens once it is saved, and Save sent twice, or a photo sent twice, makes one plan. The discussion is
  written the moment it changes, as the photo is, through `SaveStudioDiscussionTreeAction`, and what AI wrote taken in
  through `ApplyStudioDiscussionGenerationAction`; the plan has one version, and each of the page's writes takes the
  version the other's left — once the plan is at it and still holds what that editor drew — so none is in conflict
  with another, while one drawn before somebody else's save is refused, the discussion's with Reload discussion. A
  Reload of either editor is said to the other, so one Reload brings the page up to date.
- **Delete draft (OVL-01):** from the row menu and the editor's header, “Delete this draft?” — “The draft will be
  removed. Its image will be released if nothing else uses it.” — Cancel and Delete draft, through
  `DeleteStudioPlanDraftAction`, refused for a draft changed since and harmless for one already gone.
- **Access:** an active administrator's (`manage-studio-plans`): the navigation item, every page and every
  Livewire request ask, and so do the actions.

### Personas

The list (`ListPersonas`) is drawn in Admin v2, with a persona — new or existing — edited in the drawer over it
(`PersonaEditor`), as the reference draws it; the old addresses of a new persona and of a persona's editor
(`CreatePersona`, `EditPersona`) lead there. `PersonaResource` is over `User`, narrowed to the Persona role — a
persona is a regular account, not a second kind of one — and keeps the routes, the navigation item and the access
check; global search stays with Users, whose result for a persona opens its editor for an administrator.

- **List header band:** “Regular accounts run by Content Studio. They never sign in; their status changes only
  through moderation in Users.” Stats: Total, Active, Restricted (limited, banned, shadowbanned) and Replies on
  (engagement other than Off) — figures that can be counted now; none about plans or replies yet.
- **Toolbar (TBL-02):** the search over the username, the names, the short description and the character; Status
  (Active, Limited, Banned, Shadowbanned) and Engagement (Off, Low, Normal, High) as filter dropdowns with their
  chips, in the URL, a value the list does not offer dropped; 25 a page, newest first (`PersonasQuery`, a
  registered stable pagination boundary).
- **Table (TBL-01):** the avatar or initials, the display name — which opens the editor — and the username; the
  short description with the speech style and emoji under it; the status (STS-01); the engagement; Max a day;
  Edit and the row menu — Edit persona, View public profile, and Moderate in Users (Limit, ban or restore access).
  Status is changed only there: Content Studio has no moderation of its own and no Deactivate.
- **Empty states (TBL-05):** “No personas yet” with Create persona; “No personas match” with Clear filters.
- **The editor (OVL-02, 680):** one drawer over the list for a new persona and for an existing one — opened by
  Create persona in the top bar or a row's Edit, the address saying which (`?persona=new`, `?persona=12`) — titled
  “New persona” or “Edit Night Owl”, “A regular account on the site, run by Content Studio”. Its footer counts the
  unsaved changes, with Cancel and Create persona or Save persona, and the browser asks before a page with unsaved
  changes is left. Profile — the photo (Upload photo, kept with the draft until Save), Display
  name (required), Username (made from the display name when left empty on create; the public profile address as
  its hint) and Public bio; Character — Short description, Character, Speech style, Topics and typical jokes,
  Never writes about (FRM-03 with counters) and Emoji (FRM-08: None, Occasional, Frequent), internal and never
  shown on the site; Activity — Engagement (FRM-08: Off, Low, Normal, High, with what each means) and Max replies
  a day (FRM-05, 0–50), kept as settings that start nothing yet; Account — its status with Moderate in Users, View
  public profile, and what it never does — sign in, receive mail, administer.
- **Saving:** through `CreatePersonaAction`, `UpdatePersonaAction` and `SetPersonaAvatarAction`, which check
  everything again whatever the page sent. Each error stays by its field; a draft drawn before somebody else's
  save is refused with a toast and a warning notice whose Reload draws the persona as it is; a persona deleted
  meanwhile can no longer be saved. Saved, the drawer closes on the list showing it; a new persona whose photo
  could not be kept opens again on itself for the photo to be chosen again.
- **Access:** an active administrator's (`manage-personas`): the navigation item, every page and every Livewire
  request ask, and so do the actions. A moderator sees no Content Studio and moderates personas in Users.

## Moderation

### Posts

Posts (`ListPosts`) is drawn entirely in Admin v2: the moderation queue and every other post, a tab at a time,
searched, narrowed and read 25 at a time on the server (`ModerationPostsQuery`). The resource keeps the route, the
navigation item — with the pending count, warning — the policy and global search, whose results open the post
in the list by its id.

- **Header band:** “Posts wait here before publishing while their author isn’t trusted or hasn’t confirmed their
  email. Everyone else publishes at once.” Stats: Waiting, Oldest waiting (“3 h 12 min”) and Reported. The top
  bar says when the list was read and has Refresh, which reads it again with skeleton rows meanwhile (TBL-06).
- **Tabs (NAV-03):** All · Pending · Published · Hidden · Reported (reported, or flagged for review after their
  reports were dismissed) · Deleted by author, each with its count; links with an address (`?status=hidden`).
  Pending opens first and reads oldest first; every other tab newest first.
- **Toolbar (TBL-02):** the search over the title, the author's username and the post's id (“#4821”); Category
  and Sort (Oldest first · Newest first · Most reported) as filter dropdowns (FRM-10); the category as a removable
  chip; “n posts”, or “n posts match” while filtered.
- **Table (TBL-01):** the selection box (FRM-07); the thumbnail — the smallest variant — which opens the original;
  the title, a link to the public post while it is published, over “#id · category”; the author with whether they
  publish at once (Trusted, Not trusted, Email not confirmed) and how many posts they have, a link to their user
  page for whoever may manage them; the status (STS-01) with its note — Restorable, the rejection reason,
  Retained for audit, Retained internally; the reports (STS-02); when it came in, and “Waiting 3 h 12 min” on a
  pending post, in the due colour after two hours.
- **Row actions (TBL-04):** Approve and Reject on a pending post, Hide on a published one, Restore on a hidden
  one; a finalized removal shows “Final” and nothing else. The menu: Open public post, Open original image, View
  author, and for an administrator Finalize removal — on a hidden post (“Irreversible”), unavailable on a
  published one (“Hide the post first”).
- **Approve** publishes at once, with no confirmation and no Undo: approving notifies the author and the
  author's followers. **Restore** is immediate, with Undo, which hides the post again.
- **Confirmations (OVL-01):** Reject is light — an optional reason; Hide is firm — a required reason, for one post
  or for every published one selected, with Undo after it; Finalize removal is irreversible — the danger callout,
  what happens, a required internal reason and the acknowledgement checkbox, with the danger confirm. Reasons are
  up to 500 characters, at least 3 when given; the chips are the reasons visitors report with (Spam, Offensive,
  Fake, Copyright, Wrong category). The confirm button waits for what is required, and each dialog says it is
  recorded in the moderation log.
- **Bulk bar (TBL-03):** “n selected”, Approve selected (n) and Hide selected (n) with the posts each applies to,
  and how many selected posts neither applies to.
- **Image viewer:** the original over a darker scrim, with its size in pixels, type, file size and disk, and Open
  original; Escape, the scrim and Close dismiss it.
- **A post that changed meanwhile** — moderated by someone else since the list was drawn — is not acted on; the
  toast says so, and the list shows it as it is now. A post the list does not hold is never acted on.
- **Access:** posts are moderated by moderators and administrators; finalizing is an administrator's. Every change
  goes through its moderation action, which checks the moderator, locks the post and writes the moderation log.

### Comments

Comments (`ListComments`) is drawn entirely in Admin v2: every comment, a tab at a time, searched and read 25 at a
time on the server (`ModerationCommentsQuery`), newest first. The resource keeps the route, the navigation item —
with the reported comments still visible, warning — the policy and global search, whose results open the comment
in the list by its id.

- **Header band:** “Comments publish immediately. Moderation happens after the fact, mostly from reports.” Stats:
  Reported and Hidden.
- **Tabs (NAV-03):** All · Visible · Hidden (a finalized removal included) · Author deleted · Reported (reported
  and not removed for good), each with its count and an address of its own.
- **Toolbar (TBL-02):** the search over the text, the author's username, the post's title and the comment's id
  (“#90412”); “n comments”, or “n comments match”.
- **Table (TBL-01):** the text in two lines — a link to it under the public post while it is visible there — over
  its id and, once its author deleted it or its removal is final, “Read-only” (“· kept for audit” for the author's
  deletion); the author, a link to their user page for whoever may manage them; the post's title and id; the
  reports (STS-02); the state (STS-01) — Visible, Hidden by moderation (“Restorable”), Deleted by author, Removal
  finalized (“Retained internally”); when it was written.
- **Row actions (TBL-04):** Hide on a visible comment, Restore on a hidden one; a finalized removal shows “Final”.
  The menu: Open in thread, View author, Open post in moderation — the post in the Posts list — and for an
  administrator Finalize removal, on a hidden comment, its author's deleted one included (“Irreversible”), and
  unavailable on a visible one (“Hide the comment first”).
- **Hide** is a warning confirmation with an optional reason — the chips are the reasons visitors report a
  comment with — and Undo after it; **Restore** is immediate, with Undo; **Finalize removal** is the irreversible
  confirmation Posts has. Each says it is recorded in the moderation log.
- **A comment that changed meanwhile** is not acted on, and the toast says so: hiding one already hidden would
  otherwise toast a hide, and an Undo, that were someone else's.
- **Access:** comments are moderated by moderators and administrators; finalizing is an administrator's. Nothing
  here deletes a comment: that is its author's alone.

### Reports

Reports (`ListReports`) is drawn entirely in Admin v2: each report points to one post, comment or person, and the
screen reads them a tab at a time on the server (`ModerationReportsQuery`), beside the open report's chain. The
resource keeps the route, the navigation item — with the open reports, warning — and the policy.

- **Header band:** “Each report points to one post, comment or person, and its author. Acting on the content and
  closing the report are separate steps.” Stats: Open, Targets (what the open reports point to) and Oldest open.
  The top bar shows the keys: J for the next report, K for the previous, from anywhere but a field or a dialog.
- **Tabs (NAV-03):** Open (oldest first) · Resolved · Ignored · All (newest first), each with its count and an
  address of its own.
- **Toolbar (TBL-02):** the search over the reported content, the reporter, the reason, the reporter's message and
  the report's id; Type (All, Posts, Comments, People) as a filter dropdown (FRM-10); “n reports”.
- **Docked or over the list:** where the main column leaves 1048 for the list, the open report's chain docks
  beside it as the 448 panel, sticky as the page scrolls, and the list condenses to Target · Reason · reporter ·
  Status · Created — opened wide with nothing chosen, the first report opens. Otherwise the list keeps Type ·
  Target · Reason · Reporter · Status · Created · Actions (Resolve and Ignore on an open report, Open details),
  scrolling inside its card, and the chain floats over it on the right, 472 wide with its card's shadow. A report's
  target opens its chain; the report is in the address (`?report=2284`).
- **Report chain (DOM-02):** the report — “Reported as spam”, who reported it, their message, and “3 open reports
  point to this post. Each one is closed separately.”; the target — its text, what it is, its state, Open target
  while visitors can see it and Hide target while it can be hidden, and the info notice that a hidden target's
  report stays open; the author — or the person reported — with their status, whether they are trusted, their
  posts and the reports on their content, View user and Ban author (unavailable, with the reason: already
  banned, account deleted, administrators only). The panel's head has Previous, Next and Close.
- **Closing:** the footer takes an optional resolution note, stored with the report; Resolve and Ignore close that
  report only, toast with Undo — which opens it again — and open the next open report. A closed report says who
  closed it, when and with which note, and offers Reopen (`ReopenReportAction`).
- **Hide target** is a warning confirmation — a required reason for a post, an optional one for a comment, the
  report's own reason filled in; **Ban author** is a danger confirmation with what a ban does and an optional
  reason. Both leave the report open, and say so in their toast.
- **Something that changed meanwhile** — a report someone else closed, a target already hidden — is not acted on;
  the toast says so.
- **Access:** reports are processed by moderators and administrators; banning is an administrator's. Every change
  goes through its action.

### Users

Users (`ListUsers`) is drawn entirely in Admin v2, with a person edited in the drawer over the list
(`UserEditor`) in the style of a persona's; a person's old address (`EditUser`) leads there. The list reads
everyone with an account a tab at a time on the server (`ModerationUsersQuery`); status changes only through the
moderation actions, which go through their actions and log themselves. The resource keeps the routes, the
navigation item, who may edit whom (`UserPolicy::manage`) and global search, whose results open the person's
editor — or the list on them where they are not the viewer's to edit.

- **List header band:** “Status changes only through moderation actions. Trust decides whether a person’s posts
  skip review.” Stats: Total, Restricted (limited, banned, shadowbanned) and Staff.
- **Tabs (NAV-03):** All · Active · Limited · Banned · Shadowbanned · Deleted · Trusted (active and at the trusted
  level), newest accounts first.
- **Toolbar (TBL-02):** the search over the username, the name and the email address; Role as a filter dropdown;
  the Role and Suspicious chips — Suspicious, as the dashboard's Suspicious users opens the list
  (`?suspicious=1`): shadowbanned, or with reported posts or comments.
- **Table (TBL-01):** the avatar, the username — which opens the person's editor for whoever may edit it — the name
  and when they joined; the email address (“Removed on deletion” for a deleted account); the role, staff as an
  outline badge; the status (STS-01); trust as a two-step bar and its word (Trusted, Not trusted); posts; the
  reports on their posts and comments (STS-02); Edit and the row menu.
- **Row menu (TBL-04):** the person, their status and trust as its caption; Edit user, View posts (the Posts list
  on them), then Mark trusted, Restore access, Limit, Shadowban and Ban as their status allows — each unavailable
  one kept with why: “This is your account”, “Administrators can’t be sanctioned”, “Administrators only”, “Already
  trusted”, “Restore access first”.
- **Moderation (OVL-01):** Limit (warning), Shadowban and Ban (danger) say what they do as the lifecycle has it —
  no new posts, comments, votes, reports or follows, the content kept, never a locked account — with an optional
  reason; Mark trusted (light) has no reason to give. A sanction on an active person toasts with Undo, which
  restores access; Restore access is immediate and toasts with Undo, which applies the sanction again. The same
  actions and dialogs are in the person's editor, the dialogs over it; Undo is the list's, which shows the change
  in its row and in the drawer.
- **A person's editor (OVL-02, 680):** a row's Edit or username opens it, the address saying on whom
  (`?user=12`); titled with the username, the name and when they joined under it, then stats Posts, Comments and
  Reports (on their content).
- **The draft (NAV-02):** Profile — Name, Username (“Public as @name”), Email (a new address has to be confirmed
  again); Role & trust — Role as a segmented control (FRM-08) with what it allows, Trust as radio cards (FRM-09):
  Not trusted, Trusted; Password — a new one, shown or hidden, empty to keep the current one. The drawer's footer
  counts the unsaved changes, with Cancel and Save changes; a change of role is asked about first (warning). Saved
  through `UpdateUserAccountAction`, after which the drawer closes. A persona's account is drawn read-only, with
  Edit in Content Studio and Close.
- **Where they stand:** Status — the badge, since when and by whom (the decision that set it, with its reason),
  that status is changed only by a moderation action, and those actions; Account — sign-in methods, language,
  joined, posts by status; Moderation history (DOM-02) — the moderation of the person and of their posts and
  comments, newest first, then when the account was created.
- **Access:** moderators see the list; editing an account and every moderation action are an administrator's,
  never on their own account nor another administrator's, and never on a deleted one.

## System

### Media diagnostics

Media diagnostics (`MediaDiagnosticsPage`) is drawn entirely in Admin v2. Every figure comes from aggregate SQL or
the snapshot the last audit recorded — never a scan of the disks; the one exception is the asset inspector, which
checks the files of the one asset it shows. The page keeps its route, its navigation item and who may open it
(`view-media-diagnostics`, administrators).

- **Header band:** the title with the library's health (STS-01: Healthy, Degraded, Critical, Unknown before any
  audit), when the last audit completed and how many issues it found — or that a full audit is running — and the
  media disks.
- **Overview:** ten figures in one card — health with its critical and warning counts, assets, variants, tracked
  storage, missing masters (Critical), missing variants (Warning), active unreferenced, purgeable, physical orphan
  candidates and failed media jobs (Failed). A figure that counts issues is a button that narrows the issues to it.
- **Audit issues (TBL-01, TBL-02):** the issues of the last completed audit, the most severe first, 25 a page
  (`MediaAuditIssuesQuery`, a registered stable pagination boundary), each kept in its address — searched by asset
  id or path, narrowed by Severity and Issue type as filter dropdowns with counts. A critical row has the faint
  danger ground. Each row offers only what can be done about it: Regenerate on a missing variant, Release on an
  active unreferenced asset, Purge on a purgeable one, and Inspect wherever there is an asset.
- **Last audit, Audit history, Failed media jobs:** the last run whatever its status — started, completed,
  duration, status, assets, variants and issues; the five runs before, each with its tally; the failed media jobs,
  each with its exception, Retry, and Retry all, which dispatches a fresh regeneration rather than the old payload.
- **Asset inspector (OVL-02):** what the issue means for the asset, its details — kind, what uses it, disk, path,
  size, dimensions, type, uploaded, lifecycle — and its files, the master and each variant, there or missing.
  Under the files, Force regenerate all variants for an asset still active; in the footer Copy path, then Release
  asset, Purge asset or Regenerate missing variants as the issue allows, or “No automatic fix” for a missing master.
  A confirmation asked for here replaces the drawer.
- **Confirmations (OVL-01):** Release (warning) and Force regenerate (warning) say what they do; Purge is
  irreversible — the danger callout, what it deletes and an acknowledgement. Each is checked again when confirmed:
  an asset something uses again is not released, and only an asset still purgeable is purged.
- **Top bar (NAV-02):** when the page was read, Refresh, and Run full audit, which dispatches an audit unless one
  is already running.

## Responsive rules

- Verified widths: 1440, 1280 and 1024; Translation AI, Posts, Comments, Reports, Users, Categories, Tags, Rating
  groups and Project settings also at 768 and 390; Personas and the persona editor, and Publications & plans with
  every step of the publication editor, also at 390.
- At 1280 px and wider the sidebar is 300; below it collapses to the 68 rail.
- Tables keep every column and scroll horizontally inside their card — except Languages, whose rows tighten and then
  stack into labelled blocks (see Localization), so it never scrolls at all.
- A grid without a minimum width — Translation Center's rows — stacks its cells in their own order once its own
  width (a container query, not the window's) leaves them too narrow, rather than scrolling or reshuffling.
- Toolbars wrap; auto-fit grids reflow; drawers cap at `100vw`.
- Reports docks its 448 panel only when the main column leaves at least 1048 px for the table — from 1348 px with the
  full sidebar, from 1116 px with the rail — otherwise the panel floats over the list.

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
| Row menus | live in the prototype; an unavailable item is greyed | built with Categories and Tags (`x-admin.ui.row-menu`); an unavailable item stays focusable, `aria-disabled` | a menu's items are reached with the arrow keys, and the reason an item is unavailable has to be heard |
| Disable language confirmation | *firm*: a required reason | a warning confirmation with no reason field | nothing stores a reason for a language change; asking for one and discarding it would be for show |
| Confirmation footnote | “Recorded in the … log as …” | none on Languages | no log records a language change |
| Languages status notes | “Disabled 21 Sep”, “Never enabled” | “Offered to visitors” / “Not offered to visitors” | no date of a language change is stored |
| Languages top bar and drawer actions | “Open Translation Center”, Translate per item, “Show all in Translation Center”, “Translate all missing” | Translate per item and Translate all missing; no “Open Translation Center” in the top bar and no “Show all” | the navigation already opens Translation Center, and Translate all missing is the drawer's one way into it |
| Translation Center AI | Generate missing and Save all generated in the top bar, AI translate and Regenerate in each row, the AI suggestion state, “What AI translate sends” in the context drawer, sparkles on the unsaved strip | the same controls, with Generate missing and Save all generated in the header band after the figures, one at a time — Save all generated replaces Generate missing while suggestions wait for review — and sparkles on the strip while an AI suggestion is unsaved (the info icon otherwise) | the actions belong to the target language the header band chooses, and the top bar holds no screen's actions; generating again while suggestions wait would pay for them twice |
| Translation Center Generate missing | Generate missing starts at once | it asks first (OVL-01), saying it covers every missing item whatever the filters show, runs in the background, and saves nothing | one press starts paid work for a whole language, and the filters on screen could suggest it covers less |
| Translation Center AI button | AI translate shown on every row, labelled Regenerate once a suggestion exists | AI translate on a Missing row, Suggest alternative on a Saved row, Regenerate on an AI suggestion; none on a typed draft | the label says what the suggestion will do to the row, and a suggestion never overwrites text somebody typed |
| Translation Center while generating | the target field is replaced by a box: spinner and “Generating a suggestion from context…” | the field stays where it is, read-only, its cell `aria-busy`; the spinner and the same words take the note's place under it — “Generating in background…” for a row waiting for Generate missing | a field that disappears takes focus and the suggestion being regenerated with it |
| Translation Center figures | the result count reads “… on this page” and the footer “1–N of M missing items” | the toolbar counts “N of M items”; the footer keeps only the note about interface strings | every unit of the language is on the page; there is no pagination |
| Translation Center title | none: the header band holds the combobox and the figures | the same, with the page's `h1` visually hidden | the screen still needs a heading for assistive technology and for focus to return to |
| DOM-01 placement | the state badge and note above the target field | under the field, after the counter and before the actions; a note too long for the line wraps under the badge | the target field starts level with the English text it translates, and a long note never pushes the actions aside |
| Languages table on narrow widths | the card scrolls the grid horizontally | tightened columns down to 876, then one labelled block per language | the screen never scrolls sideways; the locale code moves beside the names, so no column is lost |
| Translation Center and Languages search | a plain search field | a clearable one, with × while it holds text | a query is cleared in one press |
| Toast Undo | Undo restores silently | Undo restores and says so (“Dogs is active again”) | the change is a write like any other, and is confirmed like one |
| Category and tag slugs | `/c/dogs`, `/t/zoomies`, and a `pawrank.com/c/` prefix in the slug field | the slug as stored, no prefix; the warning names feed links filtered by it | categories and tags are feed filters (`/?category[]=dogs`, `/?tag=zoomies`); there are no `/c/` or `/t/` pages |
| Categories and Tags translations note | “Translations are edited in Translation Center. Changing the English name doesn’t change them.” beside Translate | Translate alone | Translation Center is already the single editor of translations, and the cutover put no note where the language tabs were |
| Categories and Tags locale chips | every language the prototype knows | the languages the project offers besides English; for categories, missing counted over active ones | visitors meet the offered languages, and Translation Center lists only what visitors can see |
| Merge into… | merges a tag into another, and a tag in use offers “Merge into another tag” | unavailable (“Not available yet”); a tag in use is a blocked dialog | merging tags needs a domain action of its own, to be designed once every section is on v2 (migration plan) |
| Categories and Tags delete footnote | “Recorded in the audit log as …” | none | no log records a category or tag deletion |
| Delete from the drawer | the dialog opens over the drawer | the dialog replaces the drawer | Categories and Tags: the drawer's one destructive action replaces it; an editor's drawer keeps its confirmations over it |
| Translate on Categories and Tags | opens Translation Center on the section | opens it on the record, in the first offered language it is missing from | the record is what was asked about |
| Rating group slots | 6 slots in the list, 8 in the editor | one past the widest range in the list, alike for every row; 10 in the editor | a group's range goes up to 10 active options, and every row's slots line up |
| Rating group and option limits | description counted to 120, option label to 20, ranges 1–8 | description to 1,000, label to 120, ranges 2 to 10 | the limits the content had before the redesign, which its translations are held to |
| Rating group Delete | a group without votes can be deleted | unavailable: “Groups are kept · deactivate instead” | a group is never deleted; deactivating keeps its votes and its place in results |
| Rating group and option hints | “Shown as the question above the options on post pages”, “Tooltip on the vote button. Also given to AI translation as context.” | “What the group asks voters about.”, “What a vote for this option means.” | descriptions are not shown on post pages; the hint says what they are |
| Group card translations note | “English reference text. Translations are managed in Translation Center.” | “English reference text.” | as on Categories and Tags: Translation Center is already the one editor of translations |
| Group key on create | a read-only key | made from the label until typed (FRM-04), fixed once saved | a new group needs a key, and nobody should have to type one |
| Active options check outside the range | a danger strip | a warning notice | FBK-02 keeps danger for irreversible dialogs |
| Option range for inactive groups | blocked only while the group is active | refused for every group | the option actions have always held every group's active options inside its range |
| New option | inactive by default | active while the group has room for one more | an option is usually made to be voted for; at the maximum it is inactive and says why |
| Archive Undo | Undo restores an archived option | no Undo: the archive is confirmed, and Unarchive is in the row menu | archiving asks first, so it is not a reversible action that toasts with Undo |
| Translate on Rating groups | opens Translation Center on the section | on the group's first missing label or description | the record is what was asked about |
| Create group | the new group's page saves in place | Create group opens the group's editor, where its options are made | options belong to a group that exists |
| Static page content | a rich text editor (headings, bold, italic, lists) | a plain textarea: “Plain text. Line breaks are kept.” | the public pages show the content as plain text with its line breaks; markup typed here would be shown as typed |
| Static page “last edited” | “last edited 12 Sep 2026 by Daria Kovaleva” | the page's path only | nothing records who changed a static page, or when |
| Unconfigured sign-in provider | turning it on is refused in a dialog: the server has no key | it can be on; its row says Not configured with the keys it needs, and its buttons stay hidden until they are set | switching a provider on is the project's decision, and setting the keys is the server's; either can come first |
| Setting text limits and counters | site name 40, tagline 60, description 200, each field counted | the limits the translations of each text are held to; a counter on the multi-line fields, `maxlength` on single-line ones | one limit for a text and its translations; a single-line field stops at its limit as it is typed |
| Installation preset | “Preset” over the name; compared with every setting | “Installation preset”; the site's identity is not compared | the preset is applied once at installation, and the site's name and copy are the project's own |
| Posts author trust | New, Regular or Trusted | Trusted, Not trusted or Email not confirmed | the application knows trust as a threshold, and a post waits when its author is below it or has not confirmed their email |
| Posts header text | “Posts from new and regular users wait here before publishing. Trusted users skip the queue.” | “Posts wait here before publishing while their author isn’t trusted or hasn’t confirmed their email. Everyone else publishes at once.” | it states the rule the application applies |
| Post ids | “P-48213” | “#48213”, and the search finds “#48213” or “48213” | the id as stored |
| Restore window | “Restorable until 16 Oct”, 14 days after hiding | “Restorable”, until the removal is finalized | nothing closes the window but finalizing |
| Approve Undo | Undo after approving, alone or selected | none | approving notifies the author and the author's followers; Hide takes a post back down |
| Reject and Hide reasons | “Shared with the author”; the author is notified of a rejection | “Stored in the moderation log.” | neither is sent to the author |
| Moderation reason chips | Not a pet, Spam or advertising, Animal sale, Graphic content, Duplicate, Low quality | Spam, Offensive, Fake, Copyright, Wrong category | the reasons visitors report with, for a project about anything |
| Finalize removal | on hidden, rejected and author-deleted posts | on hidden posts only, by an administrator | FinalizePostRemovalAction finalizes a hidden post only |
| View author | opens Users filtered to the author | opens the author's user page, for whoever may manage them | the person is what was asked about |
| Original image | a placeholder lightbox | the original, with its size, type, file size and disk | the file is there to show |
| Posts empty queue | “New submissions appear here automatically.” | “New submissions appear here when you refresh.” | the list is read on load and on Refresh |
| Posts grid | `44px 56px minmax(200px,1fr) 160px 140px 80px 120px 192px`, 992 | Author 176 and Created 136, 1024 | the trust words and “Waiting 3 h 12 min” fit without wrapping |
| Comment ids | “C-90412”, the post's “P-48166” | “#90412”, the post's “#48166” | the ids as stored, as Posts shows them |
| Comment restore window | “Restorable until 17 Oct” | “Restorable”, until the removal is finalized | as for posts |
| Comment Hide reasons | Spam or advertising, Harassment, Off-topic, Misinformation | Spam, Offensive, Fake, Copyright | the reasons visitors report a comment with |
| Finalize a comment | on hidden and on any author-deleted comment | on a hidden comment, its author's deleted one included | FinalizeCommentRemovalAction finalizes a hidden comment only |
| Comments grid | `minmax(240px,1fr) 150px 180px 80px 150px 104px 140px`, 1044 | State 170 and Created 120, 1080 | “Hidden by moderation” and the time fit without wrapping |
| Report ids | “R-2284”, the target's “P-48090” | “Report #2284”, the target's “#48090” (“@name” for a person) | the ids as stored, as the other lists show them |
| Report targets | posts and comments | posts, comments and people, with Type: People | a person can be reported, and their report reads as their chain |
| Report order | oldest first in every tab | Open oldest first; Resolved, Ignored and All newest first | the queue waits oldest first, and a closed report is looked up by when it came in |
| Hide target reasons | “Shared with the author.”; pet-specific chips | “Stored in the moderation log.”; the reasons visitors report with | a hide's reason is not sent to the author |
| Ban reasons | Repeated spam, Harassment, Animal sale, Ban evasion | Repeated spam, Harassment, Scam, Ban evasion | for a project about anything |
| What a ban does | “Signs them out everywhere and blocks sign-in.” | “They can still sign in, browse and manage their account.”, and no new posts, comments, votes, reports or follows | a ban stops participation and never locks anyone out of their account (docs/architecture/user-lifecycle.md) |
| Reports empty queue | “New reports appear here as they arrive.” | “New reports appear here when you open the page again.” | the list is read on load |
| Trust levels | New, Regular and Trusted, with upload caps | Not trusted and Trusted | trust is one threshold: at or above it a person's posts publish at once, once their email is confirmed; nothing caps uploads |
| What Limit and Shadowban do | Limit: posts reviewed, uploads capped; Shadowban: activity visible only to them, not notified | both stop new posts, comments, votes, reports and follows, and keep the content visible | the lifecycle as it is (docs/architecture/user-lifecycle.md): no viewer-dependent visibility exists |
| Sanctioning staff | “Change the role to User first” | moderators can be sanctioned; administrators can’t, and nobody their own account | UserPolicy as it is |
| Mark trusted Undo | Undo after marking someone trusted | none | nothing takes trust back but an edit of the person |
| Sanction Undo | Undo after any sanction | Undo from an active account only | restoring access returns someone to Active, not to the sanction they had before |
| Last active | “Last active Today 08:15” | not shown | nothing records when someone was last active |
| New password | at least 10 characters; signs other sessions out | the application's password rule; other sessions stay | the rule people's own passwords follow, and saving does not end sessions |
| Person's editor | User edit, a page of its own with Cancel and Save changes in the top bar | a 680 drawer over the list, Cancel and Save changes in its footer | edited the way a persona is, over the list it is opened from |
| Users grid | `minmax(200px,1fr) 220px 110px 140px 120px 70px 80px 110px`, 1050 | Trust 150, 1080 | “Not trusted” beside its bar fits without wrapping |
| Next scheduled audit | “Next scheduled audit Tomorrow 04:00” in the header band | not shown | no audit is scheduled; one runs from the top bar or `media:audit` |
| Asset ids | “A-208113” | “#208113”, and the search finds “#208113” or “208113” | the id as stored, as the other lists show theirs |
| Issue type names | Missing master, Missing variant, Unreferenced asset, Purgeable asset, Physical orphan | Missing master file, Missing variant file, Active unreferenced asset, Purgeable asset, Physical orphan candidate, Failed generation job | the issue types the audit records |
| Asset preview | a 168 high preview at the top of the inspector | none | the inspector checks files, it does not render them |
| Force regenerate | not in the reference | in the inspector, under the files of an asset still active, after a warning confirmation | the page has always offered replacing an asset's variants from its master |
| Media diagnostics grid | `104px 160px 104px 104px 96px minmax(200px,1fr) 104px 210px`, 1100 | `96px 170px 88px 132px 80px minmax(160px,1fr) 120px 210px`, 1056 | the variant names as stored (`post_detail_1920`) and “Yesterday 23:39” fit, and the grid fits the card at 1440 |
| Overview figures | at least 180 wide | at least 160 wide | two figures a row on a phone instead of ten one under another |
| Purge confirmation | a typed asset id and an acknowledgement; “Recorded in the audit log as …” | the acknowledgement alone; no footnote | the acknowledgement is the irreversible level, as Finalize removal has it; no log records a purge |
| Release facts | “Checked just now: no post, avatar or static page uses this file.” | “Checked again when you confirm: an asset something uses now is left as it is.” | the references are checked on confirming, not on opening the dialog |
| Failed job attempts | “attempt 3 of 3” | not shown | the failed job record keeps no attempt count |
| Run full audit progress | a progress bar while the audit runs | “A full audit is running” in the header band; Refresh reads the outcome | the audit runs in the background and reports no progress |
| Translate on Project settings | opens Translation Center on the section | on the first missing text of the card or the page, in the first offered language it is missing from | the text is what was asked about |
| Global search | sidebar search over records, settings, pages, languages and media | the same field and results, over the records Filament's global search finds | see [Transitional omissions](#transitional-omissions) |
| Sidebar header | ~73 high, its hairline below the top bar's | 62, as tall as the top bar | the two hairlines run as one line |
| Workspace switcher | a switcher button with a chevron | a static identity block | there is only one workspace |
| Translation AI period and Refresh | “Updated”, the period and Refresh in the top bar | in the header band, under the model filter | they drive this page's own state, and the top bar holds no screen's controls |
| Translation AI model statuses | Default, Active and Retired models, from a fixed list | Current or Not current, from the models recorded and the one configured | the application knows which model is configured and which were used, not a lifecycle of models |
| Translation AI health | Configured (success, dot) before telemetry existed; Degraded in red | Configured is neutral; Degraded is warning, as STS-01 has it | a configured key is not proof that translation works |
| Translation AI recent calls | eight columns, a batch id under Generate missing, 20 a page, All / Failed | a Model column, no batch id, 25 a page, All / With issues | the call records its model and no batch; NAV-04's page size; a successful call with failed items has issues too |
| Translation AI failures | one count per error type | failed calls and failed items side by side, item-only types without a pattern | one timeout of ten items is one failed call and ten failed items, and a refused translation is not an outage |
| Translation AI systemic issue | a danger-tinted line in Failures and issues | the FBK-02 danger strip, outside a dialog | FBK-02 keeps danger for irreversible dialogs; the Translation AI reference draws an ongoing outage in it, and nothing else on the page is danger-toned |
| Translation AI chart | bars drawn by the screen | the same, as the screen's own element | the kit reference names no chart, so none is a kit component; it becomes one, with a reference ID, when a second screen needs it |
| Translation AI background generations and configuration | not in the prototype | a Background generations card and a folded Configuration and guardrails card | the technical monitoring the page is for, from the store and configuration that already exist |
| Publications & plans figures | Total publications, Drafts, Scheduled, Published | Total plans, Drafts, With image, With author | nothing approves, schedules or publishes a plan yet, and no figure is drawn that cannot be counted |
| Publications & plans columns and states | Date · launch, Comments; Scheduled, Publishing, Published, Plan running, Error; Open plan, Unschedule, View errors, View post | Image, Publication date and Last updated; the plan's own status; Edit draft and Delete draft | a plan holds no comments, no run and no post yet; no action is drawn that nothing does |
| Publication editor footer | Back, Step n of 4, Continue, and Review and confirm on the last step | Back, Step n of 4 and Continue; the last step has none, and Continue waits only for a photo on its way | nothing confirms or publishes a plan yet; the draft saves from the top bar, and no step is locked by an incomplete draft |
| Image sources | Upload, From link and From social post, “up to 20 MB”, Re-run analysis | the same three, the limit `uploads.images.max_kilobytes` sets, a link imported once it is complete instead of Load image or Import image, and the sites the importer reads directly marked; Re-run analysis or Try again after the first analysis, which starts by itself | the upload limit is the site's; a social post's image is found by the site's own import (`ImportFromUrlAction`), which reads four sites directly and any other public page by its preview image |
| Post texts | Tone of new variants, 10 titles, 10 descriptions, Generate 10 more and a Generate of its own on each column, the first set written on entering the step; Final description up to 500 | one Generate for both columns, in the Context card, and nothing written until it is pressed; the newest batch shown, each earlier one kept on the server but no longer in view; Final description up to 2,000 | a batch is one paid request for both columns, asked for only by Generate, and kept on the server so reopening the draft shows it again without another; a post's description takes 2,000 characters |
| Post author picker | Editorial accounts, Users and Personas, Pick random | living personas, searched on the server | a plan's author is a persona and nothing else |
| Votes tab and Discussion | post votes, voters, timing; Comment votes and Comment voters cards, Comment votes and Voters tabs, priorities, a reply to a reply | no votes, voters or priorities; a reply answers a comment on the post; Generate writes from saved texts only | persona votes come with their own stage; a discussion has two levels |
| Publication step | overdue actions, an action plan, and a note that real timestamps are never backdated | the mode, the date and the publication card | there is no action plan yet, and the post will take the plan's date as its own timestamps (H1) |
| Persona editor fields | City, Country, Active from and until, Generate avatar, Use initials and Try it with Another reply | none of them | geography and an hourly schedule are not part of a persona; generated avatars and sample replies wait for AI, and no button is drawn that does nothing |
| Personas figures and columns | Inactive, Replies a day, Replies · 7 days; Plans, 7 days and Now a day columns | Total, Active, Restricted and Replies on; no plan or reply columns | nothing records plans or replies yet, and no figure is drawn that cannot be counted |
| Personas status | Active or Inactive, with Deactivate and Activate in the row | the account's lifecycle status, changed in Users | engagement Off and the account's status are separate things, and there is no inactive account |
| Personas engagement and limit in the row | segmented control and stepper editable in each row | read-only in the row, edited in the persona's editor | every change is saved from the editor, through the action that checks it |

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
| Status | STS-01 Status badge · STS-02 Counters · STS-03 Progress bar · STS-04 Active range slots · STS-05 Locale chips | all |
| Forms | FRM-01 Search field · FRM-02 Text field · FRM-03 Textarea with counter · FRM-04 Locked identifier · FRM-05 Number stepper · FRM-06 Toggle switch · FRM-07 Checkbox · FRM-08 Segmented control · FRM-09 Radio cards · FRM-10 Filter dropdown and chips · FRM-11 Searchable combobox | all |
| Navigation | NAV-01 Sidebar · NAV-02 Top bar and breadcrumb · NAV-03 Status tabs · NAV-04 Pagination | all; NAV-01 and NAV-02 also run as the production shell; NAV-04 as a live, reusable component |
| Layout | LAY-01 Page header · LAY-02 Card and sections · LAY-03 Detail rows and section labels | all |
| Tables | TBL-01 Table row · TBL-02 Table toolbar · TBL-03 Bulk action bar · TBL-04 Row actions and menu · TBL-05 Empty states · TBL-06 Loading skeleton | all; TBL-03 and TBL-04 as live, reusable components |
| Overlays & feedback | OVL-01 Confirmation dialog · OVL-02 Drawer · FBK-01 Toast · FBK-02 Inline notice | all; OVL-01, OVL-02 and FBK-01 as live, reusable components |
| Admin-specific | DOM-01 Translation field states · DOM-02 Report chain | all; DOM-02 as a live, reusable component (`x-admin.ui.timeline-item`) |

The [migration plan](migration-plan.md) says when the remaining elements are built.
