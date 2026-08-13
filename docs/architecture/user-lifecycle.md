# User Lifecycle

This document is the contract for what each `UserStatus` means in the
product. The single source of truth in code is the capability API on
`App\Enums\UserStatus`; `App\Models\User` exposes convenience methods that
delegate to it and fail closed when `status` is `null`. No other place may
define lifecycle meaning.

## Role vs lifecycle

`UserRole` and `UserStatus` are independent dimensions:

- **Status** answers "is this account in good standing" (lifecycle).
- **Role** answers "what may this account administer" (authorization).

Privileged-panel access requires **both**: a lifecycle-eligible status
(`UserStatus::canAccessPrivilegedPanel()`, Active only) **and** an allowed
role (Admin or Moderator) — see `User::canAccessPanel()`.

| role \ status | Active | Limited | Banned | Shadowbanned |
| ------------- | ------ | ------- | ------ | ------------ |
| Admin         | panel allowed | denied | denied | denied |
| Moderator     | panel allowed | denied | denied | denied |
| User          | denied | denied | denied | denied |

Lifecycle status is **not** RBAC: no status ever grants a permission a role
withholds, and no role rescues a non-active status (fail closed).

## Status meanings (as audited on develop)

### Active

Full participation: create posts, comment/reply, vote (posts, comments,
rating options), report, follow, be followed, manage own content in the
feed, edit profile, and — role permitting — access the admin panel.

### Limited

A restricted / suspension-like state. Limited users cannot create posts,
comment, vote, report, follow, or be followed, are excluded from the
matched-users search, and are ineligible for the admin panel. They can still
log in, browse, edit their profile and save posts. Their existing content
stays visible. Admin UI renders the state as a warning badge.

### Banned

Non-destructive participation ban. Same enforced restrictions as Limited;
additionally the moderation UI treats Banned as terminal-ish (a banned user
can only be unbanned; the ban action is hidden for already-banned users).

The ban is **strictly non-destructive**:

> Ban/shadowban modify participation state. They do not delete identity,
> posts, comments, follows, avatar, or media.

Identity, posts, comments, avatar asset, media rows and follows (both
directions) are all retained; only *new* participation is restricted. This
invariant is pinned by `tests/Feature/Actions/ModerationLifecycleIntegrityTest.php`
and is the foundation later deletion work (PR-C/PR-F) builds on.

### Shadowbanned

**Audited semantics:** today Shadowbanned enforces exactly the same
capability profile as Banned — the user cannot create, comment, vote,
report, follow or be followed, and existing content remains visible to
everyone. There is **no viewer-dependent visibility** anywhere in the
product: a shadowbanned user's posts are not hidden from others, and the
author is not shown a different view. The differences from Banned are
moderation-facing only: a separate badge, the `SuspiciousUsersWidget`
listing, and different transition availability (a shadowbanned user can
still be banned).

If real contextual visibility ("author sees own content, others do not") is
introduced later, it must be modelled where viewer and target exist (Gate
policies / query objects), not as a boolean on the enum.

### `null` status

Fails closed. The DB column defaults to `'active'` and is non-nullable, so
`null` only occurs on unhydrated/legacy instances — every `User::can*()`
convenience method returns `false` for it.

### Deleted (irreversible tombstone)

**Account deletion invariant: delete identity, preserve community
contribution.** Normal account deletion never physically deletes the users
row. `AnonymizeUserAccountAction` turns it into an irreversible tombstone:

- `status = Deleted`, `anonymized_at = now()` — set exactly once, never
  reversible; this is deliberately **not** a Laravel SoftDelete.
- PII is replaced: `name = "Deleted user"`, unique non-identifying
  `username` (`deleted_{id}_{random}`) and non-routable `email`
  (`deleted-{id}-{uuid}@deleted.invalid`), everything else personal is
  nulled; credentials are scrambled (random password, cleared
  remember token / verification / reset tokens / sessions).
- A deleted former admin/moderator is demoted to an ordinary `User` role —
  no privileged tombstones.
- Every capability fails closed, including `canUpdateProfile` and
  `canAuthenticate`.
- Public UI renders the author of surviving posts/comments as
  **Deleted user** — no @handle, no profile link, no avatar, and the
  internal tombstone username/email never appear anywhere public. The
  presentation boundary is centralized in `resolved_display_name` and
  `public_username` on `User`; views never test the status directly.
- The profile route 404s (`ProfilePage` uses the `withoutTombstoned`
  scope); the pre-deletion username 404s naturally because no row carries
  it anymore.
- Admin UI shows tombstones read-only: `UserPolicy` refuses
  manage/ban/unban/shadowban/markTrusted for tombstone targets, and the
  moderation actions re-check under lock so a stale instance cannot slip a
  tombstone back into the moderation lifecycle.
- The `NoPhysicalUserDeletionRule` PHPStan rule (non-ignorable) bans
  `$user->delete()` / `forceDelete()` / `User::destroy()` in all app code.

#### Data policy on account deletion

| Data | On account delete |
| --- | --- |
| User PII (name, email, username, bio, website, display name) | anonymize |
| Credentials, sessions, reset tokens, remember token | delete / revoke |
| Followers / following (both directions) | delete |
| Avatar | detach + release via media lifecycle (grace-period purge) |
| Posts | retain |
| Comments / replies | retain (author label becomes "Deleted user") |
| Votes (post / comment / rating) | retain |
| Reports | retain (moderation evidence) |
| Post media | retain |
| Post saves | delete |
| Received notifications | delete |

