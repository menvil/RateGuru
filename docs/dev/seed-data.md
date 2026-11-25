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

## Demo traffic and game analytics

To look at Growth → Traffic sources and a game's Analytics, Value and Traffic
on figures, add the tracker's sources and then two months of play on top of the
demo game:

```bash
php artisan db:seed --class=TrafficDemoSeeder
php artisan db:seed --class=GameAnalyticsDemoSeeder
```

`TrafficDemoSeeder` adds PopAds, ExoClick and a banner from their presets, a few
campaigns and six weeks of visits. `GameAnalyticsDemoSeeder` then plays the
demo game's history through the game's and the tracker's own actions, the clock
set back to each moment: three versions sharing the traffic period after
period, anonymous and logged-in players, answers, results, emails, shares and
exits, attempts on the visits they came on across ten more ad networks and a
few banners, sign-ups confirmed, never confirmed and still waiting, and
sign-ins to existing accounts. It rewrites what happened after each of
`TrafficDemoSeeder`'s clicks, so the two agree, and takes about five minutes.
Both run once and are not called by `DatabaseSeeder`; if the history run stops
halfway, start again from `migrate:fresh`.

## Demo site visitors

To look at Growth → Analytics with the whole site's visitors in it, add two
months of them on top of the game's history:

```bash
php artisan db:seed --class=AnalyticsDemoSeeder
```

`AnalyticsDemoSeeder` switches on Record visits and Record site visits, then
plays sixty days of people arriving on the feed or a post: from Reddit through
a tagged thread and plain links, from Google's results and its tagged ads,
from Facebook with and without a tagged promotion, from the weekly
newsletter's tagged links, from Bing, a food blog and a forum, and from
nowhere. They read posts in the overlay and share some, go on from the feed to
the game, sign up there or on the feed — confirming at once, minutes later or
never — and the new accounts come back on the days after to vote, comment,
post and play, which activates some on days 1 to 7; members of the site come
back signed in too. Only posts already published at the moment are read, and
visitors go on from the feed to the game only from the day the game's current
traffic split began — after `GameAnalyticsDemoSeeder`, the last week for
visitors signed out and the last twenty days for members — since the game's
page gives the version by the split open now. Every visit goes through the
tracker's own actions with the token the middleware would print, so sessions,
source and campaign keys, tags and landings are written as live capture
writes them. It runs after `GameAnalyticsDemoSeeder`, adds nothing once the
site's own pages have visits, takes a minute or two and is not called by
`DatabaseSeeder`. Each visit is kept or undone whole, but a run stopped
halfway leaves half a history: start again from `migrate:fresh`.

## Demo multilingual site

To look at the feed and the threads with the viewer's language first
(`docs/architecture/language-ranking.md`), play a month of a multilingual
site, best into a database of its own:

```bash
DB_DATABASE=rateguru2_lang php artisan migrate:fresh --seed
DB_DATABASE=rateguru2_lang php artisan db:seed --class=LanguageDemoSeeder
```

`LanguageDemoSeeder` adds 300 members who write in a dozen languages —
English the most, Russian next, a long tail after — each account holding its
language, a browser's guess at it, English instead now and then, or none at
all. Over thirty days they post about ten posts a day in their own language,
with a picture made for each, and comment and vote, mostly among their own;
some titles and comments tell no language ("wow 😍", names). The texts come
from `database/seeders/data/language-demo-texts.json`. Every post, comment and
vote goes through the action the site uses, the clock set back to its moment,
so each text gets its language as it would on the site and its check waits
for OpenAI; at the end each post's language is worked out and the hot scores
brought up to date. `BusyLanguageDemoSeeder` plays a hundred posts a day among
1,000 members and takes several minutes. Then switch on Project settings →
Language ranking → Rank posts by language, and sign in as `langdemo1@demo.test`
… `langdemo300@demo.test` (password `password`), or switch the language in the
header as a guest. Neither seeder is called by `DatabaseSeeder`; run again,
they add nothing.

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
