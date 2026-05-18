# Phase 11 Drawer UI Review

## Reference checked
- [x] docs/design/design-contract.md — dark surfaces, purple accents, rounded cards
- [x] /dev/ui-kit — existing component tokens used (rg-card, rg-border, rg-accent)
- [ ] docs/design/reference/original/PlateRate.html — visual comparison pending manual check
- [ ] docs/design/reference/screenshots/ — pending manual check
- [ ] docs/design/phase-8-feed-ui-review.md — referenced for consistency
- [ ] docs/design/phase-10-upload-ui-review.md — referenced for drawer pattern consistency

## Drawer shell
- [x] Dark surface preserved — `bg-rg-card` background
- [x] Backdrop exists — `bg-black/70` overlay
- [x] Close button exists — `data-testid="post-drawer-close"` with `x-ui.icon name="x"`
- [x] Escape close works — `@keydown.escape.window` on drawer root
- [x] Mobile drawer layout: `inset-x-0 bottom-0 max-h-[90vh] rounded-t-rgCard`
- [x] Desktop right-side layout: `md:inset-y-0 md:right-0 md:h-dvh md:max-w-lg`

## Content
- [x] Large image renders — `aspect-[4/3]` with `object-cover`
- [x] Missing image placeholder works — `x-ui.image-placeholder` with "Image preview"
- [x] Title renders — `<h2>` with `font-bold text-rg-text`
- [x] Description renders — `<p>` with `text-rg-muted`, skipped gracefully when null
- [x] Author metadata renders — avatar, name, username, published timestamp
- [x] Vote summary renders read-only — Score, Homemade, Restaurant in 3-column grid
- [x] Comments placeholder renders — "Comments will appear here" via `x-ui.empty-state`
- [x] Not found state works — `x-ui.error-message` for invalid/hidden post ids
- [x] Loading state exists — `wire:loading` skeleton markup

## Mobile pass (RG-239)
- Drawer uses `inset-x-0 bottom-0 max-h-[90vh] rounded-t-rgCard` on small screens
- Scrollable content inside drawer
- Close button in header is reachable
- Backdrop covers the feed
- Manual check: pending visual verification in browser

## Desktop pass (RG-240)
- Drawer uses `md:inset-y-0 md:right-0 md:h-dvh md:max-h-none md:max-w-lg` on medium+ screens
- Right-side panel with full height
- Content scrollable inside drawer
- Feed visible behind backdrop
- Manual check: pending visual verification in browser

## Test results
- `composer test`: 348 tests pass
- `npm run build`: ✓ built successfully

## Known deviations
- Alpine transitions configured for right-side (translate-x-full). Mobile bottom sheet appears without slide-up animation. Acceptable for Phase 11; animation refinement is a future task.
- No standalone post show route — Phase 12.
- No voting interactions — Phase 13+.
- Comments placeholder only, no real comments list — Phase 17/18.
- No share panel, SEO/OpenGraph, related posts — later phases.
