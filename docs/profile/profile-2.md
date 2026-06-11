# Profile 2.0 — Developer Reference

Phase 53 — Version 1.0

---

## Overview

Profile 2.0 adds a complete profile experience with tabs, privacy controls, avatar upload, and activity visibility settings.

---

## Profile Fields (users table)

New fields added in Phase 53:

| Field | Type | Default | Notes |
|-------|------|---------|-------|
| `display_name` | string(80), nullable | null | Overrides `name` in public display |
| `bio` | text, nullable | null | Public bio |
| `avatar_path` | string, nullable | null | Uploaded avatar via public disk |
| `profile_website_url` | string, nullable | null | Optional website URL |
| `rating_activity_visibility` | string(30) | `'private'` | Controls activity tab visibility |

Existing field `avatar_url` is kept for legacy/external avatar URLs.

---

## Avatar Upload

Avatars are stored in the `public` disk under `avatars/` directory.

- Upload via `EditProfileForm` Livewire component
- Validated as image, max size from `uploads.images.max_kilobytes` config
- Old avatar deleted on replacement
- `avatar_path` takes priority over `avatar_url` for display

Resolved via `UserPublicProfilePresenter::resolveAvatarUrl()`.

---

## Profile Tabs

The profile page has three tabs:

| Tab | Visibility | Implementation |
|-----|-----------|----------------|
| Posts | Public | `UserPublicPostsQuery::forProfile()` |
| Activity | Public (if `rating_activity_visibility = public`) or Owner | `UserRatingActivityQuery::forProfile()` |
| Saved | Owner only | `SavedPostsQuery::forUser()` |

Tab state is stored in Livewire `$tab` property with `#[Url]` attribute.

---

## ProfileStats Service

`App\Support\Profile\ProfileStats` provides `forUser(User $profileUser, ?User $viewer): ProfileStatsData`.

Public stats (always returned):
- `publicPostsCount`
- `followersCount`
- `followingCount`

Owner-only stats (null for non-owners):
- `savedPostsCount`

---

## Privacy

See `docs/profile/profile-privacy-rules.md` for full rules.

Key points:
- `rating_activity_visibility = 'private'` (default) — only owner sees activity
- `rating_activity_visibility = 'public'` — all users see activity
- Saved posts are always private (owner-only tab, no public signal)
- Hidden/rejected posts never shown publicly

---

## Key Classes

| Class | Purpose |
|-------|---------|
| `ProfilePage` (Livewire) | Profile page with tabs, stats, computed properties |
| `EditProfileForm` (Livewire) | Edit profile fields + avatar upload |
| `UserPublicProfilePresenter` | Safe public DTO, no private fields |
| `ProfileStats` | Public + owner-only stats |
| `UserPublicPostsQuery` | Published posts for profile tab |
| `UserRatingActivityQuery` | Privacy-aware rating activity |
| `ProfileValidationRules` | Validation rules for profile fields |
| `ProfileActivityVisibility` | Enum: private / public |

---

## i18n

Translation file: `lang/{en,ru,bg}/profile.php`

Key groups:
- Tab labels: `profile.posts`, `profile.saved`, `profile.activity`
- Field labels: `profile.display_name`, `profile.bio`, `profile.website`
- Privacy labels: `profile.visibility_private`, `profile.visibility_public`
- Empty states: `profile.no_posts`, `profile.no_activity`

---

## Follow Integration

Follow button and follower/following counts were added in Phase 52.

Phase 53 ensures:
- `followersCount` and `followingCount` come from `ProfileStats`
- Follow button visibility controlled by `canSeeFollowButton` computed property
- Follower stats shown in profile header stats row
