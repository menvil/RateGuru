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

| role \ status | Active | Limited | Banned | Shadowbanned | Deleted |
| ------------- | ------ | ------- | ------ | ------------ | ------- |
| Admin         | panel allowed | denied | denied | denied | denied |
| Moderator     | panel allowed | denied | denied | denied | denied |
| User          | denied | denied | denied | denied | denied |

(A Deleted admin/moderator cannot exist in practice — anonymization demotes
the role to User — but the panel gate fails closed regardless.)

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
log in, browse, manage account security, self-delete their account,
unfollow, and save/unsave posts (private state). Public profile/identity/
avatar mutation is frozen (PR-F). Their existing content stays visible.
Admin UI renders the state as a warning badge. First-class moderation
action: `LimitUserAction` (Active only). No timed expiry.

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
- The `EnsureAccountIsNotTombstoned` middleware (web group) force-terminates
  any surviving session of a tombstoned account on its next request —
  effective for file/redis session backends the DB cleanup cannot reach.
- Profile mutations (`UpdateUserIdentityAction`, `UpdateUserProfileAction`)
  re-read the user under `lockForUpdate()` and check `canUpdateProfile()` on
  the current row, so an in-flight stale edit racing anonymization can never
  write PII or an avatar back into a committed tombstone.
- A tombstone neither receives new notifications (`User::notify()` is a
  centralized no-op for tombstones) nor stays referenced in other users'
  inboxes: notifications whose payload snapshots the pre-deletion
  name/username (`author_id`/`actor_id`) are deleted during anonymization.

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
| Other users' notifications referencing this identity | delete |

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
| canUpdateProfile | yes | no | no | no | no | no |
| canAuthenticate | yes | yes | yes | yes | no | no |
| canAccessPrivilegedPanel | yes | no | no | no | no | no |

Notes:

- **canUpdateProfile** is Active-only since PR-F, enforced by the locked
  actor re-reads inside `UpdateUserIdentityAction`/`UpdateUserProfileAction`.
  The password-security flow and account self-deletion are deliberately NOT
  gated by it: a sanctioned living user can always secure or delete their
  account.
- **canAuthenticate** is enforced at the auth boundary
  (`AuthenticateUserAction`): living sanctions log in normally and never
  force logout or session revocation; a Deleted tombstone is refused with
  the generic failure message. The stale-session terminal-account
  middleware (`EnsureAccountIsNotTombstoned`) is unchanged. The sanctioned
  authenticated user sees a private, translated restriction notice on
  normal pages — neutral for Shadowbanned, never exposing the internal
  moderation reason.
- **canFollow** enforcement is the one deliberate behavior change of PR-A:
  previously only the *author's* status was validated, so a banned/limited/
  shadowbanned user could still start following people. "New participation
  restricted" now includes creating follows. Unfollowing (removing a follow)
  remains allowed for every status.
- Replies reuse `canComment` — same permission, no duplicate rule.

## Moderation transitions

Implemented by `LimitUserAction`, `BanUserAction`, `ShadowbanUserAction`
and `RestoreUserAccessAction` — all through one shared executor
(`ExecutesUserStatusTransition`) that locks BOTH the acting admin and the
target in ascending primary-key order, re-authorizes on the fresh locked
rows (`Gate::forUser`), validates the transition matrix against the locked
target status, and writes exactly one ModerationLog with the authoritative
`from_status`/`to_status`. Only an **Active Admin** may sanction: a stale
request from a just-sanctioned admin fails even though its caller object
still says Active. Invalid or same-state transitions throw and log nothing.

```text
Active                           --limit-->        Limited
Active | Limited | Shadowbanned  --ban-->          Banned
Active | Limited                 --shadowban-->    Shadowbanned
Limited | Banned | Shadowbanned  --restore access--> Active
any living state                 --self-delete-->  Deleted   (terminal)
```

Banned deliberately cannot move sideways to Limited/Shadowbanned: to
downgrade a ban, restore to Active first, then apply the new state
explicitly. Deleted is terminal in both directions — no sanction may
target a tombstone and nothing transitions out of it.

`UnbanUserAction` is gone; `ModerationActionType::UnbanUser` remains only
so historical log rows keep hydrating — new restores log
`RestoreUserAccess`. `MarkUserTrustedAction` is not a lifecycle transition
(it mutates `trust_level`, requiring an Active regular-user target) but
uses the same actor+target pair locking. Sanctions never change role or
trust_level: a sanctioned Moderator keeps role=Moderator, merely losing
panel access until restored; only account anonymization demotes role.

Lifecycle mutation happens **only** through these boundaries: the generic
Filament user edit form shows status read-only (a raw select would bypass
locking, the matrix, reason capture and the log), and an architecture
guard (`UserLifecycleWriteBoundaryTest`) keeps `'status' => UserStatus::…`
writes inside anonymization, the shared executor and admin bootstrap.

## Lock ordering and stale actors

The uniform deterministic order for every lifecycle-dependent write:

```text
Actor User -> other User rows (ascending id) -> Post -> Comment /
RatingGroup / child rows -> edges (votes, saves, follows, reports)
```

Every participation action re-reads and locks the actor User inside its
transaction and re-checks the current capability before mutating
(`LocksActorForWrite`): post/comment/rating votes, comment creation
(Actor -> Post -> parent Comment), post creation (trust-level publishing
is decided on the fresh row), reports, author post delete/restore and
author comment delete (authorized via `Gate::forUser` on the locked
actor). Two-user operations (follows, sanctions) lock both rows in
ascending primary-key order and only then identify follower/target —
never "follower first", which would deadlock against the opposite pair.
Cheap pre-checks on the caller's instance remain but are never
authoritative.

Shadowbanned is **not** viewer-dependent shadow visibility: it is a
moderation-facing label with the same capability restrictions as
Limited/Banned, and existing content remains publicly visible.

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
- `tests/Feature/Actions/{Ban,RestoreUserAccess,Shadowban}UserActionTest.php`,
  `tests/Feature/Admin/FilamentAdminAccessTest.php` — moderation and panel
  coverage.
- `tests/Feature/Domain/UserModerationTransitionMatrixTest.php` — the full
  allowed/forbidden transition matrix, stale-admin race, authoritative
  from_status logging, role/trust preservation.
- `tests/Feature/Domain/UserStaleActorProtectionTest.php` — deterministic
  stale-actor regressions for every participation write.
- `tests/Feature/Domain/UserSanctionSemanticsTest.php` — non-destructive
  sanction invariant, public content untouched, private save/unsave,
  sanctioned-moderator panel round trip, sanctioned self-delete,
  tombstone user-report refusal.
- `tests/Feature/Livewire/AccountRestrictionNoticeTest.php` — private
  restriction notice, session survival mid-ban, reason privacy.
- `tests/Feature/Architecture/UserLifecycleWriteBoundaryTest.php` —
  sanctioned lifecycle write boundaries.
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
