# Phase 53 — Profile 2.0 Audit

## Overview

Audit of the current profile experience before Phase 53 improvements.

---

## 1. Profile Route

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Route | `GET /u/{username}` → `ProfilePage` Livewire | Works | — | — |
| 404 handling | `firstOrFail()` in `mount()` | Works | — | — |
| SEO meta | None | Missing title/description tags | P2 | RG-865 |

---

## 2. Profile Header

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Avatar | Uses `avatar_url` field via `<x-ui.avatar>` | No upload UI | P0 | RG-861 |
| Display name | Falls back to `name` then `username` | No `display_name` field | P0 | RG-857 |
| Username | Shows `@username` | OK | — | — |
| Bio | Missing | No `bio` field at all | P0 | RG-857, RG-860 |
| Website | Missing | No `profile_website_url` field | P1 | RG-857 |
| Join date | Missing | Not shown | P2 | RG-866 |

---

## 3. Profile Stats

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Published posts count | Present | Hardcoded label "Published posts" | P1 | RG-872 |
| Total upvotes | Present | Hardcoded label | P1 | RG-872 |
| Comments received | Present | Hardcoded label | P1 | RG-872 |
| Followers count | Present (Phase 52) | OK | — | — |
| Following count | Present (Phase 52) | OK | — | — |
| Saved posts count | Missing | Owner-only stat absent | P1 | RG-863 |
| Rating activity count | Missing | Owner-only stat absent | P2 | RG-863 |

Stats are computed inline in `getStatsProperty()` with cloned queries — not extracted to a service. Should be moved to `ProfileStats`.

---

## 4. Follow Button Integration

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Follow button | Present (Phase 52) | Placement works | — | — |
| Follow counts | Present (Phase 52) | Shown in stats | — | — |
| Mobile follow button | Present | Needs layout review on xs | P1 | RG-873 |

---

## 5. Saved Posts Integration

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Saved posts on profile | Missing | Not integrated at all | P0 | RG-869 |
| Saved tab | Missing | No tabs exist yet | P0 | RG-867 |
| Privacy guard | N/A | Must be owner-only | P0 | RG-869 |

---

## 6. Rating Activity

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Rating activity section | Missing | Not shown at all | P0 | RG-870, RG-871 |
| Privacy control | Missing | No `rating_activity_visibility` field | P0 | RG-857, RG-858 |
| Activity tab | Missing | No tabs exist | P0 | RG-867, RG-871 |

---

## 7. Tabs Layout

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Tabs | Missing | Profile is flat single-section layout | P0 | RG-867 |
| Posts tab | Posts shown inline (no tab) | Needs wrapping in tab | P0 | RG-867, RG-868 |
| Activity tab | Missing | Not implemented | P0 | RG-871 |
| Saved tab | Missing | Not implemented, owner-only | P0 | RG-869 |

---

## 8. Edit Profile Form

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Edit page | `profile.edit` (Blade, not Livewire) | Only name/email/username editable | P0 | RG-860 |
| Bio editing | Missing | No field | P0 | RG-860 |
| Website editing | Missing | No field | P1 | RG-860 |
| Display name editing | Missing | No field | P0 | RG-860 |
| Avatar upload | Missing | No upload UI | P0 | RG-861 |
| Visibility setting | Missing | No `rating_activity_visibility` | P0 | RG-860 |
| Validation | Partial | Only in `ProfileUpdateRequest` | P0 | RG-859 |

---

## 9. Mobile Layout

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Header mobile | flex-col on mobile | Works but could be improved | P1 | RG-865, RG-873 |
| Stats grid | `sm:grid-cols-3 lg:grid-cols-5` | 5-col squeeze on tablets | P1 | RG-865 |
| Posts grid | `sm:grid-cols-2 lg:grid-cols-3` | Works | — | — |
| Tabs (future) | N/A | Must not overflow on mobile | P0 | RG-867 |

---

## 10. Theme Support

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Theme tokens | Used throughout | OK — `rg-*` tokens used | — | — |
| Raw colors | None found in profile | OK | — | — |
| Dark mode | Relies on tokens | Works | — | — |

---

## 11. Translations

| Item | Current State | Issue | Severity | Target Task |
|------|--------------|-------|----------|-------------|
| Profile labels | Hardcoded English | "Posts", "Edit profile", etc. | P1 | RG-872 |
| `profile.php` lang file | Missing | No `lang/en/profile.php` | P0 | RG-872 |
| Follow labels | Use `follows.php` (Phase 52) | OK | — | — |

---

## 12. Privacy Risks

| Risk | Current State | Severity | Target Task |
|------|--------------|----------|-------------|
| saved posts exposed | Not shown on profile (safe) | P0 | RG-869 must guard |
| rating activity exposed | Not shown at all (safe) | P0 | RG-870, RG-871 must guard |
| vote history exposed | Not shown at all (safe) | P0 | Never public by default |
| hidden/rejected posts shown | Not shown (filtered via `published()` scope) | P0 | Maintained |
| notification preferences exposed | Not shown (safe) | P0 | Never on public profile |
| moderation actions exposed | Not shown (safe) | P0 | Never on public profile |
| email exposed | Not shown (safe, guarded in test) | P0 | Maintained |

---

## Summary

Phase 53 must add:
- `display_name`, `bio`, `profile_website_url`, `rating_activity_visibility` fields
- Avatar upload
- Profile tabs (Posts / Activity / Saved)
- Saved tab owner-only guard
- Rating activity with privacy control (private by default)
- `ProfileStats` service
- `UserPublicPostsQuery`
- `UserRatingActivityQuery`
- `UserPublicProfilePresenter`
- `ProfileActivityVisibility` enum
- `ProfileValidationRules`
- `EditProfileForm` Livewire component
- Profile header component
- Translation keys in `lang/*/profile.php`
- Mobile/theme polish
- Browser smoke tests
