# RateGuru backup operations

## Local backup and restore test (Phase 4 slice 7.1, completed)

`backup` and `restore-test` accept the same selector contract as
`health-check`, `status`, `cleanup`, `deploy` and `rollback`:

```bash
sudo /home/www/rateguru/bin/backup --environment staging
sudo /home/www/rateguru/bin/backup --target staging-main

sudo /home/www/rateguru/bin/restore-test --environment staging
sudo /home/www/rateguru/bin/restore-test --target staging-main
```

`--target` and `--environment` are mutually exclusive, exactly one is
required, and `--help` documents both forms. Both scripts require root
unconditionally, as the first action of every invocation — before argument
parsing even runs — matching `deploy`/`rollback`'s exact contract.

**Both selectors resolve to the identical existing namespace, root and
lock**, so no existing backup directory moves:

```text
backup namespace = staging
backup root      = /home/www/rateguru/backups/staging
lock (backup)     = /home/www/rateguru/run/backup-staging.lock
lock (restore-test) = /home/www/rateguru/run/restore-test-staging.lock
```

`backup --environment staging` and `backup --target staging-main` cannot run
concurrently against that namespace — the lock filename is built from the
resolved backup namespace, never from the selector label. The same holds for
`restore-test`.

In target mode, `require_active_target` runs immediately after root
authorization — before the backup root, lock, database binary, `rclone`, or
any filesystem work — so a planned target (`tits-guru`) is rejected before
anything is touched.

### Manifest: schema 2, backward compatible with schema 1

Every backup produced from this slice onward carries a
`manifest_schema_version: 2` manifest naming its `selector`, `target` (`null`
for a legacy-mode backup), `environment` and `backup_namespace`, alongside
the pre-existing `project`, `database`, `release`, `postgres_version` and
`php_version` fields. `restore-test` validates whichever schema it finds on
the backup it selects:

- always required: `project == rateguru`, `environment` matching the
  resolved selector, `database` matching the resolved database;
- additionally required for schema 2 only: `backup_namespace` matching the
  resolved namespace, and — under `--target` — a non-null manifest `target`
  must match the target ID given;
- a schema 1 backup (everything produced before this slice, with none of the
  new fields) remains fully restorable through both selectors, as long as
  the schema-1-only fields above still match.

`manifest_schema_version` is recognized strictly, by its JSON type: absent
or JSON `null` is schema 1; a JSON *number* equal to `2` is schema 2. Any
other value — `3`, `0`, the JSON *string* `"2"`, an array, an object, a
boolean — is rejected outright, before the temporary database is created,
with `unsupported backup manifest schema_version: ...` naming the offending
value.

Manifest validation always completes — like checksum and storage-archive
validation — before the temporary restore-test database is created.

### What stays legacy-only

