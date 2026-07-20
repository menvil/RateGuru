# CodeRabbit full-project audit — batch scripts

Generated to work around the CLI plan's 50-files-per-review limit while doing a
full audit of the whole codebase, diffed against the project's first commit
(`f8d765aca3e3e789e542a2eb92e573ece77ca098`, `chore: initialize develop
branch`, itself empty) so every tracked file shows up as "new".

## How the batching works

`coderabbit review --dir <path>` filters the diff to everything **recursively**
under `<path>` (confirmed from a prior local review run cached in
`~/.coderabbit/reviews`). Since a single invocation can only target one
directory, each script line below is one directory whose recursive file count
is ≤ 50. Larger directories were walked top-down and only split into their
subdirectories when the parent itself exceeded 50 files — so each command
targets the *largest* directory that still fits the limit, keeping the number
of commands as low as the actual folder structure allows.

- 1284 tracked files in the repo (`git ls-tree -r --name-only HEAD | wc -l`)
- 142 directories fit the ≤50 rule directly → `scripts/01`–`13` (133 commands)
- 90 files sit *directly* inside a directory whose total exceeds 50 (e.g. a
  loose `helpers.php` next to subfolders that are already reviewed
  individually) — `--dir` can't isolate "this directory but not its
  subdirectories", so these files have no valid `--dir` target on their own.
  `scripts/00-orphan-files.sh` handles them by temporarily relocating them
  into a **disposable git worktree** (two subfolders of 45 files each), running
  the review there, then deleting the worktree. Your real branch and working
  tree are never touched.
- `AGENTS.md` and `.coderabbit.yaml` are excluded from the automated pass —
  they're CodeRabbit's own instruction/config files, not application code.
  Worth a quick manual read instead.

Total: **144 `coderabbit review` invocations**, covering 1282 of 1284 files.

## Files

- `project-audit.md` (repo root) — draft audit instructions passed via
  `--config` alongside `AGENTS.md`. Edit it before your first run if you want
  to change what CodeRabbit prioritizes; the existing `.coderabbit.yaml`
  `path_instructions` already cover layer-boundary rules, so this file only
  adds project-audit-specific framing (security, N+1, dead code, test
  quality — see the file for the full list).
- `scripts/00-orphan-files.sh` … `scripts/13-tests-misc.sh` — one script per
  batch, each a sequence of `coderabbit review --agent --type committed
  --base-commit "$BASE" --dir "<path>" --config AGENTS.md project-audit.md`
  calls, with output piped through `tee` into `results/<path>.md` so you see
  it in the terminal and it's saved to disk.
- `scripts/run-all.sh` — convenience wrapper that runs every block in order.
  144 reviews back to back will take a while; running the numbered scripts
  one at a time (at your own pace, across sessions) is the safer default.
- `results/` — created on first run, gitignored. One `.md` file per reviewed
  directory, mirroring the source path.

## Batches

| Script | Covers | Commands | Files |
|---|---|---|---|
| 00-orphan-files.sh | loose files with no standalone `--dir` target (worktree trick) | 2 | 90 |
| 01-app-actions.sh | `app/Actions/*` | 16 | 63 |
| 02-app-support.sh | `app/Support/*` | 19 | 50 |
| 03-app-core-1.sh | `app/{Console,Contracts,Data,Enums,Exceptions,Filament,Http,Jobs}` | 8 | 122 |
| 04-app-core-2.sh | `app/{Livewire,Models,Notifications,Policies,Providers,Queries,Rules,Services,View}` | 9 | 86 |
| 05-database.sh | `database/{factories,migrations,seeders}` | 3 | 84 |
| 06-docs.sh | `docs/*` | 20 | 76 |
| 07-resources.sh | `resources/*` | 12 | 114 |
| 08-root-misc-1.sh | `config`, `lang`, `public`, `routes`, `tools` | 5 | 116 |
| 09-root-misc-2.sh | `bootstrap`, `storage`, `.github` | 3 | 18 |
| 10-tests-feature-a.sh | `tests/Feature/*` (part A) | 17 | 222 |
| 11-tests-feature-b.sh | `tests/Feature/*` (part B) | 17 | 136 |
| 12-tests-unit.sh | `tests/Unit/*` | 11 | 79 |
| 13-tests-misc.sh | `tests/Browser`, `tests/Visual` | 2 | 26 |

## Usage

1. Read/edit `project-audit.md` at the repo root.
2. Sanity-check with one manual command first (copy a single `coderabbit
   review ...` line out of any script) to confirm auth, `--config`
   resolution, and quota behave as expected before running a whole block
   unattended.
3. Run blocks one at a time, in any order:
   ```
   bash coderabbit-review/scripts/09-root-misc-2.sh
   ```
4. Read results under `coderabbit-review/results/`.
