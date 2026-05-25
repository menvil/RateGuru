# Phase 37 UI Polish Review

## Reference checks

- [x] Checked original prototype reference via `/dev/ui-kit` PlateRate Reference Composition.
- [x] Checked `docs/design/design-contract.md`.
- [x] Checked `docs/design/ui-review-checklist.md`.

## Phase 37 checklist

- [x] RG-598 feed spacing checked.
- [x] RG-599 card radius checked.
- [x] RG-600 background checked.
- [x] RG-601 accent purple checked.
- [x] RG-602 header checked.
- [x] RG-603 upload button checked.
- [x] RG-604 vote states checked.
- [x] RG-605 origin controls checked.
- [x] RG-606 cuisine controls checked.
- [x] RG-607 drawer width checked.
- [x] RG-608 drawer animation checked.
- [x] RG-609 modal backdrop checked.
- [x] RG-610 mobile card checked.
- [x] RG-611 desktop two-column checked.
- [x] RG-612 hover states checked.
- [x] RG-613 focus states checked.
- [x] RG-614 disabled states checked.
- [ ] RG-615 loading states checked.

## RG-598 notes

- Feed container now uses mobile `px-4 py-5`, desktop `px-8 py-8`, and a wider desktop max width while preserving a focused feed column before the later two-column task.
- Search, category tabs, sort controls, feed title, loading state, empty state, and cards share the same vertical rhythm.
- No feed query, sorting, upload, drawer, or voting behavior was changed.

## RG-599 notes

- Card, empty state, dropdown, drawer, and product surfaces already used `rounded-rgCard` / related RateGuru radius tokens.
- Modal content shell now uses `rounded-rgCard` instead of raw `rounded-2xl`, keeping large surfaces visually related to cards.
- Control, media, pill, and avatar radii remain on their existing purpose-specific tokens.

## RG-600 notes

- App shell body, topbar, optional header band, brand mark, and auth text actions now use existing RateGuru dark/background/text tokens.
- Existing component surfaces already use `bg-rg-card`, `bg-rg-card2`, `border-rg-border`, and `text-rg-*` tokens.
- Legacy Breeze components still exist for auth/profile internals and will be handled only where they surface in scoped UI tasks.

## RG-601 notes

- Primary button and active sort states now use `text-rg-onAccent` instead of raw `text-white`.
- Input, textarea, and reference topbar focus rings now use `focus-visible:ring-rg-accent/25` instead of raw RGBA values.
- UI kit accent examples remain on `bg-rg-accent`, `bg-rg-accentSoft`, `border-rg-accent`, and `text-rg-accent2`.

## RG-602 notes

- App header now exposes `data-testid="app-header"` and uses a 60px minimum height to match the reference topbar density.
- Header spacing is compact and responsive: brand left, authenticated actions right, and no guest-only layout wrap.
- Existing notification bell is rendered alongside upload/logout for authenticated users without adding new navigation behavior.

## RG-603 notes

- Header upload CTA now uses `x-ui.button` with the primary accent style, upload icon, and `shadow-rgUpload`.
- Mobile keeps a compact `Post` label while preserving the `Create post` text in the authenticated header markup.
- Upload modal Alpine open/close behavior is unchanged.

## RG-604 notes

- Post vote buttons now expose `aria-pressed` and `data-state` for selected state visibility.
- Upvote active state uses success tokens; downvote active state uses danger tokens; idle buttons keep card surface and accent-border hover.
- Loading disabled markup is preserved, and focus-visible rings use the accent token.

## RG-605 notes

- Origin voting pills now expose `aria-pressed` and `data-state` based on the authenticated user's selected origin vote.
- Selected pills use `bg-rg-accentSoft`, `border-rg-accent`, and readable text tokens; idle pills keep subtle card surfaces.
- Pills can wrap on narrow widths while preserving distribution bar alignment.

## RG-606 notes

- Cuisine vote chips now expose `aria-pressed` and `data-state` based on the authenticated user's selected cuisine vote.
- Selected chips share the origin pill accent-soft language; idle chips retain subtle card surfaces and accent-border hover.
- Chips use flexible wrapping with stable minimum height for mobile density.

## RG-607 notes

- Large right-side drawers now use `md:max-w-xl lg:max-w-2xl`, landing in the intended desktop width range while keeping `w-full` mobile behavior.
- XL drawers scale intentionally to `md:max-w-2xl lg:max-w-3xl`; default/mobile positioning is unchanged.
- Drawer content scrolling and backdrop behavior are unchanged.

## RG-608 notes

- Drawer backdrop and panel transitions now use `motion-safe` transition utilities and `motion-reduce:transition-none`.
- Existing 200ms enter / 150ms leave timing and translate directions are preserved for responsive close behavior.
- Close button, escape, backdrop, and click-outside dispatch paths are unchanged.

## RG-609 notes

- Modal shell backdrop now exposes `data-testid="modal-backdrop"` and keeps the shared `bg-black/70 backdrop-blur-sm` treatment.
- Modal open/close fade uses 200ms enter / 150ms leave motion-safe opacity transitions with reduced-motion fallback.
- Upload, report, and confirmation modals continue to inherit the same `x-ui.modal` shell.

## RG-610 notes

- PostCard shell now hides overflow, truncates author metadata, and uses `break-words` on title/description.
- Stats and vote controls can wrap on narrow screens without changing feed behavior.
- Image and placeholder aspect ratios remain stable.

## RG-611 notes

- Post show now uses a responsive `lg:grid-cols-[minmax(0,1fr)_360px]` layout with main post/comments content and an existing side panel.
- The side panel contains existing voting, vote summary, share, and related sections; no new sidebar content was introduced.
- Mobile remains single-column because the grid only activates at `lg`.

## RG-612 notes

- Clickable PostCard surfaces now have subtle border/surface hover feedback.
- Copy-link buttons now hover both border and surface while keeping text contrast.
- App and guest brand links have explicit hover affordance without changing navigation behavior.

## RG-613 notes

- Keyboard-reachable PostCard surfaces now have accent focus-visible rings and shell-colored offsets.
- Copy-link buttons now have the same accent focus-visible treatment as base buttons.
- App and guest brand links include explicit focus-visible rings.

## RG-614 notes

- Reusable inputs and textareas now use tokenized disabled surface/text classes in addition to cursor and opacity changes.
- Base buttons and Livewire voting/upload submit controls already carry real `disabled` / `wire:loading.attr="disabled"` behavior.
- Disabled styling remains token-based and does not affect validation error rendering.
