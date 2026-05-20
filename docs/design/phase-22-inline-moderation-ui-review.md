# Phase 22 — Inline Moderation UI Review

Date: 2026-05-20
Release: `v0.2.3-phase22-inline-moderation-ui`

## Reference checked

- `docs/design/design-contract.md`
- `docs/design/ui-review-checklist.md`
- `docs/design/phase-18-comments-ui-review.md`
- `docs/design/phase-20-reports-ui-review.md` *(absent — no prior review document for Phase 20; recorded here, implementation not blocked)*

## Checklist

- [x] **Moderator visibility** — panel renders only when `auth()->user()->isModerator()` or `auth()->user()->isAdmin()` returns true (both are methods on the `User` model). Negative tests for guest and normal user in `tests/Feature/Livewire/InlinePostModerationTest.php`.
- [x] **Pending / published / hidden button states** — each status shows only the actions it allows; negative tests guard every invalid pairing.
- [x] **Hide confirmation modal** — Alpine `x-data="{ confirmHideOpen: false }"` opens from the Hide button and closes on Cancel / Confirm hide.
- [x] **Reason input** — `name="moderation_reason"`, `maxlength="1000"`, `wire:model.defer="reason"`, per-card unique `id` suffix, forwarded to backend actions via `normalizedReason()`.
- [x] **Card refresh** — `post-moderated` event with `postId`/`action` is consumed by `PostFeed` via `#[On('post-moderated')]`, triggering a re-render. Regression test verifies the single component instance re-renders on dispatch.
- [x] **Backend exceptions surfaced** — `CannotModeratePostException` is caught in `runModerationAction()`, written into `$error`; `$success` stays null and no event is dispatched.
- [x] **Open in admin link** — rendered only for moderator/admin; live anchor only when `filament.admin.resources.posts.edit` exists, otherwise a `<span>` placeholder (no broken `href`).

## Notes

- The panel is mounted in `PostCard` under `@if ($post->exists)` so the dev UI kit's stub post (no id) does not crash the component.
- `render()` skips the post lookup for viewers who cannot moderate — no wasted query per card for guests and normal users.
- `InlinePostModeration` deliberately re-fetches the post fresh before each action (`Post::query()->findOrFail($this->postId)`) so a stale parent reference cannot leak into moderation operations.
- User moderation (`Ban`, `Shadowban`) is intentionally out of scope here — that surface ships with the Filament User Resource in Phase 25.
