# Deployment Migrations

## Production command

```bash
php artisan migrate --force
```

## Before running migrations

- take a database backup;
- for SQLite deployments, follow [SQLite backup strategy](sqlite-backup-strategy.md);
- check pending migrations;
- review destructive migrations;
- ensure deployment rollback plan exists.

## Check status

```bash
php artisan migrate:status
```

## Forbidden in production

Never run migrate:fresh in production.

Never run these commands against production data:

```bash
php artisan migrate:fresh
php artisan migrate:fresh --seed
```

## Recommended deploy order

1. Put app in maintenance mode if needed: `php artisan down`.
2. Backup database.
3. Pull or release new code.
4. Run `composer install --no-dev --optimize-autoloader`.
5. Build assets.
6. Run migrations with `php artisan migrate --force`.
7. Clear and cache config/routes/views.
8. Restart queue workers if used.
9. Bring app back up: `php artisan up`.
10. Smoke test feed, login, admin, and upload.
