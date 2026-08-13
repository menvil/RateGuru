# Community FK Safety

The database schema no longer encodes a hidden deletion policy: `ON DELETE
CASCADE` is reserved for true implementation-owned children, and every
community/history reference restricts instead. A low-level
`DELETE FROM users/posts/comments` is refused by the FK graph whenever
authored content, votes, reports or a reply subtree reference the target.

**The contract:**

> CASCADE is allowed only for implementation-owned children, never for
> authored / community / history data. Physical deletion of community
> models happens only inside explicit, sanctioned lifecycle services.

Today's sanctioned services: account anonymization
(`AnonymizeUserAccountAction` — never deletes the users row at all) and
`MediaLifecycleService` (the only `forceDelete()` in app code, guarded by a
test). Comment purge (PR-D) and post retention purge (PR-E) will join this
list and must clean their child graph explicitly before force-deleting.

## FK deletion-policy matrix

| child table.column | references | before | now | reason |
| --- | --- | --- | --- | --- |
| posts.user_id | users | CASCADE | **RESTRICT** | authored content survives its author |
| comments.post_id | posts | CASCADE | **RESTRICT** | thread survives until explicit post purge |
| comments.user_id | users | CASCADE | **RESTRICT** | authored content survives its author |
| comments.parent_id | comments | CASCADE | **RESTRICT** | reply subtree must never vanish implicitly (PR-D) |
| post_votes.post_id | posts | CASCADE | **RESTRICT** | vote history / stable aggregates |
| post_votes.user_id | users | CASCADE | **RESTRICT** | votes survive account deletion (PR-B policy) |
| comment_votes.comment_id | comments | CASCADE | **RESTRICT** | explicit comment purge decides (PR-D) |
| comment_votes.user_id | users | CASCADE | **RESTRICT** | votes survive account deletion |
| rating_votes.post_id | posts | CASCADE | **RESTRICT** | rating history |
| rating_votes.user_id | users | CASCADE | **RESTRICT** | votes survive account deletion |
| rating_votes.rating_group_id | rating_groups | CASCADE | **RESTRICT** | config deletion must not erase history |
| rating_votes.(option,group) | rating_options | CASCADE | **RESTRICT** | same |
| post_author_answers.post_id | posts | CASCADE | **RESTRICT** | authored content of the post |
| post_author_answers.rating_group_id | rating_groups | CASCADE | **RESTRICT** | config deletion must not erase answers |
| post_author_answers.(option,group) | rating_options | CASCADE | **RESTRICT** | same |
| post_saves.post_id | posts | CASCADE | **RESTRICT** | explicit lifecycle decides |
| post_saves.user_id | users | CASCADE | **RESTRICT** | PR-B deletes saves explicitly |
| follows.follower_id / author_id | users | CASCADE | **RESTRICT** | PR-B deletes follows explicitly |
| reports.reporter_id | users | CASCADE | **RESTRICT** | moderation evidence survives |
| reports.resolved_by | users | SET NULL | **RESTRICT** | keep attribution (actor is a tombstone, never deleted) |
| moderation_logs.moderator_id | users | SET NULL | **RESTRICT** | keep attribution |
| media_assets.owner_user_id | users | SET NULL | **RESTRICT** | keep attribution |
| post_tag.post_id / tag_id | posts / tags | CASCADE | CASCADE | pure pivot edge, implementation-owned |
| rating_options.rating_group_id | rating_groups | CASCADE | CASCADE | config owned by group; history protected one level down |
| media_variants.media_asset_id | media_assets | CASCADE | CASCADE | derived artifacts, implementation-owned |
| media_audit_issues.media_audit_run_id | media_audit_runs | CASCADE | CASCADE | diagnostics, implementation-owned |
| posts.category_id | categories | RESTRICT | RESTRICT | unchanged |
| posts.image_asset_id | media_assets | SET NULL | SET NULL | protective detach when media purge removes the asset |
| users.avatar_asset_id | media_assets | SET NULL | SET NULL | same |
| sessions.user_id | — | no FK | no FK | framework table, cleaned explicitly by PR-B |
| notifications.notifiable | — | morph, no FK | morph, no FK | cleaned explicitly by PR-B |

Composite FKs on `rating_votes`/`post_author_answers` additionally pin the
chosen option to its group; both restrict so configuration cannot be
deleted out from under recorded history — archive rating groups/options
instead of deleting them once votes exist.

## Why the SQLite/MariaDB branches changed too

Several older migrations drop and re-create FKs inside driver-specific
branches: the MariaDB down-paths of `add_indexes_to_posts_table`,
`add_unique_index_to_post_votes_table`, `add_indices_to_post_saves_table`
and `add_unique_user_reportable_index_to_reports_table`, plus the raw-SQL
SQLite `rebuild_comment_votes_type_constraint`. Those re-creations now use
the same RESTRICT policy so a rollback/re-run can never silently
reintroduce a cascade.

## STAGING RESET REQUIRED

The FK policy was changed **in the original create-migrations** (this
project is staging-only, no production data): editing an already-executed
migration does not alter an existing staging database. After merging,
recreate staging:

```shell
php artisan migrate:fresh --seed --force
```

**The automatic deploy will not do this for you.** The deploy pipeline runs
a plain `php artisan migrate --force` (infrastructure/scripts/deploy),
which re-runs nothing that already executed — until the manual reset above
is performed, an existing staging database keeps the old cascading FKs.

Verified clean on PostgreSQL, MariaDB and SQLite.

## Tests

`tests/Feature/Database/CommunityFkSafetyTest.php` — DB-level integration
suite (runs on all three engines): hard-deleting a user with posts /
comments / votes / reports / follows / saves is rejected and the graph
survives; hard-deleting a post with any community child is rejected;
hard-deleting a parent comment or a voted comment is rejected; deleting
rating config referenced by history is rejected; the deliberate cascades
(pivot, options-of-unused-group, media variants) and protective SET NULLs
still work; a user nothing references remains deletable by deliberate
maintenance SQL; and `forceDelete()` stays confined to sanctioned purge
services. PR-B's rich anonymization scenario is unchanged and keeps
passing against the new constraints.
