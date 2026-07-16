# Database support

## Supported runtime

SQLite is the only supported runtime database for RateGuru. Local development,
the application test suite, and the current production runbook all use SQLite.
Query behavior, locking assumptions, and application-level database semantics
are certified on SQLite only.

The default must remain `DB_CONNECTION=sqlite` in `.env.example`, and PHPUnit
must run with the SQLite connection. Changing the supported runtime database is
a product and operations decision, not a transparent implementation detail.

## CI migration smoke checks

The MariaDB and PostgreSQL jobs are migration smoke checks. They verify that a
fresh schema can be migrated and seeded and that the best-effort rollback path
can run on those engines. They do not certify application query semantics. They
also do not run the application test suite or certify transaction behavior,
search behavior, or production readiness on either database.

Keeping these jobs is useful because it exposes avoidable schema portability
problems early. Their presence must not be represented as supported runtime
compatibility.

## Query expressions

Raw expressions are isolated in the approved Query Objects documented in
[HTTP and database boundaries](http-and-database-boundaries.md). Their semantic
tests run on SQLite. Expressions should use portable SQL when this does not
make the SQLite implementation less correct or less efficient, but portability
alone does not expand the support contract.

## Adding another supported database

Supporting MariaDB or PostgreSQL requires a separate task that includes:

- a production deployment and data-migration design;
- the same semantic query tests on SQLite and the candidate database;
- no driver-specific assertions at the public behavior boundary;
- transaction, locking, JSON, collation, case-sensitivity, and timestamp tests;
- backup, restore, observability, rollback, and staging rehearsal updates;
- an explicit update to this document, `.env.example`, and the production
  checklist.

Until those acceptance criteria are complete, non-SQLite database jobs remain
migration smoke checks only.
