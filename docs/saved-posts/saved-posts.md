# Saved Posts

## Overview

Users can save posts they want to return to later. Saved posts are **private** — only the owner can view their saved list.

## Key Rules

### Saved posts are private by default

Only the authenticated owner can view their saved posts. There is no public saved list, no public saved count, and no way to see another user's saved posts.

### Saved posts are not votes

Saving a post does not affect vote counts, hot score, or rating distributions. It is a personal bookmark.

### Feature flag: show_saved_posts

All saved posts UI is gated behind the `show_saved_posts` feature flag. When disabled:
- Save buttons are hidden from post card, post show, post drawer
- Navigation entry is hidden
- Backend actions throw `SavedPostsDisabledException`

## Data Model

Table: `post_saves`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | primary key |
| user_id | foreignId | cascade delete |
| post_id | foreignId | cascade delete |
| created_at | timestamp | |
| updated_at | timestamp | |

Constraints:
- `unique(post_id, user_id)` — one save per user per post
- `index(user_id, created_at)` — for efficient user saves ordered by date
- `index(post_id)` — for efficient deletion cascade

## Actions

### SavePostAction

- Checks feature flag
- Checks post is published
- Uses `firstOrCreate` (idempotent)

### UnsavePostAction

- Checks feature flag
- Deletes where user_id + post_id (idempotent)

### ToggleSavedPostAction

- Composes Save + Unsave actions
- Returns `ToggleSavedPostResult { isSaved: bool }`

## Services

### SavedPostState

Batch-loads saved state for a collection of posts in a single query. Use `forUserAndPosts($user, $posts)` to avoid N+1 on feed pages.

### SavedPostsQuery

Paginates the authenticated user's saved posts, ordered by saved date descending. Only returns published posts.

## UI Locations

- **Post card** — compact bookmark icon (feature flag gated, auth-only)
- **Post show** — bookmark button in action row (feature flag gated, auth-only)
- **Post drawer** — bookmark button in action row (feature flag gated, auth-only)
- **Sidebar** — "Saved posts" nav link (feature flag gated, auth-only)
- **User dropdown** — "Saved posts" link (feature flag gated, auth-only)
- **Saved page** — `/saved` route, shows user's saved posts privately

## Route

```http
GET /saved → saved-posts.index (middleware: auth)
```

## Not included in Phase 51

- Public favorites or public saved counts
- Collections or folders
- Shared saved lists
- Recommendations based on saved posts
- Follow authors
- API endpoints
- Notifications
