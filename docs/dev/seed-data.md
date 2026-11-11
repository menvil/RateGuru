# RateGuru Seed Data

## Local reset

Run a fresh local database with the compact demo dataset:

```bash
php artisan migrate:fresh --seed
```

To review a non-generic preset with demo content, apply it explicitly after the
demo seed. `--force` is required because the seed has already created posts:

```bash
php artisan migrate:fresh --seed
php artisan rateguru:setup nature --force
```

This preserves demo users and posts while replacing settings, rating
configuration, and tags. Existing votes remain attached to their archived
options.

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

## Demo game

To try Growth → Games locally, build the demo game after the large dataset — its
rounds need posts with enough votes to be decided:

```bash
php artisan db:seed --class=DemoFillSeeder
php artisan db:seed --class=GamesDemoSeeder
```

`GamesDemoSeeder` builds "Trust Your Taste" the way an administrator would:
five crowd rounds, each the least liked against the best liked of the published
posts left with at least 10 votes, the suggested results, the Result First
flow; it publishes V1, gives it all the traffic of both audiences, makes the
game live and switches games on in Project settings — creating the settings
row from the defaults if there is none. Play it at `/games/trust-your-taste`.

It runs once: when the game exists it changes nothing, and without enough
decided posts it makes nothing and says why. It is not called by
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