Note: notifications *received by other users* may still embed the deleted
user's pre-deletion display name inside their JSON payloads; rewriting other
users' inboxes is deliberately out of scope here.

## Capability matrix

| capability | Active | Limited | Banned | Shadowbanned | Deleted | `null` |
| --- | --- | --- | --- | --- | --- | --- |
| canCreateContent | yes | no | no | no | no | no |
| canComment (incl. replies) | yes | no | no | no | no | no |
| canVote (post/comment/rating) | yes | no | no | no | no | no |
| canReport | yes | no | no | no | no | no |
| canFollow (as follower) | yes | no | no | no | no | no |
| canBeFollowed (as author) | yes | no | no | no | no | no |
| canManageContent (feed delete) | yes | no | no | no | no | no |
| canUpdateProfile | yes | yes | yes | yes | no | no |
| canAuthenticate | yes | yes | yes | yes | no | no |
| canAccessPrivilegedPanel | yes | no | no | no | no | no |

Notes:

- **canUpdateProfile / canAuthenticate** are *declared but not enforced*:
  the audit found no lifecycle gating on profile mutation or login anywhere,
  so these methods pin today's behavior (allowed for every defined status)
  and give a future PR (PR-F) a single flip point. Do not add enforcement
  call sites without a product decision.
- **canFollow** enforcement is the one deliberate behavior change of PR-A:
  previously only the *author's* status was validated, so a banned/limited/
  shadowbanned user could still start following people. "New participation
  restricted" now includes creating follows. Unfollowing (removing a follow)
  remains allowed for every status.
- Replies reuse `canComment` — same permission, no duplicate rule.

## Moderation transitions

Implemented by `BanUserAction`, `UnbanUserAction`, `ShadowbanUserAction`
(admin-only via `UserPolicy`, self/admin-target protected, wrapped in
`DB::transaction` with `lockForUpdate()` re-reads, moderation-logged with
accurate `from_status`/`to_status`):

```
Active | Limited | Shadowbanned  --ban-->      Banned
Active | Limited                 --shadowban-> Shadowbanned
Banned | Shadowbanned            --unban-->    Active
any non-deleted state            --self-delete-> Deleted   (terminal)
```

Deleted is terminal in both directions: no moderation action may target a
tombstone (unban requires Banned/Shadowbanned; ban/shadowban explicitly
reject Deleted under lock; `UserPolicy` refuses tombstone targets), and
nothing transitions out of it.

State guards in these actions intentionally use direct enum comparisons
("is the target already banned?") — they reason about the state itself, not
about what the state may do, so the capability API does not apply. The
Filament UI additionally hides the shadowban action for banned users; the
action layer's own guard only rejects re-shadowbanning.

`MarkUserTrustedAction` is not a lifecycle transition (it mutates
`trust_level`, requiring Active status as a precondition).

## Where lifecycle checks live

- Enforcement call sites go through the capability API:
  `PostPolicy::create/report/vote/deleteFromFeed`, `AddCommentAction`,
  `VotePostAction`, `VoteCommentAction`, `VoteRatingOptionAction`,
  `ReportContentAction`, `FollowAuthorAction`, `CreatePostAction`
  (auto-publish trust gate), `User::canAccessPanel()`.
- Direct `UserStatus` usage remains legitimate for: transition guards in
  moderation actions (above), audience query filters
  (`MatchedUsersQuery`, `SendContactMessageAction`, Filament table
  filters/widgets, demo seeders), presentation badges, and default values
  (factories, `CreateAdminUserCommand`).
- The PHPStan rule `NoDirectControllerPermissionCheckRule` blocks
  presentation classes (Filament/Livewire/controllers/requests) from calling
  capability methods directly — presentation must authorize through Gate.

## Tests

- `tests/Unit/Enums/UserStatusTest.php` — capability matrix (the product
  contract) + enum-case-count guard.
- `tests/Feature/Models/UserLifecycleTest.php` — User delegation to the
  enum contract, null fail-closed, role×status panel matrix.
- `tests/Feature/Domain/UserLifecycleEnforcementTest.php` — application
  integration: each participation action rejects restricted states with its
  domain exception and works for Active.
- `tests/Feature/Actions/ModerationLifecycleIntegrityTest.php` — transition
  audit logging (including the stale-instance concurrency regression) and
  the non-destructive content-preservation invariant.
- `tests/Feature/Actions/{Ban,Unban,Shadowban}UserActionTest.php`,
  `tests/Feature/Admin/FilamentAdminAccessTest.php` — pre-existing
  moderation and panel coverage.
- `tests/Feature/Actions/AnonymizeUserAccountActionTest.php` — tombstone
  happy path (rich graph), rollback, idempotency, uniqueness at scale,
  Banned-vs-Deleted distinction, media retention/release.
- `tests/Feature/Livewire/DeletedUserPresentationTest.php` — public
  "Deleted user" rendering, profile-route 404, API resource redaction.
- `tests/Feature/Filament/UserResourceTombstoneTest.php` — read-only
  tombstones in the admin panel.

## Deferred to later PRs

- FK cascade hardening → PR-C
- comment tombstones (`[comment deleted]`) → PR-D
- post retention / hard purge → PR-E
- moderation lifecycle (auth enforcement, suspension UI) → PR-F
- moderation retention → PR-G
