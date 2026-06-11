# Follow Authors

Phase 52 introduces user-to-user following. This document explains the design.

## What is Follow

A user can follow an author to receive in-app notifications when the author publishes new posts.

Follow is:
- User-to-user (follower follows author)
- Not a vote, not a saved post, not a subscription
- Not an activity feed subscription
- Not a payment

## What Follow is Not

- Follow is **not** a saved post. Use `PostSave` for saving posts.
- Follow is **not** a vote. Use voting actions for votes.
- Follow does **not** grant access to private content.
- Follow does **not** create a public activity feed.
- Follow does **not** send email or push notifications.

## Data Model

Table: `follows`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | |
| follower_id | FK users.id | user who follows |
| author_id | FK users.id | user being followed |
| created_at | timestamp | |
| updated_at | timestamp | |

Constraints:
- `unique(follower_id, author_id)` — one follow per pair
- `index(author_id, created_at)` — query followers of an author
- `index(follower_id, created_at)` — query who a user follows
- Cascading deletes on both FKs

## Rules

- A user cannot follow themselves (`follower_id != author_id`)
- A user cannot follow a banned/non-active author
- Follow is idempotent — calling follow twice creates one row
- Unfollow is idempotent — unfollowing when not following is a no-op

## Feature Flag

`show_follow_buttons` controls follow visibility:
- When disabled: follow UI is hidden, backend actions throw `FollowFeatureDisabledException`
- Notifications are not generated when feature is disabled (action throws before persist)

## Actions

- `FollowAuthorAction` — creates follow, validates rules
- `UnfollowAuthorAction` — deletes follow, idempotent
- `ToggleFollowAuthorAction` — delegates to follow/unfollow, returns `ToggleFollowAuthorResult`
- `NotifyFollowersAboutNewPostAction` — notifies followers on post publish

## FollowState Service

`FollowState::forViewerAndAuthors(?User $viewer, iterable $authors): FollowStateMap`

Loads all follow states for a set of authors with a single query. Safe for guest viewer (returns all false).

## Notification

`FollowedAuthorPostedNotification` — database channel only.

Triggered:
- When a trusted user creates a post (published immediately via `CreatePostAction`)
- When a moderator approves a post (via `ApprovePostAction`)

Not triggered:
- Draft creation
- Pending post creation
- Rejected/hidden posts
- Post edits

Deduplication: checks existing notifications before sending to prevent duplicates.

## Notification Preference

Column: `users.notify_followed_author_posts` (boolean, default true)

UI: profile settings page → Notifications section.

## Counts

Profile page shows:
- `followers_count` — number of users following this author
- `following_count` — number of authors this user follows

No public followers/following list pages in Phase 52.

## Translations

Keys in `lang/{locale}/follows.php`:
- `follow`, `following`, `unfollow`, `followers`, `following_count`
- `login_required`, `cannot_follow_self`, `cannot_follow_author`, `feature_disabled`
- `notifications.followed_author_posted`, `notifications.preference_label`, `notifications.preference_description`

Locales: `en`, `ru`, `bg`.

## What is Explicitly Not in Phase 52

- Algorithmic feed
- Recommendations or "top authors"
- Public followers/following list pages
- Activity feed
- Email notifications
- Push notifications
- Paid subscriptions
- Creator analytics
- Private messaging
- React/Vue/Inertia
- API endpoints for follows
