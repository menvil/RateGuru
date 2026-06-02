# Post List Cache Placeholder

## Phase 41 status

Real feed cache is not enabled in Phase 41.

`App\Support\Cache\PostListCacheManager` defines the future cache contract:

- `remember()` returns the callback result immediately.
- `invalidateForPost()` is a safe no-op.
- `keyForFeed()` builds stable feed keys from filters.

## Future cache keys

Post list cache keys should use the `post-list:feed` prefix and include normalized filters:

```txt
post-list:feed:page=1:perPage=12:search=pasta:sort=newest:tag=italian
```

## Future invalidation triggers

Invalidate affected post lists after:

- vote changes
- comment create/delete/restore
- post create/update/delete
- moderation status changes
- tag changes

## Constraints

- Redis is not required in Phase 41.
- `Cache::tags()` is not allowed as an MVP dependency.
- Do not enable real feed caching until keys, invalidation, and driver support are explicitly implemented.
