# Phase 18 Comments UI Review

Scope: RG-355 – RG-374 (Comments UI on top of the Phase 17 comments backend).

## Reference checked
- [x] docs/design/reference/original/PlateRate.html
- [ ] docs/design/reference/screenshots/ — directory exists but is empty (no baseline screenshots committed)
- [x] docs/design/design-contract.md
- [x] docs/design/ui-review-checklist.md
- [x] /dev/ui-kit (Comments section with CommentItem example)
- [x] docs/design/phase-11-drawer-ui-review.md
- [ ] docs/design/phase-12-post-show-page-review.md — **missing**: no Phase 12 review doc exists (docs/design contains phase-8, phase-10, phase-11 only). Recorded here as a known gap; not blocking Phase 18.

## Components
- [x] CommentsSection renders visible comments (oldest-first, eager-loads user)
- [x] CommentItem renders author (avatar + name + @username, safe when user missing)
- [x] CommentItem renders escaped body (no raw HTML, no markdown)
- [x] CommentItem renders timestamp (semantic `<time datetime>`, safe when created_at null)
- [x] CommentForm renders textarea (bound to body, maxlength=1000)
- [x] CommentForm renders submit button (wire:submit, loading label)
- [x] CommentForm renders validation errors (data-testid="comment-body-error")

## States
- [x] Guest sees login prompt instead of an active form
- [x] Empty state ("No comments yet") when no visible comments
- [x] Loading state markup during delete/hide (skeleton, data-testid="comments-loading")
- [x] Submit clears body only on success; preserved on validation failure
- [x] Comments refresh after submit (comment-created/deleted/hidden listeners, scoped to postId)
- [x] Delete own comment works via DeleteCommentAction; non-owner has no button
- [x] Moderator/admin hide comment works via HideCommentAction; normal user has no button

## Layout
- [x] Drawer layout — CommentsSection integrated into post-drawer (replaced placeholder)
- [x] Post show layout — CommentsSection integrated into post-show (replaced placeholder)
- [x] Dark-first surfaces reused (rg-card/rg-border tokens, x-ui.empty-state, x-ui.button, x-ui.skeleton, x-ui.avatar)
- [x] Mobile/drawer safe — compact author row, wrapping body, secondary destructive actions
- [x] Desktop post-show safe — section reused under existing Comments heading

## Backend usage (architecture compliance)
- [x] All writes go through Phase 17 actions (AddCommentAction / DeleteCommentAction / HideCommentAction)
- [x] No direct Comment::create / $comment->delete / status update in UI
- [x] Only visible, non-deleted comments render publicly

## Known deviations / notes
- No `phase-12-post-show-page-review.md` exists; the Phase 12 visual baseline could not be diffed. Post-show integration was verified against the live `data-testid="post-show-comments"` section and existing PostShow tests instead.
- `docs/design/reference/screenshots/` is empty, so pixel baselines were not compared; review relied on component reuse and the PlateRate reference + design-contract tokens.
- Out of Phase 18 scope (deferred): report comment button, nested replies, edit, likes, markdown, notifications, Filament resource.

## Verification
- `composer test`: see CI / command output in the RG-374 PR.
- `npm run build`: see command output in the RG-374 PR.
