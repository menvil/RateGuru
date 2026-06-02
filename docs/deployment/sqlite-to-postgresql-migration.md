# SQLite to PostgreSQL Migration Note

## Phase 42 status

This is a strategy note. Migration from SQLite to PostgreSQL is not implemented in Phase 42.

## When to migrate

Consider PostgreSQL when:

- concurrent writes grow;
- SQLite write locks become visible;
- database file size grows materially;
- backup and restore needs become more serious;
- analytics queries become heavier;
- production uptime requirements increase.

## High-level migration path

1. Put app in maintenance mode: `php artisan down`.
2. Freeze schema changes.
3. Backup SQLite.
4. Create PostgreSQL database.
5. Configure `.env` for PostgreSQL in staging.
6. Run Laravel migrations on PostgreSQL.
7. Export data from SQLite.
8. Import data into PostgreSQL.
9. Verify row counts and critical relations.
10. Run test suite against PostgreSQL.
11. Run browser smoke tests.
12. Run visual screenshots if UI data changed.
13. Complete a staging rehearsal.
14. Rehearse rollback.
15. Bring app back up: `php artisan up`.
16. Schedule production cutover.

## Things to verify

- enum/string compatibility;
- JSON columns;
- timestamps and timezones;
- foreign keys;
- indexes;
- full-text/search behavior if used;
- case sensitivity;
- unique constraints;
- file/image paths;
- admin user access.

## Rollback

Keep the SQLite backup untouched until PostgreSQL is verified.

Rollback must restore application config and the SQLite database file.

## Not implemented in Phase 42

- no automated conversion script;
- no production cutover;
- no PostgreSQL requirement;
- no Docker/Postgres service setup.
