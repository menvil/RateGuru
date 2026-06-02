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

1. Freeze schema changes.
2. Backup SQLite.
3. Create PostgreSQL database.
4. Configure `.env` for PostgreSQL in staging.
5. Run Laravel migrations on PostgreSQL.
6. Export data from SQLite.
7. Import data into PostgreSQL.
8. Verify row counts and critical relations.
9. Run test suite against PostgreSQL.
10. Run browser smoke tests.
11. Run visual screenshots if UI data changed.
12. Complete a staging rehearsal.
13. Rehearse rollback.
14. Schedule production cutover.

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
