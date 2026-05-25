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
- [ ] RG-604 vote states checked.
- [ ] RG-605 origin controls checked.
- [ ] RG-606 cuisine controls checked.
- [ ] RG-607 drawer width checked.
- [ ] RG-608 drawer animation checked.
- [ ] RG-609 modal backdrop checked.
- [ ] RG-610 mobile card checked.
- [ ] RG-611 desktop two-column checked.
- [ ] RG-612 hover states checked.
- [ ] RG-613 focus states checked.
- [ ] RG-614 disabled states checked.
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
