# RateGuru Seed Data

## Local reset

Run a fresh local database with the full demo dataset:

```bash
php artisan migrate:fresh --seed
```

If the schema is already migrated and you only need to rerun seeders:

```bash
php artisan db:seed
```

## Demo accounts

Admin:

```txt
Email: admin@rateguru.test
Password: password
```

Moderator:

```txt
Email: moderator@rateguru.test
Password: password
```

## Dataset summary

- normal users
- trusted, banned, and shadowbanned users
- tags
- published posts
- pending posts
- hidden posts
- comments
- post votes
- configurable rating votes
- reports for posts and comments
- demo admin account
- demo moderator account

## Safety

These credentials are local-only. Do not seed demo accounts in production.

The demo seeders are guarded to run only in `local` and `testing` environments.
Do not remove that guard without a separate production-data task.