`backup-cycle` is unaffected by this slice and remains `--environment`-only
until its own future slice (7.3) — see
[Offsite backup procedure](#offsite-backup-procedure) below. As of slice 7.2,
`offsite-backup`, `offsite-retention` and `offsite-restore-test` themselves
are target-aware — see
[Target-aware offsite backup path](#target-aware-offsite-backup-path-phase-4-slice-72)
below.

## Target-aware offsite backup path (Phase 4 slice 7.2)

`offsite-backup`, `offsite-retention` and `offsite-restore-test` accept the
same selector contract as `backup`/`restore-test`:

```bash
sudo /home/www/rateguru/bin/offsite-backup --environment staging
sudo /home/www/rateguru/bin/offsite-backup --target staging-main

sudo /home/www/rateguru/bin/offsite-retention --environment staging
sudo /home/www/rateguru/bin/offsite-retention --target staging-main
sudo /home/www/rateguru/bin/offsite-retention --environment staging --apply
sudo /home/www/rateguru/bin/offsite-retention --target staging-main --apply

sudo /home/www/rateguru/bin/offsite-restore-test --environment staging
sudo /home/www/rateguru/bin/offsite-restore-test --target staging-main
```

`--target` and `--environment` are mutually exclusive, exactly one is
required, and `--help` documents both forms. All three scripts require root
unconditionally, as the first action of every invocation. In target mode,
`require_active_target` runs immediately after root authorization — before
any `rclone` config check, remote listing, local backup root, lock, temp
directory, or database work — so a planned target (`tits-guru`) is rejected
before anything is touched, including before any Backblaze B2 access.

### Both selectors share the existing remote namespace — nothing moves

Legacy and target selectors of the `staging` namespace resolve to the
**identical** existing remote root, and no existing remote backup is moved
or renamed:

```text
backup namespace = staging
remote root       = rateguru-b2:rateguru-database-backups/rateguru/staging
lock (offsite-backup)      = /home/www/rateguru/run/offsite-backup-staging.lock
lock (offsite-retention)   = /home/www/rateguru/run/offsite-retention-staging.lock
lock (offsite-restore-test) = /home/www/rateguru/run/offsite-restore-test-staging.lock
```

`offsite-backup --environment staging` and
`offsite-backup --target staging-main` cannot run concurrently — every lock
filename and every remote root is built from the resolved backup namespace,
never from the selector label. The same holds for `offsite-retention` and
`offsite-restore-test`.

### Retention: side-effect-free dry-run, target-specific retention days

`offsite-retention`'s default mode is a dry-run that lists remote backups and
prints `WOULD DELETE` lines. It never calls `rclone purge` in dry-run mode —
this is a genuine code-path guarantee, not a reliance on `rclone`'s own
dry-run flag. Only `--apply` performs real deletion, and even then: the
candidate set is listed once for a preview, the lock is acquired, the remote
is listed *again* under the lock, and only backups present in that
re-listing are purged — so a backup uploaded between the preview and the
locked listing is never deleted.

The retention window itself is resolved independently per selector: legacy
mode uses `environment_offsite_backup_retention` (`staging` = 30 days,
`production` = 90 days); target mode uses the registry's own
`offsite_retention_days` field for the resolved target. These can genuinely
differ even for targets that share a namespace — the latest backup and any
backup inside either selector's own retention window are always protected,
regardless of which selector triggered the run.

### Manifest validation reuses the same strict schema contract

`offsite-backup` and `offsite-restore-test` validate the manifest of the
backup they select using the identical strict, type-based
`manifest_schema_version` classification as local `restore-test` (absent or
JSON `null` → schema 1; JSON number `2` → schema 2; anything else, including
the JSON string `"2"`, is rejected outright). Schema 2 additionally requires
`backup_namespace` to match the resolved namespace, and — under `--target` —
a non-null manifest `target` to match the target ID given. `offsite-backup`
validates the manifest of the local backup it is about to upload before any
Backblaze B2 access check; `offsite-restore-test` validates the manifest of
the remote backup it downloads before creating the temporary restore
database. Local `restore-test` is untouched by this slice and keeps its own,
already-correct implementation of the same contract.

### What stays legacy-only

`backup-cycle` is unaffected by this slice and remains `--environment`-only
until its own future slice (7.3).

## Offsite backup procedure

Run the complete local and offsite backup cycle for the required environment:

```bash
sudo /home/www/rateguru/bin/backup-cycle --environment staging
sudo /home/www/rateguru/bin/backup-cycle --environment production
```

The cycle creates a local backup, invokes `offsite-backup`, and applies offsite
retention. The upload uses `rclone copy --immutable`, so an existing remote
object is never overwritten with different content. `backup-cycle` itself
remains `--environment`-only until slice 7.3; the `offsite-backup` and
`offsite-retention` invocations it makes internally are unaffected by this
and keep using the legacy selector until then.

### Recovering from an immutable partial upload

If an interrupted upload leaves a stale remote object whose content differs
from the local backup, later `--immutable` retries will fail. Inspect the
timestamped remote backup directory, confirm that it is the incomplete upload,
and perform manual cleanup of that remote directory before rerunning
`offsite-backup`. Never remove a remote directory that has already passed the
offsite restore test.
