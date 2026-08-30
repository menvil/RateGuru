# Durable release artifact archive

RateGuru's third, durable copy of every immutable application release. This
runbook is the operational source of truth for what is archived, where it
lives, which credentials exist, how a release is retrieved after the fact, and
what a human has to configure by hand.

It is Phase 7.1 of the disaster-recovery roadmap and nothing more. It does not
restore data, does not rebuild a host, does not roll anything back and does not
touch the application VPS at all.

---

## The question this exists to answer

> The VPS is gone. The GitHub Actions artifact expired three weeks ago. Which
> exact bytes were running, and can we get them back?

A database backup tells us what *data* existed. It says nothing about which
application build produced it. Total VPS loss destroys the deployed release
tree, and GitHub Actions artifact retention is deliberately short (3 days), so
without a durable archive the only remaining answer would be "rebuild from
source and hope it is the same" — which is not a recovery, it is a new build.

Phase 7.1 makes the answer deterministic:

```text
release ID (v0.0.0-20260830-120211-ca7d1c7)
  ↓
rateguru-release-artifacts/rateguru/artifacts/<release-id>/
  ↓
the exact tarball that was built, its SHA-256, and its own release.json
  ↓
checksum-verified retrieval onto any machine, at any later date
```

Nothing is rebuilt during recovery. The immutable tarball that was originally
deployed *is* the recovery artifact.

---

## Storage identity

| Concept | Value |
|---|---|
| Provider | Backblaze B2, through `rclone` |
| Bucket | `rateguru-release-artifacts` |
| Project namespace | `rateguru` |
| Release directory | `rateguru/artifacts/<release-id>/` |
| rclone remote name | `rateguru-artifacts-b2` |

```text
rateguru-release-artifacts/
└── rateguru/
    └── artifacts/
        └── v0.0.0-20260830-120000-abcdef1/
            ├── rateguru-v0.0.0-20260830-120000-abcdef1.tar.gz
            ├── rateguru-v0.0.0-20260830-120000-abcdef1.tar.gz.sha256
            └── release.json
```

### A separate bucket, deliberately

Release artifacts are **not** stored inside the `rateguru-database-backups`
namespace. Backup retention and release-artifact retention must never be able
to affect each other: a backup-retention change must never be able to delete a
release, and a release policy must never be able to touch a backup.

### The path is project-scoped, never target-scoped

There is no `staging-main/` or `tits-guru/` anywhere in the archive path. The
same immutable application artifact is a **project** artifact — the identical
bytes are what every target that ever ran that release would need back. The
target belongs to the deployment record, not to the artifact's identity.

The `rateguru/` prefix exists so a future project (`cataloghub/artifacts/…`)
can share the model without collisions. It is **not** operator input: the
project ID is a fixed constant in the code, and no flag can move a RateGuru
artifact into another project's namespace.

---

## Where the archive sits in the pipeline

```text
resolve  →  build  →  archive  →  deploy  →  observability
                        ▲
                        └── hard precondition
```

```text
build immutable artifact
        ↓
local checksum verification
        ↓
durable B2 archive
        ↓
remote archive verification
        ↓
deploy to staging
        ↓
existing deployment verification
        ↓
existing Sentry marker
```

**The archive is a hard precondition, not a best-effort side effect.** The
`deploy` job `needs: [resolve, build, archive]`, so GitHub will not start it
unless the archive job succeeded. If the durable archive fails, the deployment
never starts and no release becomes `current`. There is deliberately no
`continue-on-error` anywhere on that path — this is the opposite of the Sentry
release action, which is observability and may never fail a healthy deployment.

**The archive runs from CI, never from the VPS.** The artifact is already on
the trusted runner immediately after the build, so it is archived there, before
deployment. The storage that protects us from host loss must not require the
host being protected in order to create the durable archive.

**GitHub's own artifact stays.** It remains 3-day CI transport and short-term
debugging material. Its retention is never raised as a substitute for B2 — B2
is the durable source.

---

## The three files, and why release.json is one document

The build job writes `release.json` into the package root, tars it, checksums
the tarball, and then copies *that same file* out beside the tarball:

