# Phase 53 — Profile 2.0 Review Checklist

Version: 1.0

---

## Summary

Phase 53 implements Profile 2.0 for RateGuru. The profile experience is now complete with tabs, privacy controls, avatar upload, and rating activity visibility.

All saved posts are **private by default**. Rating activity is **private by default**.

---

## Implementation Checklist

### Foundation

- [x] Profile audit completed (`docs/profile/phase-53-profile-audit.md`)
- [x] Privacy rules documented (`docs/profile/profile-privacy-rules.md`)
- [x] Profile fields added to users table (`display_name`, `bio`, `avatar_path`, `profile_website_url`, `rating_activity_visibility`)
- [x] `rating_activity_visibility` defaults to `'private'`
- [x] `ProfileActivityVisibility` enum exists with `Private` and `Public` cases
- [x] `ProfileValidationRules` class exists with display_name/bio/url/visibility rules

### Edit Profile

- [x] `EditProfileForm` Livewire component exists
- [x] Avatar upload works through safe validation + public disk storage
- [x] Avatar upload validates image type and max size
- [x] Old avatar deleted on replacement
- [x] Edit profile form integrated into `profile.edit` page

### Profile Services

- [x] `UserPublicProfilePresenter` exists and exposes no private fields
- [x] `ProfileStats` service exists with public + owner-only stats
- [x] `UserPublicPostsQuery` exists, returns only published posts
- [x] `UserRatingActivityQuery` exists with privacy-aware viewer check

### Profile UI

- [x] Profile page layout refactored with tabs
- [x] Profile header component (`x-profile.header`) exists
- [x] Profile tabs: Posts / Activity / Saved implemented
- [x] Posts tab shows public posts only
- [x] Saved tab is owner-only (tab hidden from non-owners)
- [x] Activity tab respects `rating_activity_visibility` privacy
- [x] Bio and website shown in profile header
- [x] `display_name` used as primary display name, falls back to `name`/`username`
- [x] Avatar from `avatar_path` (uploaded) or `avatar_url` (external)

### Privacy Guards

- [x] No public saved posts leak — saved tab only renders for owner
- [x] No public detailed rating activity by default — private visibility hides activity
- [x] Hidden/rejected posts not shown in any public tab
- [x] No followers/following directory pages added

### i18n

- [x] `lang/en/profile.php` exists with all profile keys
- [x] `lang/ru/profile.php` exists with all profile keys
- [x] `lang/bg/profile.php` exists with all profile keys
- [x] Profile UI uses `__('profile.*')` translation keys

### Tests

- [x] Migration tests pass (`ProfileFieldsOnUsersTest`)
- [x] Enum tests pass (`ProfileActivityVisibilityTest`)
- [x] Validation tests pass (`ProfileValidationRulesTest`)
- [x] Edit profile form tests pass (`EditProfileFormTest`)
- [x] Avatar upload tests pass (`AvatarUploadTest`)
- [x] Presenter tests pass (`UserPublicProfilePresenterTest`)
- [x] Stats service tests pass (`ProfileStatsTest`)
- [x] Public posts query tests pass (`UserPublicPostsQueryTest`)
- [x] Rating activity query tests pass (`UserRatingActivityQueryTest`)
- [x] Profile layout tests pass (`ProfilePageLayoutTest`)
- [x] Profile header component tests pass (`ProfileHeaderComponentTest`)
- [x] Profile tabs tests pass (`ProfileTabsTest`)
- [x] Posts tab tests pass (`ProfilePostsTabTest`)
- [x] Saved tab privacy tests pass (`ProfileSavedTabTest`)
- [x] Activity tab tests pass (`ProfileActivityTabTest`)
- [x] Translation key tests pass (`ProfileTranslationKeysTest`)
- [x] Theme token tests pass (`ProfileThemeTest`)
- [x] Browser smoke tests exist (`ProfileBrowserTest`)

---

## Not In Scope (Phase 53)

The following were explicitly excluded:

- Public followers/following directory pages
- Algorithmic activity feed
- Recommendations
- Direct messages
- Badges/achievements
- Creator monetization
- Profile comments/wall
- Private accounts
- Block/mute system
- External social links verification
- Public saved posts
- Public detailed vote history by default
- API endpoints
- React/Vue/Inertia

---

## Privacy Summary

| Data | Visibility |
|------|-----------|
| username, display_name, avatar, bio, website | Public |
| public posts | Public |
| followers_count, following_count | Public |
| saved posts | **Owner only** — never public |
| rating activity | **Private by default** — owner sees always, others see only if `visibility = public` |
| notification preferences | Never public |
| email, password | Never public |
| hidden/rejected posts | Never public |
| moderation data | Never public |
