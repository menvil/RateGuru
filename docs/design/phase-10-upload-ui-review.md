# Phase 10 Upload UI Review

## Reference checked
- [x] docs/design/reference/original/PlateRate.html
- [x] docs/design/design-contract.md
- [x] docs/design/ui-review-checklist.md
- [ ] docs/design/reference/screenshots/ (directory exists, reference reviewed conceptually)
- [ ] /dev/ui-kit (upload modal not added to ui-kit in this phase)

## Upload modal
- [x] Dark modal surface preserved (`bg-rg-card`, `bg-rg-card2` tokens)
- [x] Rounded panel/card style preserved (`rounded-2xl`, `rounded-rgCard`)
- [x] Purple primary action used (`bg-rg-accent` on submit button)
- [x] Close behavior works (close button inside modal via `x-ui.modal`)
- [x] Escape close behavior implemented (`@keydown.escape.window="open = false"`)
- [x] Backdrop click close implemented (via `x-ui.modal` backdrop `x-on:click="open = false"`)
- [ ] Mobile layout — manual check required
- [ ] Desktop layout — manual check required

## Form fields
- [x] Title input exists (`wire:model.defer="title"`, `x-ui.input`)
- [x] Description textarea exists (`wire:model.defer="description"`, `x-ui.textarea`)
- [x] Image input exists (`type="file"`, `wire:model="image"`, `accept="image/*"`)
- [x] Image preview exists (Alpine `x-data="{ previewUrl: null }"` with FileReader)
- [x] Source URL input exists (`type="url"`, `wire:model.defer="sourceUrl"`)
- [x] Optional category selector exists and uses configured options.
- [x] Optional author answers render for all active rating groups.
- [x] Tags placeholder exists ("Tag selection coming soon")

## States
- [x] Missing title validation visible (`data-testid="field-error-title"` with `x-input-error`)
- [x] Missing image validation visible (`data-testid="field-error-image"` with `x-input-error`)
- [x] Loading state visible (`wire:loading` on submit button, "Uploading..." text)
- [x] Generic error state visible (`x-ui.error-message` when `$submitError` is set)
- [x] Success event closes modal (`@post-uploaded.window="open = false"`)
- [x] Feed refreshes after success (`#[On('post-uploaded')]` listener on PostFeed)

## Test coverage
- [x] `composer test` passes (326 tests, 719 assertions)
- [x] `npm run build` passes

## Known deviations
- Upload button placement: currently above the "Latest dishes" section. Original design may show it differently (e.g., floating button or in header). Acceptable for MVP — Phase 11+ may adjust placement.
- Tags: placeholder only, no actual tag picker. Documented as future work.
- Image preview uses Alpine FileReader (client-side only) — not Livewire server-side preview. This is the correct approach for performance.
- Submit button uses `wire:click="submit"` (not form submit). Both patterns are functionally equivalent in Livewire.
- `/dev/ui-kit` does not include upload modal preview — could be added in a future documentation task.
