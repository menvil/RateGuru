# Comment Lifecycle

The contract: **removing one comment must never destroy or silently hide
other users' surviving replies.** Two distinct removal operations exist and
stay distinguishable in storage, behavior, UI and audit semantics:

| | Author delete | Moderation hide |
| --- | --- | --- |
| Who | comment owner only (`CommentPolicy::delete`) | moderator/admin |
| Storage | SoftDeletes (`deleted_at`) | `status = Hidden`, `deleted_at` stays null |
| Reversible | never | yes, via `RestoreCommentAction` |
| Moderation log | none — not a moderation act | HideComment / RestoreComment logs |
| Body retained | internally, until a future retention policy | in DB for moderation/audit |

There is deliberately **no admin delete**: moderation acts through
hide/restore, and the Filament comments table exposes no delete action.
Permanent purge belongs to a later retention policy — no `forceDelete()`,
purge command or scheduler exists for comments (PR-C FKs refuse physical
deletion of referenced comments anyway).

## Structural tombstones

A removed comment is publicly absent — unless it anchors surviving public
replies, in which case the root renders as a neutral **structural
tombstone** that preserves the thread:

- author-deleted parent → `[comment deleted]` (`ui.comments.tombstone_deleted`)
- moderator-hidden parent → `[comment removed by moderator]` (`ui.comments.tombstone_hidden`)

A tombstone exposes nothing: no body, author identity, avatar, profile
link, votes, and no report/reply/delete/hide/vote actions — enforced both
in `CommentItem` (flags forced off) and in the backend (`canReceiveVotes()`
/ `canReceiveReports()` are trashed-aware; `AddCommentAction`,
`VoteCommentAction` and `ReportContentAction` revalidate the comment under
lock; `ReportModal` resolves only Visible comments; `CommentPolicy` refuses
hide/restore on trashed rows).

Replies are one level deep (unchanged product rule), so replies never need
their own tombstones: a removed leaf simply disappears.

## Queries and counts

`CommentListQuery` distinguishes two numbers:

- **`countVisible`** — the public comment count (`posts.comments_count`):
  visible, non-author-deleted comments. A structural tombstone is *not* a
  comment here: deleted parent + 2 live replies ⇒ count 2.
- **`countRenderableTopLevel`** — what the thread view paginates: visible
  roots **plus** structural tombstone roots with at least one surviving
  public reply. Deleted parent + 1 live reply ⇒ 1 renderable root.

The root query uses `withTrashed()` for exactly this one purpose; replies
eager-load through the relation's normal SoftDeletes scope plus a Visible
filter, so hidden/deleted replies never load. Sorting is unchanged:
tombstone roots keep their stored counters and thread position (`top`/`hot`
still order by the root's own vote counters — no reply-aggregate ranking).

## Race matrix (all row-locked)

- **Hide, then author delete** — allowed; author deletion is the final
  public state and `RestoreCommentAction` can never resurrect it (policy
  and in-transaction re-read both refuse trashed rows).
- **Author delete, then hide** — silent no-op: the locked re-read inside
  `HideCommentAction` sees no live target; no moderation state or log.
- **Delete racing reply creation** — `AddCommentAction` re-reads the parent
  under `lockForUpdate()` and revalidates same-post/top-level/Visible/not
  deleted immediately before insert; the losing side gets the existing
  reply-target-unavailable error.
- **Hide/delete racing a vote or report** — `VoteCommentAction` and
  `ReportContentAction` re-read the comment under `lockForUpdate()` and
  revalidate `canReceiveVotes()` / `canReceiveReports()` immediately before
  the write, so a stale Visible instance cannot land a vote or report on a
  freshly tombstoned row. Posts are deliberately not report-gated:
  soft-deleted posts stay reportable.
- **Double delete** — idempotent: no error, no double counter decrement.
- **Double hide** — pre-existing idempotency retained: single log.

## Account deletion is NOT comment deletion

PR-B regression, pinned by tests: a Deleted User's live comment keeps its
body and renders under the "Deleted user" author label. Only the author
identity is tombstoned — never the comment. Conversely a deleted comment
by a living user hides content but not identity elsewhere.

## Admin table

Author-deleted rows stay reviewable (withTrashed) as audit history, labeled
via a derived state column (Visible / Hidden by moderation / Deleted by
author) with an "Author deleted" filter — and expose no actions at all.

## Deferred

Moderation retention → PR-G. Post retention landed in PR-E
([post-lifecycle.md](post-lifecycle.md)): comments are preserved exactly
while their post sits in retention and are physically removed, bottom-up,
only by the final post purge (`PostRetentionPurgeService`).
