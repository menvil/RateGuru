# Social preview deployment verification

RateGuru validates social-sharing URLs in two stages.

## Deployment configuration check

`infrastructure/scripts/deploy` runs:

```bash
php artisan rateguru:sharing:verify --expected-host=PUBLIC_HOSTNAME
```

The deployment stops before the current symlink is switched when:

- `APP_URL` is empty;
- `APP_URL` is not HTTPS;
- its hostname does not match the deployment environment;
- the public image disk uses another hostname;
- canonical or fallback Open Graph URLs do not use the expected HTTPS host;
- the PHP GD extension is unavailable.

## External crawler smoke test

The staging and production workflows run
`infrastructure/scripts/social-preview-smoke` after deployment when the
environment-level `SOCIAL_SMOKE_POST_URL` variable is configured. Set it to a
stable published post URL for that environment.

The script requests the page as `facebookexternalhit`, verifies canonical and
Open Graph URLs, then downloads the referenced image and requires an HTTP 200
JPEG or PNG response.

Staging currently uses Basic Auth. Until a public social-preview route exists,
configure the environment secret `SOCIAL_SMOKE_BASIC_AUTH` as `username:password`
to verify the protected response. The script prints a warning in this mode
because the authenticated check does not make the page accessible to real
Facebook or X crawlers.
