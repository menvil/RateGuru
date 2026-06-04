# Visual Regression

Phase 39 starts the RateGuru visual regression foundation. It provides
repeatable screenshot capture and approved baseline storage, but it does not
add automatic pixel diff failures in CI.

## Commands

Visual screenshots use the same Pest Browser runner as the Phase 38 browser
smoke tests. The command starts its own Laravel test server through the browser
runner, so no separate `php artisan serve` process is required.

Capture the desktop feed screenshot:

```bash
php artisan visual:screenshot feed-desktop
```

Capture the mobile feed screenshot:

```bash
php artisan visual:screenshot feed-mobile
```

Capture the upload modal open state:

```bash
php artisan visual:screenshot upload-modal
```

Capture the post drawer open state:

```bash
php artisan visual:screenshot post-drawer
```

Capture the standalone post show page:

```bash
php artisan visual:screenshot post-show
```

Save into approved baselines instead of current screenshots:

```bash
php artisan visual:screenshot feed-desktop --baseline
```

Run every registered target:

```bash
php artisan visual:screenshot all
```

Regenerate every approved baseline after an intentional visual change:

```bash
php artisan visual:screenshot all --baseline
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
| `upload-modal` | `1440x1000` | Authenticated feed with upload modal open | `tests/Visual/current/upload-modal.png` |
| `post-drawer` | `1440x1000` | Public feed with deterministic post drawer open | `tests/Visual/current/post-drawer.png` |
| `post-show` | `1440x1000` | Standalone deterministic post show page | `tests/Visual/current/post-show.png` |

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

Use this workflow for public UI changes:

```bash
composer test:browser
php artisan visual:screenshot all
```

Then compare:

```txt
tests/Visual/current/
tests/Visual/baselines/
```

Only update baselines with `--baseline` when the visual change is intentional
and reviewed. Do not update baselines to hide broken images, layout overflow,
debug text, validation errors, or accidental styling drift.

Phase 43 replaces domain-specific public copy with generic Source, Category,
and Post wording. Regenerate visual baselines after the Phase 43 generic copy
merge only as an intentional visual review step.

Phase 39 intentionally does not add automatic pixel comparison or CI failures.
`tests/Visual/diff/` is reserved for a later visual-diff phase.

## Approved Baselines

| Baseline | Status |
| --- | --- |
| `feed-desktop.png` | Approved in RG-631 |
| `feed-mobile.png` | Approved in RG-632 |
| `upload-modal.png` | Approved in RG-633 |
| `post-drawer.png` | Approved in RG-634 |
| `post-show.png` | Approved in RG-635 |