```bash
cp "${package_root}/release.json" "${output_root}/release.json"
```

There is exactly one release.json document. It is frozen inside the tarball,
archived beside the tarball, and deployed with the tarball — never two
independently generated documents whose values could diverge. Both the archive
and the retrieval prove this: the copy inside the artifact must be
**byte-identical** to the copy beside it, or the operation hard-fails.

That is what makes the archived metadata usable during recovery without
unpacking or rebuilding anything.

---

## Repository-owned primitives

All business rules live in repository scripts, not in workflow YAML. The
GitHub Action is transport and orchestration only.

| Script | Purpose |
|---|---|
| `infrastructure/scripts/archive-release-artifact` | Validate and durably archive one release |
| `infrastructure/scripts/fetch-release-artifact` | Retrieve and re-prove one archived release |
| `infrastructure/scripts/release-artifact-common` | The shared contract both of them source |
| `infrastructure/scripts/fetch-verified-rclone` | Install the pinned, signature-verified rclone without root |

These primitives deliberately **do not** source
`/home/www/rateguru/bin/common`. One of their consumers is a GitHub runner
before any deployment exists, and a future consumer is a completely new
recovery host before RateGuru target operations exist on it. They read no
target registry, resolve no deployment configuration and take no `--target`.
They are portable, repository-owned and callable by path alone.

---

## Validation: what is proven before a single byte is uploaded

1. the release ID matches the canonical
   `vX.Y.Z-YYYYMMDD-HHMMSS-shortsha` identity;
2. the canonical artifact filename `rateguru-<release-id>.tar.gz` exists,
   along with its `.sha256` sidecar and `release.json`;
3. the sidecar is exactly one well-formed entry **naming that artifact** — a
   sidecar describing some other file is rejected rather than believed;
4. the artifact's real SHA-256 equals the sidecar digest;
5. `release.json` is valid JSON;
6. `project == "rateguru"`, `release == <requested release ID>`, and
   `source_sha` is a 40-character Git commit SHA whose first characters are
   the release ID's own short-SHA component;
7. the `release.json` inside the tarball is byte-identical to the one beside
   it.

Any mismatch is a hard failure. A malformed or ambiguous artifact is never
archived.

---

## Immutability and idempotency

The archive operation is safe to retry. It classifies the remote release
directory before deciding anything:

| Remote state | Behaviour |
|---|---|
| Absent | Upload, verify, PASS |
| Present, identical | No upload at all, verify, PASS (idempotent no-op) |
| Present, a strict subset, every present object identical | Upload only the missing objects, verify, PASS |
| Present, any object differs | **HARD FAIL** — refuses to overwrite an immutable release |
| Present, an unexpected object exists under the release ID | **HARD FAIL** |

The upload itself is `rclone copy --immutable --check-first --checksum` from a
private staging directory holding exactly the three canonical files, so rclone
physically cannot push anything else into the release namespace.

**No delete. No replace. No mutation.** There is no `rclone delete`, `purge`,
`sync`, `move` or `rmdir` anywhere in the archive path, and Phase 7.1
implements no retention, deletion or garbage collection at all — nothing here
may ever remove a previously archived release. Retention will be designed
separately, once recovery-point references exist.

---

## Post-upload verification

A successful upload is not acceptance. Every path — fresh upload, resumed
upload, idempotent no-op — proves the remote archive against the local release
afterwards:

- `rclone check --checksum` between the staged release and the remote
  directory, in both directions;
- the remote listing is exactly the three expected files;
- the artifact is **streamed back out of the remote** and re-hashed here, and
  must equal the sidecar digest;
- the remote `release.json` and `.sha256` are read back and compared
  byte-for-byte with the local ones.

A partial archive fails.

---

## Credential model

| Name | Kind | Value |
|---|---|---|
| `B2_ARTIFACT_KEY_ID` | repository **secret** | Backblaze application key ID |
| `B2_ARTIFACT_APPLICATION_KEY` | repository **secret** | Backblaze application key |
| `B2_ARTIFACT_BUCKET` | repository **variable** | `rateguru-release-artifacts` |

