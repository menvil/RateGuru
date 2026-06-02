# Phase 41 Performance Basics Review

- FeedQuery eager-loads user.
- FeedQuery avoids N+1 for user relationship.
- FeedQuery eager-loads tags.
- FeedQuery avoids N+1 for tags.
- Vote counts use aggregate attributes and do not load full vote relations.
- Pagination max guard exists.
- Image size guard exists.
- Post list cache placeholder exists.
- Vote invalidation placeholder is called after successful votes.
- Redis is not required.
