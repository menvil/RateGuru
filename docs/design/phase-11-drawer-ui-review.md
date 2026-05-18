# Phase 11 Drawer UI Review

## Reference checked
- [ ] docs/design/reference/original/PlateRate.html
- [ ] docs/design/reference/screenshots/
- [ ] docs/design/design-contract.md
- [ ] docs/design/ui-review-checklist.md
- [ ] /dev/ui-kit
- [ ] docs/design/phase-8-feed-ui-review.md
- [ ] docs/design/phase-10-upload-ui-review.md

## Drawer shell
- [ ] Dark surface preserved
- [ ] Backdrop exists
- [ ] Close button exists
- [ ] Escape close works
- [ ] Mobile drawer checked (RG-239)
- [ ] Desktop right-side drawer checked (RG-240)

## Content
- [ ] Large image renders
- [ ] Missing image placeholder works
- [ ] Title renders
- [ ] Description renders
- [ ] Author metadata renders
- [ ] Vote summary renders read-only
- [ ] Comments placeholder renders
- [ ] Not found state works
- [ ] Loading state exists

## Mobile pass (RG-239)
- Drawer uses `inset-x-0 bottom-0 max-h-[90vh] rounded-t-rgCard` on small screens
- Scrollable content inside drawer
- Close button in header is reachable
- Backdrop covers the feed
- Manual check: pending

## Known deviations
- Alpine transitions are configured for right-side (translate-x-full) — mobile bottom sheet may not animate on open/close. Acceptable for Phase 11; animation refinement is a future task.
- No standalone post show route (Phase 12).
- No voting interactions (Phase 13+).
- Comments placeholder only — no real comments list (Phase 17/18).
