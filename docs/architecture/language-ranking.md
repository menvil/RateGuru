# Language-aware ranking

A visitor reads the site as if it were in their own language. While Project settings → Language ranking → Rank posts
by language is on:

- the feed shows fresh posts in the viewer's language first, then fresh English posts, then the rest, each group in
  the chosen sort's own order;
- every tenth place of New and Hot on the home feed shows a strong post in another language;
- a thread shows comments in the viewer's language first.

Off — the default — every feed and thread keeps its usual order, while the languages of posts and comments are still
kept, so the feature can be switched on with them already in place.

## A post's language

A post's language is worked out from what tells it (`App\Support\Languages\LanguageProfile`). Each signal adds weight
to a language:

| Signal | Weight | Rule |
|---|---|---|
| the post's text (title and description) | 3 | only when a detector named a language; "tells none" (names, brands, "wow") adds nothing |
| the author's account language | 2 | none for an account without one (Content Studio's Personas) |
| each visible comment | 1 | its text's language as far as a detector could tell it; the rest of its weight — all of it for a comment of only emoji or names — at half to its writer's account language |
| each vote | 0.3 | the voter's account language; all the votes together count for no more than the author (2) |

The post is in the language holding at least half the weight (`posts.language`, its share in `posts.language_share`),
or in none (`null`). A post with no language is in the third group of every viewer's feed.

The weights were chosen on a simulated site of 28 languages — a labelled corpus of 3,360 texts plus 101 that tell no
language, posts, comments and votes from members whose account language was chosen, guessed from the browser or
missing — before any of this was built. Together the signals named a post's language about 98% of the time a day
after it came out; its text alone about 80%, its author alone about 60%. The cap on votes matters: uncapped, a
popular post voted on by people of every language lost its own language, and accuracy fell below what it was with no
votes at all. Those numbers rest on the simulation's assumptions — how often authors write in their account's
language, how often that language was guessed wrong, how many comments and votes a post gets — which only real posts
can confirm: `php artisan languages:report` reads them on an installation (below).

## Telling a text's language

Two readings, so a text has a language at once and a better one a minute later.

**On this server, as the text is written** — `App\Services\Languages\LocalLanguageDetector`, the efficient language
detector (ELD, `nitotm/efficient-language-detector`) on the text's words. `App\Support\Languages\TextCleaner` takes
out links, addresses, @handles, #tags, digits, emoji and the words people write the same way in many languages (ok,
lol, wow, selfie, love…) first; a text with nothing left tells no language. A text of fewer than twelve letters
counts in proportion — a one-word comment is easily mistaken for a close language. The answer is held to the
languages the site installs (`config/locales.php`), not just the ones it offers, so a Ukrainian post is never read as
Russian because Ukrainian is switched off. ELD calls Norwegian `no` and Filipino `tl`; the site's codes are `nb` and
`fil`. ELD does not know Macedonian, Indonesian, Bosnian or Montenegrin and names a neighbour for them; the second
reading corrects that. On the corpus ELD read 85% of single texts right.

The detector loads its large database in bytes mode — about 24 MB and a millisecond, once per process, and only in the
requests that write a text. ELD ships every size of its database in two formats, 172 MB; a script run after every
autoload dump (`tools/composer/prune-language-detector-data.php`) keeps the one it reads, about 21 MB, whichever
install composer runs.

**By OpenAI, in the background** — `App\Services\Languages\OpenAiLanguageDetector`, called by `languages:work`. Its
own prompt and strict response schema: the translation engine translates and nothing else
([translation-engine.md](translation-engine.md)). It uses the Translation AI account — the key, model and address in
`config('translation.providers.openai')` — sends only a text's words (`TextCleaner`), never links, handles or
addresses, asks for nothing to be stored, and reads forty texts of mixed languages a request: a batch of one language
would tell the model the language of each of its texts. It keeps close languages apart, reads transliterated text as
the language it is written in, and answers "und" for a text that tells none, which counts as no text signal. On the
corpus it read 98.4% of single texts right, the same in two runs; about $0.02 per 1,000 texts.

Each text has one check (`content_language_checks`): what the local detector said, what OpenAI said once asked, and
where the check stands — waiting, being sent, done, given up after three attempts (the local answer stands), or
skipped for a text with no words. A check goes with its post or comment (`docs/architecture/community-fk-safety.md`).

## Where languages are written

- **A new post** (`CreatePostAction`): its text read before the transaction; in `Post::create` its language from its
  text and its author; its check queued in the same transaction. Content Studio's posts and a moderator's approval go
  through the same action.
- **A new comment** (`AddCommentAction`): its language read before the transaction and written with it; its check
  queued; its post marked stale.
- **Whatever changes a post's signals later marks the post stale instead of working it out.** A vote, a comment added,
  deleted, hidden or restored, and the purge of an unconfirmed account each set `posts.language_stale_at` inside the
  one update they already make of the post: a second update of a post in one transaction makes PostgreSQL lock its
  author after it, out of the lock order ([user-lifecycle.md](user-lifecycle.md), `PostAuthorLockOrderTest`). A
  change of an author's language — chosen in the header or the profile, or let go with an account's anonymisation —
  marks their posts after the commit, in a statement of its own. A voter's change of language does not mark the posts
  they voted on: a weak signal left to drift until something else marks the post.

