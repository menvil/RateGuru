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
- Food placeholder palettes: carbonara and matcha are first-class Phase 1 palettes.

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
- Dish placeholder
- Binary choice
- Cuisine chips
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