The B2 application key must be **restricted to the release-artifact bucket**.
It is not the database-backup key, not the VPS root rclone configuration, not
a staging `.env` value and not an application secret. Nothing on a RateGuru
host ever holds it.

The credentials are repository-level rather than environment-level on purpose:
the durable archive is a project concern, not a target concern, and the archive
job is deliberately not bound to the `staging` GitHub Environment.

Handling inside the job:

- passed to the composite action through `env:` only, never a command line, so
  they cannot leak through a process list;
- written into a temporary rclone configuration created with
  `install -m 0600 /dev/null`, inside `RUNNER_TEMP`;
- never echoed, never `cat`-ed, never written to the step summary;
- deleted in an `always()` cleanup step, so a failed archive cleans up too;
- `rclone.conf` is gitignored, so a generated configuration can never be
  committed.

A missing credential is **fatal**, not a skip: refusing to archive means
refusing to deploy.

---

## rclone

The runner installs the pinned, signature-verified rclone this repository
already trusts — never `curl | bash`, never an unversioned runner-preinstalled
binary, never `rclone selfupdate`:

```bash
infrastructure/scripts/fetch-verified-rclone --into "${RUNNER_TEMP}/rateguru-rclone"
```

The version, platform and official release-signing key fingerprint all come
from the single committed contract at
`infrastructure/config/external-runtimes/versions.env`, the same file
`install-bootstrap-runtime` and `bootstrap-host-preflight` read. Moving the pin
stays one reviewed repository change in one place.

The verification chain is the same one the host installer performs: the
committed public key is trusted only after it dearmors to a keyring holding
exactly the pinned fingerprint; the clearsigned `SHA256SUMS` is verified
against that keyring alone; the archive's SHA-256 must match the verified
payload; and the extracted binary must report exactly the pinned version before
it is installed.

`fetch-verified-rclone` needs no root, never touches `/usr/bin/rclone`, and
never reads or writes an operator's `~/.config/rclone/rclone.conf`.

---

## Step summary

Every archive run writes an operator-facing summary, on success and on
failure:

```text
## RateGuru Release Archive

Project:        rateguru
Release:        v0.0.0-20260830-120000-abcdef1
Source SHA:     abcdef1234567890abcdef1234567890abcdef12
Bucket:         rateguru-release-artifacts
Remote path:    rateguru/artifacts/v0.0.0-20260830-120000-abcdef1/
Checksum:       2dd4254cae2170c65bc06abe86c1babcca92bfa840cc5dc5f54cf06dc149171e

Archive upload: PASS
Remote verify:  PASS
Result:         PASS
```

No secret value ever appears in it.

---

## Retrieval

The canonical way to get an archived release back:

```bash
infrastructure/scripts/fetch-release-artifact \
    --release v0.0.0-20260830-120000-abcdef1 \
    --destination /tmp/rateguru-recovery \
    --rclone-config /path/to/rclone.conf \
    --rclone-bin /usr/bin/rclone
```

It:

1. validates the release ID;
2. retrieves **only** `rateguru/artifacts/<release-id>/`;
3. rejects a missing or incomplete archive before downloading anything;
4. verifies the artifact against its SHA-256 sidecar;
5. validates `release.json`, including `project == rateguru` and
   `release == <requested release>`;
6. verifies the retrieved artifact contains a matching `release.json`;
7. exits non-zero on any mismatch.

The destination must not already hold any of the three canonical files:
whatever the script reports as verified has to be what it just downloaded.

Everything is downloaded and validated in a private staging directory inside
the destination, and the canonical filenames only appear once the package has
passed. **A failed retrieval therefore leaves the destination exactly as it
found it and is safe to retry into the same directory** — there is nothing to
clean up by hand, and unverified bytes are never left where they could be
mistaken for a good package.

Its output is a verified artifact package on disk — nothing more. **It does
not deploy the retrieved release.** Clean-host recovery (Phase 7.6) will
consume this primitive; that is a later slice.

To use it, supply an rclone configuration declaring a `rateguru-artifacts-b2`
remote:

```ini
[rateguru-artifacts-b2]
type = b2
account = <B2_ARTIFACT_KEY_ID>
key = <B2_ARTIFACT_APPLICATION_KEY>
```

