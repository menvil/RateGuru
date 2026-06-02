# SQLite Backup Strategy

## Scope

This note is for MVP deployments that use SQLite.

## Database file

Default path:

```txt
database/database.sqlite
```

Confirm the actual production path from `DB_DATABASE`.

## Simple cold backup

Stop writes or put the app in maintenance mode, then copy:

```bash
cp database/database.sqlite backups/database-$(date +%Y%m%d-%H%M%S).sqlite
```

## SQLite online backup

Use the sqlite shell:

```bash
sqlite3 database/database.sqlite ".backup 'backups/database-$(date +%Y%m%d-%H%M%S).sqlite'"
```

## Before migrations

Always create a backup before:

```bash
php artisan migrate --force
```

## Restore

```bash
cp backups/database-YYYYMMDD-HHMMSS.sqlite database/database.sqlite
php artisan optimize:clear
```

## Risks

- SQLite is one file; losing it loses all data.
- Backups must be copied off-server.
- File permissions must be checked after restore.
- Test restore regularly.
