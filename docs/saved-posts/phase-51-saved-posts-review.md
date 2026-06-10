# Phase 51 — Saved Posts Review Checklist

## Data Layer

- [x] `post_saves` table exists with `id`, `user_id`, `post_id`, `created_at`, `updated_at`
- [x] `unique(post_id, user_id)` constraint exists
- [x] `index(user_id, created_at)` exists
- [x] `index(post_id)` exists
- [x] `PostSave` model exists with `user()` and `post()` relations
- [x] `PostSaveFactory` exists
- [x] `User::postSaves()` (hasMany) relationship exists
- [x] `User::savedPostItems()` (belongsToMany Post) relationship exists
- [x] `Post::saves()` (hasMany) relationship exists
- [x] `Post::savedByUsers()` (belongsToMany User) relationship exists

## Actions

- [x] `SavePostAction` exists — checks feature flag, checks published, firstOrCreate
- [x] `UnsavePostAction` exists — checks feature flag, idempotent delete
- [x] `ToggleSavedPostAction` exists — composes Save + Unsave, returns `ToggleSavedPostResult`
- [x] Saving is idempotent
- [x] Unsaving is idempotent
- [x] Unpublished posts cannot be saved
- [x] Feature flag disabled blocks backend actions

## Services

- [x] `SavedPostState` service exists
- [x] `SavedPostState::forUserAndPosts()` loads state in a single query (N+1 safe)
- [x] `SavedPostState::forUserAndPost()` single post check
- [x] N+1 regression test exists

## UI

- [x] `SavePostButton` Livewire component exists
- [x] Save button uses `ToggleSavedPostAction`
- [x] Save button uses `SavedPostState` for initial state
- [x] Save button gated by `show_saved_posts` feature flag
- [x] Save button integrated into post card
- [x] Save button integrated into post show
- [x] Save button integrated into post drawer
- [x] All UI locations gated by feature flag

## Page & Navigation

- [x] `SavedPostsQuery` exists — private, user-scoped, published-only, ordered by saved_at desc
- [x] `/saved` route exists (`saved-posts.index`)
- [x] Route requires auth (redirects guests to login)
- [x] `SavedPostsPage` Livewire component exists
- [x] Empty state exists (`data-testid="saved-posts-empty-state"`)
- [x] Navigation entry in sidebar (feature flag gated, auth-only)
- [x] Navigation entry in user dropdown (feature flag gated, auth-only)

## Privacy

- [x] Saved list is private
- [x] Guest cannot access `/saved`
- [x] Other user cannot see owner's saved posts
- [x] Privacy tests exist

## i18n

- [x] Translation files exist for en, ru, bg
- [x] All 11 keys present in each locale: save, saved, unsave, save_post, saved_posts, page_title, empty_title, empty_description, login_required, feature_disabled, post_unavailable
- [x] UI uses translation keys (no hardcoded English strings)

## Mobile & Theme

- [x] Save button uses rg- theme token classes
- [x] Saved page has mobile-safe container
- [x] No horizontal overflow
- [x] Empty state renders on mobile

## Tests

- [x] Database schema tests
- [x] Factory tests
- [x] Relationship tests
- [x] SavePostAction tests
- [x] UnsavePostAction tests
- [x] ToggleSavedPostAction tests
- [x] SavedPostState service tests
- [x] N+1 regression test
- [x] SavePostButton component tests
- [x] Post card feature flag tests
- [x] Post show feature flag tests
- [x] Post drawer feature flag tests
- [x] SavedPostsQuery tests
- [x] SavedPostsPage tests
- [x] Navigation tests
- [x] Privacy tests
- [x] Translation tests
- [x] Mobile tests
- [x] Browser smoke tests

## Not Implemented (Out of Scope)

- Public favorites
- Public saved counts
- Collections/folders
- Bookmarks with notes
- Share saved list
- Recommendations based on saved posts
- Notifications
- Follow authors
- Profile 2.0 redesign
- API endpoints
- React/Vue/Inertia

## Phase 51 Completion

All RG-815 through RG-834 tasks completed. Ready for release branch.

```bash
git checkout -b release/v0.3.8-phase51-favorites-saved-posts
```
