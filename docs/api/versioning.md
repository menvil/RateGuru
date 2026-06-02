# RateGuru API Versioning

## Phase 40 status

Phase 40 does not expose versioned endpoints.

## Future route structure

When real API endpoints are introduced, use:

```txt
/api/v1/...
```

Example future route group:

```php
Route::prefix('v1')
    ->name('api.v1.')
    ->group(function () {
        // future endpoints
    });
```

## Compatibility rules

- Do not remove fields from v1 responses without a new version.
- Adding nullable fields is usually allowed.
- Changing field meaning requires a new version.
- Changing auth requirements requires an explicit migration note.
- Write endpoints must be versioned from the first release.
- API backward compatibility is required for any released API version.

## Resources

Phase 40 resources live under:

```txt
App\Http\Resources\Api
```

If API v1 is formally released and needs stable contracts, move or alias resources to:

```txt
App\Http\Resources\Api\V1
```

## Not implemented in Phase 40

- no `/api/v1` endpoints
- no API controllers
- no OpenAPI spec
- no version negotiation
