# Post Lifecycle

The contract: **author deletion is a recoverable, owner-only lifecycle with
a retention window; moderation hide is a separate, reversible state that
never starts a purge clock.** PR-E (this document) builds on the comment
lifecycle (PR-D, [comment-lifecycle.md](comment-lifecycle.md)), the
account tombstones (PR-B, [user-lifecycle.md](user-lifecycle.md)) and the
RESTRICT FK graph (PR-C, [community-fk-safety.md](community-fk-safety.md)).

```
LIVE POST ── author delete ──▶ SOFT-DELETED (restorable) ── retention expires ──▶ HARD PURGE
                                     │                                              │
                                     └── owner restore (exact prior state) ◀────────┘ never

Published ── moderator Hide ──▶ Hidden ── moderator Restore ──▶ Published
```

## Author deletion vs moderation

| | Author delete | Moderation hide |
| --- | --- | --- |
| Who | post owner only (`PostPolicy::deleteFromFeed`) | moderator/admin |
| Storage | SoftDeletes + `status = Deleted` + `deleted_from_status` capture | `status = Hidden`, `deleted_at` stays null |
| Reversible | by the owner, strictly before the retention cutoff | yes, via moderation `RestorePostAction` |
| Purge clock | starts at `deleted_at` | never |

There is deliberately **no generic admin delete**: `DeletePostInAdminAction`
and `PostPolicy::delete` were removed with PR-E. Moderation acts through
Approve/Reject/Hide/Restore only, and none of those enter author retention.
A **Hidden post cannot be author-deleted** — the author must not be able to
start a purge clock for moderation-hidden evidence.

Account deletion is unchanged (PR-B): deleting a user tombstones the
author identity, posts remain. Post retention applies only when the post
itself is explicitly deleted — and a tombstoned owner can neither delete
nor restore content (`canManageContent()` gates both).

## Deletion, capture and restore

`DeletePostAction` re-reads the post `withTrashed()->lockForUpdate()` and
decides on the authoritative row: owner-only, Hidden rejected, malformed
soft-deleted rows refused, re-delete is a no-op that never refreshes
`deleted_at`. Allowed source statuses are `Post::AUTHOR_DELETABLE_STATUSES`
(Draft, Pending, Published, Rejected); the source is captured in
`posts.deleted_from_status` and `status` becomes `Deleted`.

`RestoreDeletedPostAction` (author self-service — deliberately not the
moderation restore) restores the **exact prior status**, clears
`deleted_from_status`, preserves `published_at`, and is not publication:
no follower jobs, no approval notification, no moderation log. Child data
was never touched during retention, so nothing is mass-restored — a
comment individually deleted before the post deletion stays deleted after
the post restore.

**Retention boundary**: `POST_AUTHOR_DELETE_RETENTION_DAYS` (config
`posts.author_delete_retention_days`, default 30, `>= 0`). The value is
resolved exclusively through the strict `PostRetention::days()` resolver,
which **fails closed**: a negative or non-numeric value throws instead of
collapsing to 0 — a misconfiguration must stop the purge and every
retention computation, never turn into an immediately-expired window
feeding the daily purge. Restore is allowed strictly while
`now() < deleted_at + retention`; at the exact cutoff it is expired —
even if no purge ran yet. Retention 0 still
soft-deletes first (never a synchronous hard delete); the window is just
immediately expired. The UI surface is `/account/posts/deleted`
(Recently Deleted): owner-only, deadline shown, no public links, no
permanent-delete button.

## Public state during retention

The deleted post is publicly gone everywhere (detail 404, feed, search,
tag/category, public profile, saved posts, following feed, OG) — all
public queries filter `Published` and the SoftDeletes scope excludes the
row; none use `withTrashed()`. There is no public deleted-post tombstone
page. Rows exist only as recoverable state. The Filament posts table shows
author-deleted rows `withTrashed` as read-only audit history labeled
"Deleted by author".

## Write protection and lock order

