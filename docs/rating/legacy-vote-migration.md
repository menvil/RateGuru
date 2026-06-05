# Legacy Rating Vote Migration

Phase 44 provides an explicit, idempotent command for migrating legacy votes into `rating_votes`.

## Commands

Preview the operation first:

```bash
php artisan rateguru:rating:migrate-legacy-votes --dry-run
```

Run the migration:

```bash
php artisan rateguru:rating:migrate-legacy-votes
```

The dry run executes the same configuration and vote logic inside a transaction, reports planned counts, and rolls the transaction back. It writes no groups, options, or votes.

## Preparation

Before a production run:

1. Back up the database.
2. Deploy and run the normal Laravel migrations.
3. Run the command with `--dry-run`.
4. Review migrated, existing, and unmapped counts.
5. Run the command without `--dry-run`.
6. Run it a second time if an idempotency check is required; no duplicate votes should be created.

## Mapping

Legacy source mapping:

| Legacy value | Generic option |
| --- | --- |
| `homemade` | `source_a` |
| `restaurant` | `source_b` |

Legacy category mapping:

| Legacy value | Generic option |
| --- | --- |
| `italian` | `category_a` |
| `asian` | `category_b` |
| `american` | `category_c` |
| `mexican` | `category_d` |
| `other` | `category_other` |

Unknown or unsupported stored values are counted as unmapped and are not inserted.

## Conflict behavior

The migration key is the same unique identity used by the application: user, post, and rating group.

If a generic vote already exists for that identity, the command keeps the generic vote and reports the legacy row as existing. It does not replace a newer generic choice with historical data.

The command preserves `user_id`, `post_id`, and the legacy timestamps for newly migrated rows.

## Configuration behavior

The command finds or creates the required Source and Category groups and mapping options. It uses create-if-missing behavior and does not overwrite admin-managed labels, active states, ranges, or ordering on existing records.

## Legacy table retention

The command reads `origin_votes` and `cuisine_votes`. It does not update, truncate, rename, or delete either legacy table.

Removing legacy tables requires a separate production migration plan after migration counts, backups, and rollback requirements have been reviewed.

