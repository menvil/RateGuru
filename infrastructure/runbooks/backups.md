# RateGuru backup operations

## Local backup and restore test (Phase 4 slice 7.1)

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

`backup-cycle`, `offsite-backup`, `offsite-retention` and
`offsite-restore-test` are unaffected by this slice and remain
`--environment`-only until their own future slices (7.2 and 7.3) — see
[Offsite backup procedure](#offsite-backup-procedure) below.

## Offsite backup procedure

Run the complete local and offsite backup cycle for the required environment:

```bash
sudo /home/www/rateguru/bin/backup-cycle --environment staging
sudo /home/www/rateguru/bin/backup-cycle --environment production
```

The cycle creates a local backup, invokes `offsite-backup`, and applies offsite
retention. The upload uses `rclone copy --immutable`, so an existing remote
object is never overwritten with different content.

### Recovering from an immutable partial upload

If an interrupted upload leaves a stale remote object whose content differs
from the local backup, later `--immutable` retries will fail. Inspect the
timestamped remote backup directory, confirm that it is the incomplete upload,
and perform manual cleanup of that remote directory before rerunning
`offsite-backup`. Never remove a remote directory that has already passed the
offsite restore test.
