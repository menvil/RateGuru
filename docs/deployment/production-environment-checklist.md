# Production Environment Checklist

## Application

- Set `APP_ENV=production`.
- Set `APP_DEBUG=false`.
- Set `APP_URL` to the production URL.
- Generate `APP_KEY` once and keep it stable.
- Configure `LOG_CHANNEL`.
- Confirm the production timezone.

## Database

- Set `DB_CONNECTION`.
- Confirm the SQLite path or database credentials.
- Take a backup before deployment.
- Run production migrations with `php artisan migrate --force`.
- Never run destructive reset commands against production data.

## Storage

- Confirm the public storage symlink exists.
- See [storage symlink docs](storage-symlink.md).
- Ensure `storage/app`, `storage/app/public`, `storage/logs`, and `bootstrap/cache` are writable.
- Confirm upload disk configuration.

## Security

- Serve the app over HTTPS from the web server or proxy.
- Do not run demo seeders in production.
- Do not keep demo admin credentials in production.
- Create a real admin with the admin creation command when available.

## Queues

- Decide `QUEUE_CONNECTION`.
- See [queue worker docs](queue-worker.md).
- Configure a worker if an async queue driver is used.

## Build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Verification

- Homepage/feed opens.
- Login works.
- Admin panel access works.
- Upload works.
- Logs are clean.