Write it `0600`, outside the repository, and remove it when done. Never commit
it.

---

## Failure semantics

| Situation | Result |
|---|---|
| Malformed release ID | Hard fail, before any network call |
| Any local validation failure | Hard fail, before any upload |
| Storage unreachable / credentials wrong | Hard fail — never mistaken for "nothing archived yet" |
| Release already archived identically | PASS, no upload, still verified |
| Release archived with different content | Hard fail, nothing overwritten |
| Post-upload verification failure | Hard fail |
| Archive job fails for any reason | **The deploy job never starts** |

---

## What this does not do

Explicitly out of scope for Phase 7.1, and implemented nowhere in it:

- database restore, target data restore, safety backups, maintenance
  orchestration;
- Repair Target, clean-host drill, recover-host, DNS, TLS cutover, production
  activation;
- release-artifact retention, deletion or garbage collection;
- the backup ↔ release recovery-point mapping (Phase 7.2);
- any change to rollback, Sentry, Nightwatch or the backup/offsite scripts —
  `rollback-staging.yml` already exists and is not Phase 7 work.

---

## Operator acceptance

Run these after merging and configuring the secrets. They prove the real
staging pipeline, not a simulation.

### 1. Configure GitHub

Repository → Settings → Secrets and variables → Actions:

- secret `B2_ARTIFACT_KEY_ID`
- secret `B2_ARTIFACT_APPLICATION_KEY`
- variable `B2_ARTIFACT_BUCKET` = `rateguru-release-artifacts`

The Backblaze application key must be restricted to the
`rateguru-release-artifacts` bucket, with permission to list, read and write
objects. It needs no delete permission and should not be given one.

### 2. Archive a real staging release

Run **Deploy to staging** (`workflow_dispatch`, `ref: develop`).

Expect, in order:

- the `archive` job runs after `build` and before `deploy`;
- its step summary reports `Result: PASS` with the release, source SHA,
  bucket, remote path and checksum;
- the `deploy` job then runs and succeeds exactly as before;
- the Sentry deployment marker is recorded exactly as before.

Record the release ID from the summary.

### 3. Confirm the object store contains exactly three files

```bash
rclone --config /path/to/rclone.conf lsf \
    rateguru-artifacts-b2:rateguru-release-artifacts/rateguru/artifacts/<release-id>/
```

Expect exactly:

```text
rateguru-<release-id>.tar.gz
rateguru-<release-id>.tar.gz.sha256
release.json
```

and no `staging-main` or any other target segment anywhere in the path.

### 4. Retrieve it and verify it

```bash
infrastructure/scripts/fetch-release-artifact \
    --release <release-id> \
    --destination /tmp/rateguru-recovery \
    --rclone-config /path/to/rclone.conf \
    --rclone-bin /usr/bin/rclone \
    --report /tmp/rateguru-recovery/report.json
```

Expect exit code `0` and a final line reporting the verified artifact path.

### 5. Prove the retrieved release is the deployed release

The sidecar records the artifact's bare filename, so `sha256sum -c` resolves
it against the current directory — run these from inside the retrieval
directory, not from wherever you happen to be:

```bash
cd /tmp/rateguru-recovery

jq -r '.release, .source_sha' release.json
sha256sum -c rateguru-<release-id>.tar.gz.sha256
tar -xzOf rateguru-<release-id>.tar.gz ./release.json | jq -r .release
```

All three must agree with each other and with the release the staging host is
serving:

```bash
ssh <deploy-user>@<staging-host> 'cat /home/www/rateguru/staging/current/release.json' | jq -r '.release, .source_sha'
```

### 6. Prove idempotency and immutability

Re-run **Deploy to staging** for the *same* commit only if you want a new
release ID; to test idempotency directly, re-run the archive script by hand
against the same source directory and confirm it reports
`The existing archive is already identical to this release` and
`Nothing to upload`, with `Result: PASS`.

Then clean up:

```bash
rm -rf /tmp/rateguru-recovery
```

Nothing in the object store is ever deleted as part of acceptance.