**`languages:work`** — every minute, in a process of its own (never on the job queue's one worker), each step on its own
time:

1. **Stale posts first** (`WorkOutPostLanguageAction`), up to 200 a run: each in a transaction of its own, the post
   locked and saved once — without touching its `updated_at` — and every signal read without a lock, so it never
   takes an account or a comment after the post. A vote that lands meanwhile waits for the post and marks it again.
2. **Then texts written before languages were kept** are read on this server, up to 500 a run, posts first and the
   newest first (`LanguageCheckQueue::readOlderTexts`): a comment given the language read, each check queued for
   OpenAI behind the texts written now, and the post marked stale — in one transaction, post first, skipped if
   another run read the text meanwhile. An installation fills its older posts and comments
   in on its own after the release that brings languages; nobody runs anything.
3. **Then the checks of new texts are asked of OpenAI** (`LanguageCheckQueue`): first attempts in batches of forty,
   texts written now before a backfill's, the newest first; a check that failed is retried alone, so a text OpenAI
   will not read no longer takes a batch down with it. An answer is written only if its claim still stands, in one
   transaction with the post marked stale: post, comment, check — the order every comment writer keeps — so no answer
   is ever kept without its post being marked. A run that finds OpenAI unreachable stops asking until the next one; a
   claim left behind by a run that died is taken up again after ten minutes, or given up after its last attempt. Nothing is asked while the setting is off, without the
   Translation AI key, or once the day's limit (3,000 checks by default, UTC) is reached.

**`languages:backfill`** — not needed, since `languages:work` takes the older texts up on its own: it reads all of
them at once and works out every post's language, for an installation that wants them now. Run again, it reads only
what it has not.

## The feed

**The viewer's language** is the one `SetLocale` serves the page in: the account's, the session's, the cookie's, the
browser's or English. The language switcher in the header changes the feed too.

**Fresh** means published since:

- for a member, the end of their previous visit, held between a day and the members' window (7 days by default):
  what came out while they were away. `TrackMemberVisit` keeps `users.last_seen_at` before the page is drawn — at
  most every five minutes a session, any request — and a gap of more than an hour starts a new visit,
  `users.previous_visit_at` keeping where the last one ended (`TouchMemberVisitAction`). Kept on the account rather
  than the session, so a second device and a tab left open for days see the visit the member is on;
- for a guest, the guests' window (7 days by default) (`App\Support\Languages\FreshWindow`).

**The order** (`FeedQuery`, one `CASE` of bound values in its `orderByRaw` boundary) puts ahead of the sort: fresh
posts in the viewer's language — and the viewer's own posts, whatever language they were read as, so a post just
published never drops out of its author's sight — then fresh English posts, then the rest. Top ranks all time, so its
groups are by language alone. In Hot, a post in the viewer's language can be lifted only above a percentile of the last
two days' hot scores (Project settings; off by default — small languages have few posts to spare).

**Where:** the home feed, a category, a tag and the rating filters. Not the Following feed nor a search: there the
viewer has already narrowed what they see.

`PostFeed` fixes the order — the language, the window, the Hot floor and the hits — when it is opened, in locked
state, so its pages never shift while the viewer looks through them.

**What changes for small languages:** a viewer whose language has a post or two a week sees them at the top of New and
Hot for as long as they are fresh — up to the window — above today's popular English posts. A viewer of English, the
biggest language, sees a post in another language mostly as a hit.

## Hits

A hit is a post of the last two days in a language other than the viewer's (or in none) whose votes and comments
gathered — `1 + max(0, up − down) + 0.5 × comments` — reach the top 5% of the week's posts, with at least three net
votes up (`FeedQuery::hitCandidates`, read in PHP, a week of posts at a time). Not the hot score: it falls so fast with
age that a post with one early vote would outrank a day-old favourite. The viewer's own posts are never hits. In the
simulation, hits picked this way were clearly stronger than by hot score, and lifted the first page's quality in New
above today's.

Hits show at every 10th place (a setting; every place is not allowed) of New and Hot on the home feed, up to twenty a
feed; never in Top — a post of the last two days is weaker than all-time neighbours — nor with a filter.
`FeedQuery::paginateWithHits` puts a page together by place, since how far into the feed a page starts depends on how
many hits came before it: the hits sit at places every, 2 × every… and the rest of the feed, without them, around them.
A hit that would sit past the end of a short feed is not placed and stays where its own order puts it, so no post
shows twice and none is left out. The places are those of the list the feed was opened with: a hit hidden since
leaves its place empty rather than letting the others move up, so later pages still start where earlier ones ended.

## Comments

`CommentListQuery` lists a thread's top-level comments in the viewer's language — and the viewer's own, whatever
language they were read as — first, then the rest, each group in the chosen order (one `CASE` of bound values in its
`orderByRaw` boundary). Replies keep their order. A Project settings switch, on while the feature is on.

## Settings

Project settings → Language ranking (`LanguageRankingSettings`, stored in `project_settings.language_ranking` and read
under its defaults the way the feature flags are; applying a preset never resets it):

| Setting | Default |
|---|---|
| Rank posts by language | off |
| Comments in the visitor's language first | on |
| Check languages with OpenAI | on |
| Fresh window for guests | 7 days |
| Longest fresh window for members | 7 days (at least 1) |
| A strong post in another language every | 10 places (0 = none) |
| How strong a post in another language has to be | top 5% of the week |
| Hot: lift posts in the visitor's language only above | percentile 0 (off) |
| OpenAI checks per day | 3,000 |

## Checking it on real data

The admin lists show each post's language and how surely it was told, and each comment's language, beside their
number. Project settings → Language ranking shows how the languages look now (`LanguageStatusQuery`): the published
posts by language, where the checks stand, how often the server's reading agreed with OpenAI's and how often a
post's text was in its author's language; `php artisan languages:report` prints the same. Read a month after
switching the feature on, they say whether the simulation's assumptions hold and the weights need a change.

`LanguageDemoSeeder` plays a month of a multilingual site through the site's own actions to look at all of this before
switching it on ([seed-data.md](../dev/seed-data.md)).
