# Phase 23 Filament Admin Foundation Review

Phase: **Phase 23 — Filament Admin Foundation**
Tasks: **RG-444 → RG-450**
Release: `v0.2.4-phase23-filament-admin-foundation`

## Checklist

- [x] `/admin` path works
- [x] guest redirected/blocked
- [x] normal user forbidden (HTTP 403)
- [x] moderator allowed (HTTP 200)
- [x] admin allowed (HTTP 200)
- [x] banned moderator/admin forbidden even when role matches
- [x] dashboard placeholder visible (`RateGuru Admin`, `Moderation and content tools will appear here`)
- [x] navigation group names defined in one place (`AdminNavigationGroup`)
- [x] `RateGuru` branding visible in admin panel
- [x] no Filament resources created in this phase
- [x] `composer test` passes
- [x] `npm run build` passes

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
