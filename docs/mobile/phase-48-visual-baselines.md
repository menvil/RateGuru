# Phase 48 — Mobile Visual Baselines

Accepted visual baseline state after Phase 48 Mobile UX Pass.
Capture screenshots at these viewports to establish baselines.

## Viewports

| Name          | Width × Height |
|---------------|---------------|
| small-mobile  | 375 × 812     |
| mobile        | 390 × 844     |
| large-mobile  | 430 × 932     |
| tablet        | 768 × 1024    |

## Screenshot Targets

Each `data-screenshot` attribute marks a capturable region.

| Target              | Route             | Notes                         |
|---------------------|-------------------|-------------------------------|
| `feed-page`         | `/`               | Feed with post cards visible  |
| `profile-header`    | `/profile/:user`  | Avatar, name, stats row       |
| `auth-page`         | `/login`          | Login card centered on screen |

## Acceptance Criteria (375px)

- No horizontal scrollbar (scrollWidth ≤ innerWidth + 1px)
- All text visible without truncation loss of meaning
- Tap targets ≥ 40px height for interactive elements
- Drawer opens as bottom sheet, not side panel

## Updated

Phase 48 — 2026-06-09
