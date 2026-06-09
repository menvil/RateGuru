## Summary

- 

## Verification

- [ ] I ran the relevant automated tests.

## Visual Review

- [ ] I ran browser smoke tests when this PR touches UI or Livewire flows.
- [ ] I ran `php artisan visual:screenshot all` when this PR changes public UI.
- [ ] I compared current screenshots against approved baselines.
- [ ] I updated approved baselines only for intentional visual changes.
- [ ] I checked desktop feed.
- [ ] I checked mobile feed.
- [ ] I checked upload modal.
- [ ] I checked post drawer.
- [ ] I checked post show.
- [ ] I confirmed no accidental debug text, broken images, or layout overflow is visible.

## Mobile QA (when this PR touches layout or UI)

- [ ] No horizontal overflow at 375px (scrollWidth ≤ innerWidth).
- [ ] All interactive elements have ≥ 40px tap target height.
- [ ] Text does not overflow containers (break-words applied where needed).
- [ ] Drawer opens as bottom sheet on mobile (not side panel).
- [ ] Locale and theme switchers fit within header at 375px.
