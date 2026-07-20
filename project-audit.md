# Full-Project Audit Instructions

This file is additional CodeRabbit context used only for the one-off full-history
audit of RateGuru (base commit `f8d765aca3e3e789e542a2eb92e573ece77ca098`, the
first commit on `develop`). It complements `AGENTS.md` and the `path_instructions`
in `.coderabbit.yaml`, which already cover layer boundaries for Controllers,
Livewire/Filament, Actions, Queries, migrations, and tests. Do not repeat those
checks here unless a concrete violation is found — focus on what they don't cover.

## Scope and framing

- This review batch is scoped to one directory at a time via `--dir`, diffed
  against the project's first commit, so every file will appear as "new". Treat
  it as a fresh audit of current code, not as an incremental PR review — do not
  comment on commit hygiene, PR size, or suggest squashing.
- Findings must be actionable and specific to the file/line. Skip generic
  praise, style nitpicks already enforced by Pint/PHPStan, and speculative
  "consider adding tests" comments unless a concrete untested branch exists.

## Priority focus areas (beyond existing path_instructions)

1. **Security** — mass assignment, missing authorization checks, unescaped
   output in Blade, SSRF/URL-import handling, unsafe file uploads, secrets or
   credentials committed in code/config, unbounded rate limiting.
2. **Performance** — N+1 queries (including inside Livewire re-renders and
   Filament tables), missing eager loading, unbounded queries without
   pagination, unnecessary queries inside loops.
3. **Correctness** — race conditions on counters/votes, missing DB
   transactions around multi-step writes, off-by-one or boundary bugs, timezone
   and locale-sensitive logic.
4. **Dead code and duplication** — unused classes/methods, copy-pasted logic
   that should be extracted, leftover scaffolding or debug code.
5. **Consistency** — deviations from established patterns elsewhere in the
   same layer (e.g. an Action that doesn't follow the shape of sibling
   Actions), inconsistent naming.
6. **Test quality** (when reviewing `tests/**`) — assertions that don't
   actually exercise the behavior described by the test name, missing
   negative/authorization-denied cases, brittle assertions on implementation
   details instead of behavior.

## Output

Report findings per file with severity (critical/major/minor). If a directory
has no issues, say so briefly instead of omitting it.
