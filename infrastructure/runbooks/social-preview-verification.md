# Social preview deployment verification

RateGuru validates social-sharing URLs as part of deployment configuration.

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
