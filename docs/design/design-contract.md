# PlateRate Visual Contract

## Source

- `docs/design/reference/original/PlateRate.html`
- `docs/design/reference/screenshots/*` when available
- `/dev/ui-kit` -> PlateRate Reference Composition

## Shell

- App background uses `--rg-bg` / `--rg-shell-bg`.
- Topbar height is 60px with logo, search, upload, notification, and avatar.
- Sidebar width is 240px with nav, categories, top tags, and footer links.
- Desktop uses feed/detail grid: feed column plus persistent right detail column.
- Dense columns are scrollable with custom dark scrollbars.

## Tokens

- Background tokens: `--rg-bg`, `--rg-shell-bg`, `--rg-topbar-bg`, `--rg-sidebar-bg`, `--rg-feed-bg`.
- Surface/card tokens: `--rg-surface`, `--rg-card`, `--rg-card-2`, `--rg-card-hover`.
- Border tokens: `--rg-border`, `--rg-border-2`, `--rg-border-soft`.
- Text tokens: `--rg-text`, `--rg-text-2`, `--rg-muted`, `--rg-muted-2`.
- Accent tokens: `--rg-accent`, `--rg-accent-2`, `--rg-accent-soft`, `--rg-accent-border`.
- Success/vote tokens: `--rg-good`, `--rg-good-soft`, `--rg-good-border`.
- Image placeholder palettes use neutral `warm`, `green`, `red`, `lime`, and `neutral` names.

## Typography

- Logo: 22px, extra-bold, white with purple accent.
- Nav item: 13.5px, medium/semibold.
- Metadata: 12px muted text.
- Card title: 16px, bold.
- Detail title: 22px, bold.
- Body: 13-14px with compact line-height.
- Chips: 12px, semibold.
- Actions: 13px, medium, icon plus text.

## Components

- Topbar
- Sidebar
- Feed tabs
- Post card
- Vote rail
- Image placeholder
- Binary choice
- Rating option chips
- Detail post
- Results panel
- Comments panel
- Upload modal
- Drawer

## Forbidden Visual Drift

- No default Laravel header in reference composition.
- No amber RG logo block in reference composition.
- No sky-blue focus rings.
- No abstract purple image placeholder.
- No generic SaaS card as product reference.
- No random zinc/amber/rose classes in reusable components unless a deviation is documented here.

## Documented Deviations

### Filament admin panel (`/admin`)

The admin is a separate design system, Admin v2, defined by
[`docs/design/admin/design-contract.md`](admin/design-contract.md). The two
systems intentionally share no visual tokens:

| | Public UI | Admin v2 |
|---|---|---|
| Tokens | `--rg-*` (`resources/css/theme.css`) | `--rg-admin-*` (`resources/css/filament/admin/tokens.css`) |
| Components | `x-ui.*` | `x-admin.ui.*` |
| Developer kit | `/dev/ui-kit` | `/admin/dev/ui-kit` |

`AdminPanelProvider` registers an independent custom Filament theme with
`->viteTheme('resources/css/filament/admin/theme.css')`. The public
`resources/css/app.css` and its `--rg-*` tokens never reach the admin shell,
so `text-rg-*` utilities do not belong in `resources/views/filament/**`; admin
views use the Admin v2 components and tokens instead. Only the Inter typeface
is shared.
