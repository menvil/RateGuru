# RateGuru Seed Data

## Local reset

Run a fresh local database with the compact demo dataset:

```bash
php artisan migrate:fresh --seed
```

If the schema is already migrated and you only need to rerun seeders:

```bash
php artisan db:seed
```

## Large local dataset

The default seed is intentionally compact. For feed pagination, ranking, comment
threads, and performance checks, run the separate large dataset after the base
seed:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoFillSeeder
```

`DemoFillSeeder` creates 500 users, 100 posts with generated images, post and
configurable rating votes, nested comments, and comment votes. It assigns a mix
of optional categories and leaves every third generated post uncategorized.

The large seeder is safe to run again on the same local database: it rebuilds
its generated interactions and media instead of accumulating duplicate rows or
orphaned `fill_post_*.jpg` files. The compact demo accounts and posts are left
untouched.

The large dataset can contain more than one million comment votes and may take
noticeably longer than the default seed. It is deliberately not called by
`DatabaseSeeder`.

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
