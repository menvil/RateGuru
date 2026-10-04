# Public storage access for Nginx

This runbook covers `infrastructure/scripts/install-public-storage-access`,
which fixes a real defect found on the first live staging VPS install:
Nginx, running as `www-data`, could not serve any uploaded image through
Laravel's `public/storage` symlink.

## The bug

`deploy` (see `infrastructure/scripts/deploy`) creates the shared,
persistent-across-releases part of a target's filesystem like this:

| Path | Owner:group | Mode |
|---|---|---|
| `shared` | `runtime_user:runtime_user` | `2770` |
| `shared/storage` | `runtime_user:runtime_user` | `2770` |
| `shared/storage/app` | `runtime_user:www-data` | `2710` |
| `shared/storage/app/public` | `runtime_user:www-data` | `2750` |

`app` and `app/public` are already correctly group-owned by `www-data` — the
files Nginx needs to serve are not the problem. `shared` and
`shared/storage`, the two directories in between, are group-owned by the
**target's own runtime user** (`rateguru-<target>`), not `www-data`.

That is why an uploaded file arrives world-unreadable-but-otherwise-correct
and Nginx still returns **403** with `stat() failed (13: Permission
denied)` in its error log: on Linux, reading a file requires **execute
(traversal)** permission on every directory in its path, not just read
permission on the file itself. `www-data` can read
`shared/storage/app/public/photo.jpg` directly (mode 0640-ish, group
`www-data`) but cannot even `stat()` it, because it cannot pass through
`shared` or `shared/storage` to get there — those two directories give
`www-data` nothing at all.

Making the file itself `0644` would not fix this — the file was never the
blocked step. The two parent directories are.

## Why an ACL, not `chmod` or group membership

Three options exist to let `www-data` traverse `shared` and
`shared/storage`. Two of them are deliberately **not** used:

- **`chmod 0755` (or similar) on `shared`/`shared/storage`** would grant
  `www-data` — and everyone else on the box — the ability to **list** the
  contents of both directories, not just traverse them. `shared` and
  `shared/storage` are siblings of `logs`, `framework`, and
  `app/private` — directories that must stay invisible to the web server.
  Directory-listing permission and traversal permission are different bits
  (`r` vs `x`); this fix only ever needs `x`.
- **Adding `www-data` to the `rateguru-<target>` group** would give the web
  server every permission that group already has on every file under
  `shared` — logs, framework cache, private uploads, everything — not just
  execute on two directories. That is a much larger blast radius than the
  bug requires.
- **A POSIX ACL entry `user:www-data:--x` on exactly `shared` and
  `shared/storage`** grants precisely execute-only traversal, to precisely
  one user, on precisely the two directories that are actually in the way.
  Nothing else on the filesystem changes. This is what
  `install-public-storage-access` does — and *only* this.

The two directories keep their original owner, group, mode and setgid bit.
The ACL is the only thing this installer ever changes.

## Host prerequisite: the `acl` package

`setfacl`/`getfacl` come from Ubuntu's `acl` package, which is not part of a
minimal base image. There is currently no committed host-bootstrap package
manifest for this repository (`infrastructure/bootstrap/` is empty — Phase 5,
"Infrastructure installer and clean-VPS bootstrap," is still planned) — so
until that exists, install it manually once per host:

```bash
sudo apt-get install acl
```

`--check` and `--apply` both fail with a clear message naming this package if
`setfacl`/`getfacl` are missing; neither ever runs `apt-get` itself.

## Modes

```bash
infrastructure/scripts/install-public-storage-access --check  --target TARGET
infrastructure/scripts/install-public-storage-access --apply  --target TARGET
infrastructure/scripts/install-public-storage-access --verify --target TARGET
infrastructure/scripts/install-public-storage-access --help
```

