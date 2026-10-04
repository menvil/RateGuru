# Phase 8 Feed UI Review

## Reference checked
- [x] docs/design/reference/original/PlateRate.html
- [x] docs/design/design-contract.md
- [x] docs/design/ui-review-checklist.md
- [x] /dev/ui-kit (Feed Components section added)
- [ ] docs/design/reference/screenshots/ (not populated in repo)

## Feed page
- [x] Dark background preserved (rg-bg / zinc-950 from app layout)
- [x] Header shows RateGuru with subtitle
- [x] Feed container width is controlled (max-w-xl)
- [x] Mobile layout checked (RG-183)
- [x] Desktop layout checked (RG-184)

## PostCard
- [x] Uses x-ui.card (variant="post")
- [x] Image area exists (aspect-video img or x-ui.image-placeholder fallback)
- [x] Author area exists (x-ui.avatar + name + @username)
- [x] Title area exists (h3 + optional description with Str::limit)
- [x] Stats area exists (score, comments, and configurable rating summaries)
- [x] Missing image fallback works (x-ui.image-placeholder "Food image")
- [x] Missing description does not break layout
- [x] Missing user relation does not break layout

## States
- [x] Empty feed state exists (x-ui.empty-state "No dishes yet")
- [x] Loading skeleton exists (wire:loading + x-ui.skeleton)

## Mobile pass (RG-183)

Layout: `max-w-xl px-4 py-6 sm:px-6`

Observations:
- Feed container constrained to `max-w-xl` — single column on all widths
- `px-4` horizontal padding prevents cards touching viewport edges
- PostCard `p-[14px]` is compact but readable
- Image `aspect-video` maintains ratio on all widths
- Author row `gap-2` does not wrap on narrow screens
- Stats footer `flex-wrap gap-2` — badges wrap safely on narrow screens

Known gaps:
- No hardware device testing — verified by layout class analysis
- No swipe/touch refinements (Phase 9+)

## Desktop pass (RG-184)

Layout: `sm:text-3xl` on h1, `lg:py-10` for larger vertical rhythm.

Observations:
- Feed column `max-w-xl` centered — matches reference single-column feed
- Desktop sidebar/topbar deferred to Phase 9+
- Card density consistent at `p-[14px]`
- No horizontal overflow
- Visual rhythm: header → section title → cards with `gap-4`

## Known deviations from PlateRate reference
- No sidebar on desktop (Phase 9+)
- No topbar with search/upload/notifications (Phase 9+)
- Feed column wider than reference due to missing sidebar — acceptable for Phase 8
- Vote rail not shown (Phase 13+)
- Configurable rating controls were scheduled for a later phase.
- PostCard stats are read-only display only (no interactive voting)

## Test suite
- All 261 tests pass
- npm run build passes
