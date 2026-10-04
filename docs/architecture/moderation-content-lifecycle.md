# Moderation Content Lifecycle

The contract closing the lifecycle series (PR-G):

**HIDE != FINALIZE MODERATION REMOVAL != PHYSICAL PURGE**, and
**AUTHOR DELETE != MODERATION REMOVAL**. Builds on
[post-lifecycle.md](post-lifecycle.md),
[comment-lifecycle.md](comment-lifecycle.md),
[user-lifecycle.md](user-lifecycle.md) and
[community-fk-safety.md](community-fk-safety.md).

```text
VISIBLE/PUBLISHED --hide--> HIDDEN (reversible, forever)
                              │ Active Admin: Finalize removal (+ reason)
                              ▼
                    HIDDEN + moderation_removed_at   (irreversible)
                              │ retention policy (disabled by default)
                              ▼
                        PHYSICAL PURGE (graph/leaf boundaries)
```

## Three-stage model

- **Hide** stays exactly the PR-D/PR-E reversible moderation state:
  `status = Hidden`, `deleted_at` null, `moderation_removed_at` null.
  It never soft-deletes, never starts any purge clock, never touches
  reports/votes/saves/media/logs. Restore works indefinitely. A top-level
  safety regression proves that years of scheduled cleanup never select
  `Hidden + moderation_removed_at IS NULL`.
- **Finalize removal** (`FinalizePostRemovalAction` /
  `FinalizeCommentRemovalAction`) is the explicit declaration that hidden
  content will never return publicly. Active-**Admin**-only (moderators
  hide/restore but never finalize; PR-F actor+target locking applies, so a
  stale sanctioned admin fails). Requires a non-empty internal reason —
  stored only in the ModerationLog, never on the content row and never
  exposed publicly. Sets only `moderation_removed_at` (posts also
  normalize `needs_review` — finalization is a completed moderation
  decision) and writes exactly one log with the honest derived transition
  `hidden -> removal_finalized`. Nothing is physically deleted.
  Irreversible in the normal lifecycle: both Restore actions reject a
  finalized row, `DeleteCommentAction` refuses to pull a finalized comment
  into ordinary author cleanup, and there is no "unfinalize".
  A comment may be finalized live **or** already author-soft-deleted
  (PR-D permits Hide -> author Delete; the row remains evidence — hence
  `withTrashed`).
- **Physical purge** is command/scheduler territory only — no Filament
  purge button exists. `ModerationContentPurgeService` owns eligibility;
  the purge clock is `moderation_removed_at` alone.

## Retention: disabled by default

`MODERATION_CONTENT_RETENTION_DAYS` (config
`content_lifecycle.moderation.content_retention_days`) resolves through
the strict `ModerationContentRetention::days()`:

- empty/null → **disabled**: finalized content is retained indefinitely;
  the scheduled `moderation:purge-content` run is a cheap safe no-op.
- integer `>= 0` (or digit string) → enabled; explicit `0` is valid and
  still asynchronous (finalize never hard-deletes synchronously).
- anything else (negative, decimal, garbage) → exception, fail closed.
  Disabled never degrades to zero days.

Manual override: `--older-than=N` may dry-run freely while disabled;
destructive execution additionally requires `--force`, which only
acknowledges the override — it can never bypass finalization state,
cutoffs, evidence holds, structural anchors, parent-post holds or FK
safety.

## Purge eligibility

Finalized **post** (`purgePost`): `Hidden`, not trashed,
`moderation_removed_at <= cutoff`, `needs_review` false, no open report
against the post or any comment in its graph. Purge delegates to the
shared `PostGraphDeletionService` — the same bottom-up FK-safe graph
deletion the PR-E author purge uses; author and moderation purges share
**only** this physical boundary, their eligibility policies (and clocks:
`deleted_at` vs `moderation_removed_at`) stay fully separate.

Finalized **comment** (`purgeComment`): `Hidden` + finalized, parent post
live (not trashed, not Hidden — otherwise post-level cleanup owns the
graph → `ParentPostHold`), cutoff reached, no child row (trashed included
→ `StructuralAnchor`; surviving replies keep rendering under
`[comment removed by moderator]`), no open report. Leaf deletion goes
through `CommentPhysicalDeletionService`.

Evidence semantics: open reports block, resolved/ignored never hold
forever and are swept with the purged target. **ModerationLog rows are
retained indefinitely** — hide/restore/finalize/sanction history all
survive the physical purge; admin rendering is null-safe for missing
targets. Media is released DB-only (`releaseUnreferenced`) after the post
row disappears: shared assets stay active, final references soft-delete,
physical files wait for the normal media grace
([media.md](media.md)).

## Author-deleted comment retention

`COMMENT_AUTHOR_DELETE_RETENTION_DAYS` (default 30, strict
`CommentRetention::authorDeleteDays()`) governs purely author-deleted
leaf comments via `CommentRetentionPurgeService` and the daily
`comments:purge-deleted` (enabled by default). Every hold beats the
clock:

- still `Hidden` when author-deleted → active moderation evidence,
  ordinary cleanup refuses (`InvalidState`); Admin may finalize, moving
  the row under moderation retention;
- finalized → moderation retention owns it;
- parent post author-retained → `PostRetentionHold` (PR-E restore must
  recover the exact untouched discussion graph — pinned);
- parent post Hidden → `PostModerationHold`;
- any child row (trashed included) → `StructuralAnchor` (never rewrite
  `parent_id`, never synthesize rows);
- open report → `OpenReportHold`.

## Lock order and concurrency

Cleanup follows the established order: **parent Post → Comment → child
rows** (candidate queries are prefilters only; everything re-checks under
lock). Author and moderation post purges lock the same post row first and
delegate to the same graph deleter — concurrent attempts leave the loser
with `AlreadyGone`, never a double media release. Comment reports lock
Reporter → Post → Comment (PR-E/PR-F), so a report landing first becomes
an open-report hold and a purge landing first makes the report
revalidation see the target gone. All physical cleanup is atomic: any
failure rolls back the entire graph/leaf with media untouched.

## Physical deletion boundaries

After PR-G, `forceDelete` exists in exactly three places:

- `PostGraphDeletionService` — the whole post graph;
- `CommentPhysicalDeletionService` — a standalone leaf comment (votes +
  processed reports + row);
- `MediaLifecycleService` — media rows after the media grace.

Enforced by the FK-safety allowlist test. PR-C RESTRICT FKs are
unchanged.
