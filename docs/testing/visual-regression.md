# Visual Regression

Phase 39 starts the RateGuru visual regression foundation. It provides
repeatable screenshot capture and approved baseline storage, but it does not
add automatic pixel diff failures in CI.

## Commands

Capture the desktop feed screenshot:

```bash
php artisan visual:screenshot feed-desktop
```

Capture the mobile feed screenshot:

```bash
php artisan visual:screenshot feed-mobile
```

Save into approved baselines instead of current screenshots:

```bash
php artisan visual:screenshot feed-desktop --baseline
```

Run every registered target:

```bash
php artisan visual:screenshot all
```

Use `--fresh` when you want to refresh the database before capture:

```bash
php artisan visual:screenshot feed-desktop --fresh
```

## Targets

| Target | Viewport | State | Output |
| --- | --- | --- | --- |
| `feed-desktop` | `1440x1000` | Public feed with deterministic published post | `tests/Visual/current/feed-desktop.png` |
| `feed-mobile` | `390x844` | Public feed with deterministic published post | `tests/Visual/current/feed-mobile.png` |

## Paths

Approved baselines are committed:

```txt
tests/Visual/baselines/
```

Current screenshots are generated locally and ignored:

```txt
tests/Visual/current/
```

Diff output is reserved for a later phase and ignored:

```txt
tests/Visual/diff/
```

## Review

Compare generated screenshots manually against the RateGuru design contract
and `/dev/ui-kit` reference direction before approving any baseline in a later
task.
