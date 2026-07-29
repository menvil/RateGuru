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

### What's target-aware as of this slice

As of slice 7.2, `offsite-backup`, `offsite-retention` and
`offsite-restore-test` are target-aware — see
[Target-aware offsite backup path](#target-aware-offsite-backup-path-phase-4-slice-72)
below. As of slice 7.3, `backup-cycle` itself is target-aware too — see
[Target-aware backup cycle](#target-aware-backup-cycle-phase-4-slice-73-completed)
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
differ even for targets that share a namespace — each run protects the
latest backup unconditionally, and otherwise deletes only what falls outside
*its own invoking selector's* resolved window. A legacy run never considers
the target's window, and a target run never considers the legacy window: a
backup old enough to be a candidate under a shorter target window can still
be purged by a target run even though the same backup would still be
protected by a separate legacy run using its own, longer window.

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

## Target-aware backup cycle (Phase 4 slice 7.3, completed)

`backup-cycle` accepts the same selector contract as every other backup-path
script:

```bash
sudo /home/www/rateguru/bin/backup-cycle --environment staging
sudo /home/www/rateguru/bin/backup-cycle --environment production
sudo /home/www/rateguru/bin/backup-cycle --target staging-main
```

`--target` and `--environment` are mutually exclusive, exactly one is
required, and `--help` documents both forms. `backup-cycle` requires root
unconditionally, as the first action of every invocation. In target mode,
`require_active_target` runs immediately after root authorization — before
the cycle lock, the history file, or any child command — so a planned target
(`tits-guru`) is rejected before anything is touched.

**Both selectors resolve to the identical existing namespace and cycle
lock**, exactly like `backup`/`restore-test`/`offsite-*`:

```text
backup namespace = staging
cycle lock       = /home/www/rateguru/run/backup-cycle-staging.lock
history file     = /home/www/rateguru/backups/backup-cycles.jsonl
```

`backup-cycle --environment staging` and `backup-cycle --target staging-main`
cannot run concurrently against that namespace — the lock filename is built
from the resolved backup namespace, never from the selector label. A
different namespace (e.g. `production`) uses its own, independent lock and
never contends with `staging`.

### The five-step pipeline, strictly in order, fail-fast

```text
1. backup
2. restore-test
3. offsite-backup
4. offsite-retention --apply
5. offsite-restore-test
```

Every step is invoked with the exact same selector the cycle itself
received (`--environment staging` or `--target staging-main` for all five —
selectors are never mixed within one cycle), and its real stdout/stderr is
never suppressed. Each step only runs if the previous one exited `0`; the
first failure stops the cycle immediately, and the cycle's own exit code is
the failing child's exit code, unmodified.

### Retention safety ordering

`offsite-retention --apply` — the one step in this pipeline that actually
deletes old remote backups — only ever runs after local backup, local
restore-test, and the offsite upload have all already succeeded. If any of
those three fails, retention is never reached, so old B2 backups are never
purged on the strength of a local backup or upload that did not actually
happen. `offsite-restore-test` always runs after retention: if retention
succeeds but the offsite restore-test then fails, the cycle is still recorded
as failed — the retention deletion itself is **not** rolled back; this is a
deliberate, documented limitation of this slice, not an oversight.

**This slice does not delete local backups.** Local retention (pruning old
timestamped directories under `/home/www/rateguru/backups/<namespace>/`) is
`backup`'s own existing, unchanged behaviour — `backup-cycle` does not add
any local retention of its own.

### Cycle history: one compact JSON record per cycle

Every started cycle appends exactly one line to
`/home/www/rateguru/backups/backup-cycles.jsonl` (created `0600`, inside a
`0700` root-owned directory), generated entirely through `jq -cn`:

```json
{"status":"ok","started_at":"2026-07-29T15:00:00Z","completed_at":"2026-07-29T15:02:00Z","selector":"target","target":"staging-main","environment":"staging","backup_namespace":"staging","completed_steps":["backup","restore-test","offsite-backup","offsite-retention","offsite-restore-test"],"failed_step":null}
```

A failed cycle additionally carries `exit_code` (the failing child's own exit
code) and a `completed_steps` array that only lists the steps that actually
finished before the failure:

```json
{"status":"failed","started_at":"...","completed_at":"...","selector":"target","target":"staging-main","environment":"staging","backup_namespace":"staging","completed_steps":["backup","restore-test"],"failed_step":"offsite-backup","exit_code":1}
```

`target` is `null` for the legacy `--environment` selector, exactly like
every other backup-path history record. A `lifecycle=planned` rejection or a
cycle-lock contention writes **no** history record at all — history only
ever records a cycle that genuinely started (i.e., past the lock). A history
write failure after every step already succeeded still turns the cycle into
a reported failure; a history write failure on the failure path is logged
but never replaces the original child's exit code.

**Accepted on the real staging VPS:** a real `backup-cycle --target
staging-main` ran the full five-step pipeline to completion — local backup,
local restore-test, offsite upload, offsite retention apply, and offsite
restore-test all succeeded — and the cycle was recorded in
`/home/www/rateguru/backups/backup-cycles.jsonl`; the final staging
health-check passed.

### Cron now calls backup-cycle by target (Phase 4 slice 8)

`/etc/cron.d/rateguru-backups` (installed from
`infrastructure/config/cron/rateguru-backups`) calls all three operational
commands — the nightly `backup-cycle`, and the weekly `restore-test` /
`offsite-restore-test` — with `--target staging-main`, not
`--environment staging`. Schedules and log paths are unchanged; see
[`target-perimeter.md`](target-perimeter.md) for the perimeter migration
this belongs to. `backup-cycle` itself did not change for this: it has
accepted `--target` since slice 7.3 (above).

### Recovering from an immutable partial upload

If an interrupted upload leaves a stale remote object whose content differs
from the local backup, later `--immutable` retries will fail. Inspect the
timestamped remote backup directory, confirm that it is the incomplete upload,
and perform manual cleanup of that remote directory before rerunning
`offsite-backup`. Never remove a remote directory that has already passed the
offsite restore test.