Exactly one mode and exactly one `--target` are required. A duplicate mode,
a duplicate `--target`, an unrecognized flag, a flag missing its value, or a
stray positional argument all fail with a clear error before anything else
runs. `TARGET` is resolved exclusively through
`/home/www/rateguru/bin/common`'s `target_*` helpers — never by reading the
registry JSON directly — so only a target with `lifecycle=active` can ever
be changed or verified. `tits-guru` (`lifecycle=planned`) is rejected before
any filesystem or ACL access, the same as every other target-aware command
in this repository.

**All three modes require root.** `--check` and `--verify` both use `runuser`
to test filesystem access as the target's runtime user and as `www-data` — an
unprivileged caller cannot use `runuser` to switch to another user regardless
of what the documentation says, so requiring root is what makes that
documentation actually true rather than aspirational. `--check` remains
strictly read-only even so: no ACL, file or backup state is ever written by
that mode.

### `--check` — requires root, no changes

Validates: the target is active; every host tool this script actually
invokes is present — `setfacl`, `getfacl`, `stat`, `namei`, `readlink`,
`runuser`, `curl`, `mktemp`, `install`, `find`, `od`, `cmp`, `tr`, `head`,
`sleep`, `cat`, `rm`, `ls`, `date`, `chmod`, `grep` — an exhaustive list, not
just the "unusual" ones, so a host missing any of them fails here with a
clear message instead of a raw "command not found" partway through `--apply`
or `--verify`; `shared`, `shared/storage`, `shared/storage/app`,
`shared/storage/app/public` exist as real directories, never symlinks;
`current/public/storage` is a symlink that resolves exactly to
`shared/storage/app/public`; `shared` and `shared/storage` are owned by the
target's runtime user and are not world-writable; `shared/storage/app/public`
is writable by the runtime user; the script itself is executable. It also
*reports* (without altering) the current ACL and traversal state, so an
operator can see what `--apply` would change before running it.

### `--apply` — requires root, transactional

```bash
sudo infrastructure/scripts/install-public-storage-access --apply --target staging-main
```

1. Everything `--check` does.
2. Confirms the target is currently healthy (an internal `curl` against its
   health endpoint) — refuses to proceed on an already-unhealthy target, the
   same reasoning as `install-target-operations`: there would be no way to
   tell whether a later failure was caused by this change.
3. Creates a timestamped backup directory (see below) and saves the
   **current** ACL state of `shared` and `shared/storage` via
   `getfacl -p`, in a form `setfacl --restore` can replay exactly.
4. Applies exactly two commands:

   ```bash
   setfacl -m u:www-data:--x /home/www/rateguru/<target>/shared
   setfacl -m u:www-data:--x /home/www/rateguru/<target>/shared/storage
   ```

   No `chmod`, `chown`, `chgrp`, `usermod`, or group-membership change is
   ever applied to `shared` or `shared/storage` — enforced by architecture
   tests that grep the script's own source, not just by manual review. (The
   disposable canary file in step 5 does get one `chmod`, restoring its
   world-readable mode after atomic creation — see below — but that is a
   file under `public/`, never one of the two ACL-controlled directories.)
5. Verifies the result end to end: traversal now succeeds for `www-data`;
   directory **listing** still fails for `www-data` on both directories;
   `.env` (if present) and `logs`/`framework`/`app/private` (if present)
   remain unreadable by `www-data`; the ACL is exactly `user:www-data:--x`,
   nothing more; a disposable, randomly-named canary file is created and
   written **atomically** by the runtime user — via a real `mktemp` template
   rooted inside `public/`, never the unsafe "pick a name, then write to it"
   pattern, so a symlink already sitting at (or planted at) the chosen name
   can never be selected or written through — confirmed readable by
   `www-data` directly, then fetched over real HTTP through the target's own
   health host/URL at `/storage/<name>` — requiring an exact HTTP 200 with a
   body matching the random token exactly (no redirect, no Laravel error
   page) — and is always removed afterward, success or failure; if any real
   upload already exists under public storage, one is also spot-checked the
   same way over HTTP; `tits-guru` is confirmed still rejected throughout.
