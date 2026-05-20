# Phase 23 Filament Admin Foundation Review

Phase: **Phase 23 — Filament Admin Foundation**
Tasks: **RG-444 → RG-450**
Release: `v0.2.4-phase23-filament-admin-foundation`

## Checklist

### HTTP contract for `/admin`

- [x] `GET /admin` as **guest** → `302` redirect to `route('filament.admin.auth.login')` (`/admin/login`)
- [x] `GET /admin` as **normal active user** → `403`
- [x] `GET /admin` as **active moderator** → `200`
- [x] `GET /admin` as **active admin** → `200`
- [x] `GET /admin` as **banned moderator** → `403`
- [x] `GET /admin` as **banned admin** → `403`
- [x] `GET /admin` does NOT return `404` (panel mounted)

### Access rule

- [x] `User implements Filament\Models\Contracts\FilamentUser`
- [x] `User::canAccessPanel(Panel $panel)` returns `false` for any panel whose
      id is not `admin` (locks against future multi-panel mistakes)
- [x] For panel id `admin`: returns `true` iff
      `status === UserStatus::Active && (isAdmin() || isModerator())`

### Dashboard content

- [x] `GET /admin` as admin/moderator renders the string `RateGuru Admin`
- [x] `GET /admin` as admin/moderator renders the string
      `Moderation and content tools will appear here`
- [x] No real widget queries the database in this phase

### Navigation & branding

- [x] Navigation group names are defined exactly once in
      `app/Filament/Support/AdminNavigationGroup.php`
      (`CONTENT`, `MODERATION`, `USERS`, `TAXONOMY`, `SYSTEM`)
- [x] `GET /admin` as admin renders the string `RateGuru` (brand name)
- [x] Primary palette in `AdminPanelProvider` is `Color::Purple`

### Scope guard

- [x] No `app/Filament/Resources/*.php` files created in this phase
- [x] No moderation dashboard widgets created in this phase

### Build & tests

- [x] `vendor/bin/pest` — full suite green
- [x] `npm run build` — green
- [x] `php artisan migrate:fresh` — green

## Notes

### Access model

`User` implements `Filament\Models\Contracts\FilamentUser`. `canAccessPanel(Panel $panel)`
returns `true` only when `status === UserStatus::Active` and the role is `Admin` or
`Moderator`. This is enforced at the model layer — there is no `/admin`-only middleware
hack — so Filament's Livewire endpoints inherit the same access rule for free.

### Dashboard

`App\Filament\Pages\Dashboard` extends Filament's default `Dashboard` and renders
`resources/views/filament/pages/dashboard.blade.php` with placeholder copy. No real
widgets are queried in this phase; that lands with the moderation dashboard in Phase 29.

### Navigation groups

`App\Filament\Support\AdminNavigationGroup` exposes the canonical group names
(Content, Moderation, Users, Taxonomy, System). Future resources reference these
constants instead of hardcoding strings.

### Branding

Set via `->brandName('RateGuru')` on the panel together with a Purple primary color
palette. No custom theme build, no fake logo asset — those are out of scope for Phase 23.

## Out of scope (Phase 24+)

- `PostResource`, `UserResource`, `CommentResource`, `ReportResource`, `TagResource`
- Filament table actions / forms / bulk moderation
- Ban / shadowban UI, report resolution UI
- Moderation dashboard widgets with real data
- Filament Shield / permissions package
- Custom Filament theme build, multi-panel architecture, MFA
