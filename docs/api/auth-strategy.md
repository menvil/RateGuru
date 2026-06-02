# RateGuru API Auth Strategy

## Phase 40 status

Phase 40 does not implement API auth and does not expose public endpoints.

## Future API categories

### Public read API

Possible future endpoints:

- published posts
- visible comments
- public user profiles
- tags

Auth:

- no auth required
- only published/public-safe data
- rate limited later

### Authenticated write API

Possible future endpoints:

- create post
- vote
- comment
- report content

Auth:

- Laravel Sanctum personal access tokens are the preferred first option
- session auth may be used only for internal app requests
- OAuth is not needed for MVP

### Admin and moderation API

Not part of the first public API phase.

Rules:

- do not expose moderation endpoints until policies and actions are stable
- require admin or moderator auth
- require audit logs
- require strict rate limits

## Phase 40 decisions

- Future public read endpoints may be unauthenticated when they expose only published/public-safe data.
- Future write endpoints must require explicit authentication.
- No API auth implementation is added in Phase 40.
- Do not install or configure a new auth package in this phase unless it already exists for another reason.
- Future write endpoints must call existing Actions.
- Future API endpoints must not bypass Policies or Actions.