6. Only once every one of the above passes is the change committed. Before
   that point, any failure restores the ACL from the backup taken in step 3
   and re-confirms it **exactly** matches that backup, byte for byte — not
   merely that `www-data` can no longer traverse, which would be the wrong
   check whenever the ACL already existed before an idempotent re-apply (the
   correct rollback target there is the pre-existing, permissive ACL, not an
   assumed-blocked baseline). This happens automatically, via a trap, without
   a second command — and always removes the canary file, even on failure.

### `--verify` — requires root, read-only

```bash
sudo infrastructure/scripts/install-public-storage-access --verify --target staging-main
```

Re-runs every check from step 5 above against whatever is currently applied.
Makes no changes. Reports each phase separately:

```text
--- target validation ---
--- filesystem structure ---
--- ACL traversal ---
--- private-data isolation ---
--- direct www-data read ---
--- internal HTTP public-media test ---
--- final result ---
PASS: public storage access verified
```

A failure at any phase stops there with a specific error and a final `FAIL`
line.

## Backup location

```text
/var/backups/rateguru-public-storage-access/<UTC timestamp>-<pid>-<target>/acl.restore
```

One file per `--apply` run, containing the pre-change `getfacl -p` output for
`shared` and `shared/storage`, non-recursive. The process ID is included
alongside the timestamp so that two `--apply` runs against the same target
within the same UTC second (a scripted retry, for instance) each still get
their own backup directory rather than one silently overwriting the other's
pre-change state. Never deleted automatically — left for an operator to
prune manually, the same convention as `install-target-operations`'s own
backup directory.

### Manually restoring a backup, if automatic rollback itself fails

```bash
sudo setfacl --restore=/var/backups/rateguru-public-storage-access/<timestamp>-<pid>-<target>/acl.restore
```

Confirm with:

```bash
sudo infrastructure/scripts/install-public-storage-access --verify --target <target>
```

## Expected `getfacl` output after a successful apply

```text
$ getfacl /home/www/rateguru/<target>/shared
# file: home/www/rateguru/<target>/shared
# owner: rateguru-<target>
# group: rateguru-<target>
# flags: -s-
user::rwx
user:www-data:--x
group::rwx
mask::rwx
other::---
```

Same shape for `shared/storage`. Owner, group and the setgid flag (`s`) are
exactly what `deploy` already set — only the extra `user:www-data:--x` line
is new.

## Security invariants

- `WEB_USER` is hardcoded to `www-data`; it is never accepted as a CLI
  argument or environment variable.
- Destination paths are always derived from `target_root TARGET`, never
  accepted directly.
- Only `setfacl -m u:www-data:--x` is ever run, on exactly `shared` and
  `shared/storage`, never recursively.
- `logs`, `framework`, and `app/private` are never touched by this
  installer and remain unreadable by `www-data` after `--apply`, because the
  fix is non-recursive and grants nothing beyond execute on the two named
  directories.
- Nginx configuration is never read or modified by this installer — the fix
  is entirely filesystem-side.
- `tits-guru` (or any other `lifecycle=planned` target) can never be changed
  or verified; `require_active_target` rejects it before any filesystem or
  ACL access.
- The verification canary is created with a real `mktemp` template, never
  `mktemp -u` followed by a separate write — there is no window in which a
  pre-existing or concurrently planted symlink at the chosen name could be
  written through instead of refused.
- A rollback is only ever reported successful once the restored ACL is
  confirmed byte-for-byte identical to the pre-apply backup — never merely
  because `www-data` can no longer traverse, which is the wrong signal
  whenever the ACL already existed before an idempotent re-apply.
- `--check`, `--apply` and `--verify` all require root; `--check` remains
  strictly read-only regardless.

## Future: target bootstrap

Once Phase 5 (a real clean-VPS bootstrap installer) exists, provisioning a
new target should apply this same `user:www-data:--x` ACL on `shared` and
`shared/storage` as part of initial target setup, rather than requiring a
separate manual `--apply` run after the fact. Until then, this installer is
the way to apply and verify the fix on any existing target.
