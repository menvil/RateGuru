# Phase 52 — Follow Authors Review Checklist

## Data Layer

- [x] `follows` table exists with correct columns and indexes
- [x] `unique(follower_id, author_id)` constraint exists
- [x] `Follow` model exists with `follower()` and `author()` relations
- [x] `FollowFactory` exists and creates distinct users by default (no self-follow)
- [x] `User::followingRelations()` — HasMany pivot records where user is follower
- [x] `User::followerRelations()` — HasMany pivot records where user is author
- [x] `User::followingAuthors()` — BelongsToMany via follows.follower_id
- [x] `User::followers()` — BelongsToMany via follows.author_id

## Actions

- [x] `FollowAuthorAction` exists
- [x] `UnfollowAuthorAction` exists
- [x] `ToggleFollowAuthorAction` exists and returns `ToggleFollowAuthorResult`
- [x] `NotifyFollowersAboutNewPostAction` exists

## Guards

- [x] Cannot follow self — `CannotFollowSelfException` thrown
- [x] Cannot follow banned/non-active author — `CannotFollowAuthorException` thrown
- [x] Feature flag `show_follow_buttons` checked — `FollowFeatureDisabledException` thrown
- [x] Duplicate follow prevented via `firstOrCreate` (idempotent)
- [x] Unfollow is idempotent

## FollowState Service

- [x] `FollowState::forViewerAndAuthors()` loads states in single query
- [x] `FollowStateMap::isFollowing()` returns correct state
- [x] Guest viewer returns all-false state safely
- [x] N+1 test confirms query count bounded ≤2

## UI Components

- [x] `FollowButton` Livewire component exists
- [x] Renders for authenticated viewer viewing another user
- [x] Does not render for self
- [x] Does not render when feature flag disabled
- [x] Guest sees login message on toggle
- [x] Uses theme tokens (no raw colors)
- [x] `data-testid="follow-button"` stable selector present
- [x] `aria-pressed` attribute present

## Profile Integration

- [x] `FollowButton` integrated into profile header
- [x] Not shown on own profile
- [x] Not shown when feature flag disabled
- [x] `followers_count` stat card present (`data-testid="followers-count"`)
- [x] `following_count` stat card present (`data-testid="following-count"`)
- [x] No public followers/following list pages

## Post Show Integration

- [x] `FollowButton` integrated into post show author block
- [x] Not shown on own posts
- [x] Not shown when feature flag disabled
- [x] Mobile layout safe

## Notification Preference

- [x] `users.notify_followed_author_posts` column exists (boolean, default true)
- [x] Cast as boolean in User model
- [x] `NotificationPreferencesForm` Livewire component exists
- [x] Integrated into profile/edit settings page
- [x] `data-testid="notification-preference-followed-author-posts"` present

## Notifications

- [x] `FollowedAuthorPostedNotification` exists
- [x] Uses `database` channel only
- [x] Payload includes `post_id`, `post_title`, `author_id`, `author_name`, `url`, `type`
- [x] Hooked into `CreatePostAction` for trusted user immediate publish
- [x] Hooked into `ApprovePostAction` for moderated post approval
- [x] Not triggered for drafts, pending, rejected, hidden posts
- [x] Author not notified about own post
- [x] Non-followers not notified
- [x] Users with `notify_followed_author_posts = false` not notified
- [x] Duplicate notifications prevented

## Translations

- [x] `lang/en/follows.php` exists with all keys
- [x] `lang/ru/follows.php` exists with all keys
- [x] `lang/bg/follows.php` exists with all keys
- [x] UI uses `__('follows.*')` for all follow strings
- [x] `ui.settings.notifications` keys added to all locales

## Theme / Mobile

- [x] `FollowButton` uses theme tokens only (no raw colors)
- [x] `text-rg-onAccent` used for following state text
- [x] `bg-rg-accent` / `bg-rg-accentHover` used for following state
- [x] Mobile tap target ≥ 32px height
- [x] No horizontal overflow on follow profile page at 375px

## Tests

- [x] `FollowsTableTest` — schema, unique constraint
- [x] `FollowFactoryTest` — model, relations, no self-follow default
- [x] `UserFollowRelationshipsTest` — four relationships
- [x] `FollowAuthorActionTest` — follow, idempotent, self, flag, banned
- [x] `UnfollowAuthorActionTest` — unfollow, idempotent, isolation
- [x] `ToggleFollowAuthorActionTest` — toggle both directions
- [x] `FollowStateServiceTest` — multi-author, guest, single
- [x] `FollowStateNPlusOneTest` — query count bounded
- [x] `FollowButtonTest` — render, toggle, self, guest, flag
- [x] `ProfileFollowButtonTest` — profile integration
- [x] `PostAuthorFollowButtonTest` — post show integration
- [x] `ProfileFollowCountsTest` — followers/following counts
- [x] `FollowNotificationPreferenceTest` — default true, disable
- [x] `NotificationPreferencesFormTest` — save preference
- [x] `FollowedAuthorPostedNotificationTest` — payload, channel, url
- [x] `NotifyFollowersAboutNewPostActionTest` — notify, preference, self, non-follower, pending
- [x] `PreventDuplicateFollowedAuthorNotificationsTest` — dedup works
- [x] `FollowTranslationKeysTest` — all locales
- [x] `FollowButtonThemeTest` — theme tokens, aria
- [x] `FollowBrowserTest` — browser smoke tests

## Not In Phase 52

- no recommendations added
- no algorithmic feed added
- no activity feed added
- no email notifications added
- no push notifications added
- no public followers/following pages added
- no private messaging added
- no paid subscriptions added
- no API endpoints added
- no React/Vue/Inertia added
