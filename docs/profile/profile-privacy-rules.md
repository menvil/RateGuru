# Profile Privacy Rules

Version: 1.0 — Phase 53

---

## Principle

Profile data is **private by default** unless explicitly listed as public.

---

## Public Data

Visible to any visitor (authenticated or guest):

| Field | Notes |
|-------|-------|
| `username` | Always public |
| `display_name` | Always public |
| `avatar` | Always public |
| `bio` | Always public |
| `profile_website_url` | Always public |
| `public posts` | Only `status = published` posts |
| `followers_count` | Public stat |
| `following_count` | Public stat |
| `public_posts_count` | Public stat |

---

## Owner-Only Data

Visible only to the authenticated profile owner:

| Field | Notes |
|-------|-------|
| `saved posts` | Private by default — owner-only tab. Never a public signal. |
| `saved_posts_count` | Owner-only stat |
| `rating activity` | Private by default. Owner sees full history. |
| `ratings_given_count` | Owner-only stat |
| `rating_activity_visibility` | Setting itself is private |
| `notify_followed_author_posts` | Notification preference — never shown publicly |
| Account settings | Email, password, locale, theme — never shown publicly |

---

## Never Public

Regardless of any setting, the following are never exposed on a public profile:

| Data | Reason |
|------|--------|
| Reports filed by or against user | Moderation data |
| Moderation actions | Admin/moderator data |
| Hidden / rejected posts | Not public content |
| Detailed vote history by default | Sensitive social signal |
| Email address | Private account data |
| Password | Security |
| Internal IDs, trust levels | Internal data |
| Notification data payloads | Private system data |

---

## Rating Activity Privacy

Rating/voting activity is **private by default**.

```
rating_activity_visibility = 'private'   (default)
rating_activity_visibility = 'public'    (user opt-in)
```

Future extension (not in Phase 53):
```
rating_activity_visibility = 'followers_only'
```

Rules:
- Owner always sees own rating activity.
- Other users only see activity if `rating_activity_visibility = 'public'`.
- Even when public, activity only shows votes on **published** posts (never hidden/rejected).
- Switching visibility to `private` immediately hides activity from public view.

---

## Saved Posts Privacy

Saved posts are **always private**. There is no visibility setting for saved posts.

- Owner sees Saved tab with their saved posts.
- Any other user (or guest) must receive no indication of saved posts.
- Saved count is **not** a public social signal.
- This rule was established in Phase 51 and must never be relaxed without a new explicit task.

---

## Public Posts

Only posts with `status = published` appear on public profile.

Posts with the following statuses are never shown publicly:
- `pending`
- `hidden`
- `rejected`
- `draft` (if applicable)

---

## Implementation Checklist

- [ ] `ProfileStats::forUser()` with viewer param hides owner-only stats from others
- [ ] `UserPublicPostsQuery` filters by `published` status only
- [ ] `UserRatingActivityQuery` checks `rating_activity_visibility` and viewer
- [ ] Profile tabs: Saved tab never renders for non-owner
- [ ] Profile tabs: Activity tab only renders if `public` or viewer is owner
- [ ] `UserPublicProfilePresenter` exposes no private fields
- [ ] No API endpoint ever exposes saved posts or private rating activity