A lifecycle helper on a stale model is never enough. Every action writing
state against a post re-reads the authoritative row
`withTrashed()->lockForUpdate()` inside its transaction and revalidates
the trashed-aware helper before writing:

- `VotePostAction` / `VoteRatingOptionAction` — `canReceiveVotes()` /
  `canReceiveRatingVotes()`
- `AddCommentAction` — `canReceiveComments()` (plus the PR-D parent
  revalidation)
- `ReportContentAction` — `canReceiveReports()`; deleted **and hidden**
  posts refuse new reports (existing reports remain). Comment reports
  lock the **parent post row first**, then the comment: this serializes
  them against the retention purge (an open report either lands before
  the purge's hold check or finds the graph gone) and also refuses
  reports on still-Visible comments of a deleted/hidden post
- save/unsave/toggle — `canBeSaved()`; unsave is also refused on deleted
  posts so save rows stay intact for the final purge

**Lock order, uniform for every post writer including the purge:**
Post row → parent Comment (or the post's comment rows) → child rows.
Ratings extend it: Post → RatingGroup → RatingOption.

Delete vs moderation races are deterministic through the same row lock:
hide-first blocks deletion (no clock started), delete-first makes a later
hide reject, and moderation restore can never resurrect an author-deleted
post.

## Final hard purge

`PostRetentionPurgeService` is the **only** sanctioned boundary allowed to
force-delete a post (enforced by the CommunityFkSafetyTest forceDelete
allowlist). Per post, in one transaction, after a locked re-read:

- **Eligibility (fail closed)**: soft-deleted + `status = Deleted` +
  `deleted_from_status` in the author-deletable set + `deleted_at <=`
  cutoff. Anything else — live, Hidden, half-shaped legacy rows — is
  `invalid_state`.
- **Moderation hold**: `needs_review`, an open report against the post, or
  an open report against any of its comments blocks the purge
  (`moderation_hold`). Resolved/ignored reports never block.
- **Deletion order (PR-C FK-safe, leaves first)**: lock the post's comment
  rows → comment votes → comment-targeted reports → replies → root
  comments → post votes → rating votes → saves → author answers →
  `post_tag` pivot (explicit despite the DB cascade) → post-targeted
  reports → the post row → media release. Moderation logs are kept as
  audit history (admin rendering of missing targets is null-safe).
- **Media**: DB-only `MediaLifecycleService::releaseUnreferenced()` in the
  same transaction — a shared asset stays active, a final-reference asset
  soft-deletes. Physical files are never touched here; `media:purge`
  removes them after `MEDIA_PURGE_GRACE_DAYS`
  ([media.md](media.md)).
- Any failing step rolls back the entire graph; concurrent purges are
  safe (the loser's locked re-read sees `already_gone`).

`posts:purge` runs the service (`--post`, `--older-than`, `--dry-run`,
`--chunk`, per-outcome summary) and is scheduled **daily** with
`withoutOverlapping()` in `routes/console.php`. The candidate query is a
pre-filter only; the service re-checks everything under lock.

## Comments across the post lifecycle

During retention every comment row and state is preserved exactly
(PR-D tombstones included); restore brings the discussion back unchanged.
Only the final post purge removes the comment graph — physically,
bottom-up, inside the purge service. Normal `DeleteCommentAction` remains
the product lifecycle and is never reused for physical cleanup.

## Moderation removal (PR-G)

Ordinary Hidden never purges and restores forever. An Active Admin may
finalize a hidden post (`moderation_removed_at`,
[moderation-content-lifecycle.md](moderation-content-lifecycle.md)):
restore then rejects, and the disabled-by-default moderation retention may
eventually purge it through the same `PostGraphDeletionService` the author
retention uses — shared physical deletion, fully separate eligibility and
clocks (`deleted_at` + `deleted_from_status` vs `moderation_removed_at`).
The author Recently Deleted surface never lists moderation-hidden or
finalized posts.

## Deferred

Legal-hold tooling, permanent-delete-now UI, timed sanctions, appeals.
