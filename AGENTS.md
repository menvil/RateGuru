# RateGuru Agent Rules

## Project
RateGuru is a Laravel + Livewire + Alpine + Filament application.

## Core stack
- Laravel
- Livewire
- Alpine.js
- Filament
- SQLite first
- Pest/PHPUnit
- Tailwind

## Branch rules
- Work from develop.
- One task = one branch.
- Branch format: feature/RG-XXX-short-title.
- Commit format: RG-XXX: English commit title.

## TDD rules
- Write test first when task is testable.
- Run related test before implementation.
- Implement minimal code.
- Run related test after implementation.
- Do not skip failing tests.

## Architecture rules
- Controllers and Livewire components must stay thin.
- HTTP controllers must use dedicated FormRequest classes for user-input validation.
- HTTP controllers must consume validated input through validated() or safe(); inline validate(), validateWithBag(), Validator::make(), and validator() calls are forbidden.
- Business logic goes into app/Actions.
- Technical helpers go into app/Services.
- Authorization goes into Policies.
- Background work goes into Jobs.
- Do not put business rules directly in Blade views.
- Prefer Eloquent models, relationships, scopes, and app/Queries over direct Query Builder access.
- DB::transaction() is allowed for atomic work; DB::table() and direct SQL execution require a documented technical exception.
- Raw SQL expressions require an explicit reviewed allowlist entry, bound user values, and related tests.
- Paginated queries must end with a unique deterministic ordering column such as id.

## UI rules
- UI must follow the RateGuru design contract.
- Every UI task must check docs/design/ui-review-checklist.md.
- Original prototype reference must be checked before visual UI work.
- Before implementing any UI screen, the agent must open /dev/ui-kit and compare against the PlateRate Reference Composition.
- Reusable UI belongs in Blade components.
- Every reusable component must be rendered in /dev/ui-kit.
- Reusable UI components must use RateGuru tokens and must not introduce random Tailwind color utilities unless the deviation is documented in docs/design/design-contract.md.
- The PlateRate Reference Composition is the visual source of truth for Phase 1+ product UI.
- Alpine is for local UI state: modal, drawer, dropdown, preview.
- Livewire is for server state: forms, voting, comments, filtering.


## Media storage rules

- Media is stored/resolved through MediaStorage/MediaUrlResolver (config/media.php), never
  through Storage:: calls in models, Blade, or API resources — see docs/architecture/media.md.
- Moving to S3/CDN/another disk is a `config/filesystems.php` disk change, not a code change.
  There is no "driver" concept to switch — do not reintroduce one (e.g. a cloudinary driver).
- One disk is configured, `local`-backed (MEDIA_PUBLIC_DISK=public) in .env.example — there is
  no dedicated private-disk flow yet; do not reintroduce MEDIA_PRIVATE_DISK without a real
  consumer.

## Forbidden without separate task
- Adding React/Vue/Inertia.
- Adding Redis.
- Migrating to PostgreSQL.
- Adding external APIs.
- Adding large UI redesign.
- Changing auth stack.
- Changing GitFlow strategy.

## Definition of Done
- Task scope is respected.
- Tests pass.
- Code is formatted.
- No unrelated files changed.
- Acceptance criteria are satisfied.
