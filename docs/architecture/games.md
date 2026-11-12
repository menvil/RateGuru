# Growth → Games

A game is a few rounds of choices with a result at the end, built in the admin
panel under Growth → Games and played by visitors at `/games/{slug}`. The first
mechanic is **Versus**: two pictures per round, and the player picks one. The
first game made with it is "Trust Your Taste".

The domain is built so further mechanics (swipe, quiz, rating, blitz) fit beside
Versus. Only Versus exists, and no mechanic interface is extracted until a
second mechanic needs one. Everything that is not Versus-specific — flows,
versions, traffic, attempts, results, events and metrics — does not know which
mechanic a game uses.

One deployment is one brand with its own database, so nothing here carries a
site id. The brand's name comes from Project settings and every URL from the
router.

## Layers

| Layer | Tables | Written by |
| --- | --- | --- |
| Draft | `games`, `game_content_sets`, `game_rounds`, `game_round_options`, `game_result_profiles`, `game_flows`, `game_flow_steps` | the editors' actions, under `lock_version` — renaming and restoring a set or a flow too |
| Version | `game_versions` (snapshot JSON), `game_version_assets`, `game_version_posts` | `PublishGameVersionAction` only |
| Traffic | `game_traffic_periods`, `game_traffic_shares` | `ApplyGameTrafficAction`, `PublishGameVersionAction` (taking traffic over) |
| Runtime | `game_assignments`, `game_attempts`, `game_answers`, `game_email_captures`, `game_events` | the player's actions |

Writes live in `app/Actions/Games`, reads in `app/Queries/Games`, and pure
logic — scoring, coverage, flow rules, assignment — in `app/Support/Games`,
which never reads config, the clock or translations, so its tests run without
the application.

Locks are always taken in one order: the administrator's user row, then the
game, then the content set, then the flow. Traffic locks the game; an answer
locks its attempt.

## Database checks

Three rules are kept by the database itself, alike on every engine:

| Constraint | Holds |
| --- | --- |
| `game_round_options_source_shape_check` | a post option holds no uploaded picture: `source = 'upload' OR asset_id IS NULL` |
| `game_traffic_shares_percent_check` | a share is 1 to 100 percent |
| `game_assignments_identity_check` | an assignment names exactly one of `user_id` and `visitor_hash` — a signed-in player or an anonymous browser, never both |

That an uploaded option holds no post is `SaveContentSetAction`'s to keep, not
the database's: the post purge detaches `post_id` (`ON DELETE SET NULL`), and
MariaDB refuses a `CHECK` on a column with a referential action, so no engine
checks it and all three agree.

SQLite takes a `CHECK` only with its table, so on SQLite these three tables
are written whole, in `CREATE TABLE` statements of their own, while PostgreSQL
and MariaDB add the same named constraint to the table `Schema::create` made.
That is the engine-specific exception [Database
support](database-support.md) asks to be written down: the SQLite statement
repeats the columns, keys, referential actions and enum sets by hand, so both
branches of each migration must describe one table. The shared behaviour test,
`tests/Feature/Database/GameTablesTest.php`, holds each check — with the round
options' cascade, post detach and asset restriction — on all three engines.

## Scoring

A Versus round is **crowd** or **objective**.

- **Crowd.** The point goes to the post the site liked more: its share of
  upvotes, `up / (up + down)`, from the posts' own counters, persona votes
  included. The comparison is exact (`upA·nB` against `upB·nA`, no floats). A
  round is publishable only when both posts are published with a ready picture,
  differ, have at least `crowd_min_votes` votes each (10 by default), and their
  shares differ: by at least `crowd_min_gap` percentage points where the
  administrator asks for a gap (none by default, so only a tie has no pick).
  Both limits are set per content set. A gap is a product rule, not a
  significance test, and the editor says so.
- **Objective.** One option is marked correct. A round with an uploaded picture
  is always objective. Votes play no part.
- **Unscored.** A round can count for nothing. It is part of the progress and
  of drop-off, but not of the score, and is never coloured right or wrong.

`matches` is the number of scored rounds answered right; the score is
`matches × points_per_round`. Result profiles cover match counts, not points:
every count from 0 to the number of scored rounds belongs to exactly one
profile. The editor shows the equivalent points.

Publishing freezes everything the answer depends on into the version's
snapshot: for a crowd round both posts' votes, the limits and the preferred
side; for an objective round the correct option. Nothing is recalculated from
live votes while people play, and an attempt keeps its score, matches, maximum
and profile, so a result never changes after the fact.

The answer never reaches the browser before the pick, and the snapshot is never
part of Livewire state. Only an answer to the next round is accepted
(`round_index = rounds_answered`, the attempt row locked), and
`unique(attempt_id, round_index)` makes a repeated answer harmless. The page
sends the round on screen with each pick: a pick from a stale tab, made on a
round the attempt has already passed, records nothing and shows where the
attempt stands.

## Versions

A version is a content set and a flow frozen together. Its number rises
monotonically per game (`games.last_version_number` under the game's lock, plus
`unique(game_id, number)`), and numbers are never reused. The snapshot
(`snapshot_schema = 1`) holds the game's texts, scoring, rounds, profiles, flow
steps and every translation, and is read through `GameVersionSnapshot`. A
published version is immutable: the model refuses any change but its status.
Versions are retired, never deleted; a retired one can be restored. A set or
flow that a version used is only ever archived.

`game_version_posts` lists the posts a version shows (no foreign key: a post's
own deletion rules apply). If one of them is hidden or deleted, the version
can't be played: a started attempt says so at its next round, assignment skips
the version and shares its traffic out among the playable ones, and Library,
Versions and Traffic warn about it.

Versions says when the chosen set or flow — or anything in them, a translation
included — changed after the newest version published from them.

## Traffic

Each audience — anonymous visitors and logged-in players — has its own shares
between active versions, whole percentages adding up to 100, or no traffic at
all. Applying shares starts a new period, unless the current one has assigned
nobody yet, in which case it is updated. A live game cannot be left without
playable traffic in both audiences. Apply carries the shares the page was
drawn with and, under the game's lock, refuses as stale when they have changed
since — after a publish that took a version's traffic over, say.

A visitor is assigned deterministically: `hash(game, period, identity) mod 100`
over the playable versions in number order. The assignment
(`game_assignments`, one open row per visitor through `active_key` and a unique
index, inserted in a savepoint) lasts as long as its version keeps a share and
stays playable. A started attempt always finishes on its own version.

The page itself stores nothing: the first Livewire request (`wire:init`)
assigns and records the `view`, so a client that runs no script is neither
assigned nor counted.

## Visitors and attempts

A signed-in visitor is known by their account. A guest is known by a random
token in the encrypted, HttpOnly, first-party cookie `rg_game_visitor`
(SameSite=Lax, one year); only the token's SHA-256 is stored. The privacy page
is the project's own content, so the operator guide (`docs/admin/games.md`)
reminds the administrator to mention it.

An attempt is created on Start. The session keeps the ids of its latest
attempts (`games.attempts`, up to 20) and every Livewire call checks that the
attempt it acts on is one of them or the signed-in player's — a locked property
alone proves nothing. A refresh resumes at the next round or the step it stood
on; a stale tab cannot move an attempt backwards.

An attempt is `started` or `completed`; one started more than a day ago with no
activity since is "abandoned", a derived state. A paused game lets started
attempts finish but starts none; an archived game is over, and its result
pages say so.

Full Result can carry the sign-up itself for an anonymous visitor: the email
first, or a provider, or logging in instead; then a name and one password,
shown on request and sent as its own confirmation. It is the site's own
registration — `POST /register` with the `embedded` surface of
`AuthSurfaceContext`, which comes back to the game like the modal does but
with the modal shut and a failure's errors in the form on the page.

Signing up or signing in claims the guest's attempts of the last day
(`Registered` and `Login` listeners, `ClaimGuestGameAttemptsAction`): under a
lock on the user row, with `canSignIn()` checked again, only attempts of this
session with no user yet. `Registered` records `registered`, `Login`
`signed_in`. A failure is reported, never thrown at the visitor.

Email capture stores the address and a separate marketing consent, unticked by
default, with the consent wording the guest saw. Nothing is ever sent. Only a
guest's attempt takes an email — one an account has claimed refuses it — and a
required email step holds only the visitors who still see it: a guest who
signs in on it goes on. Deleting an account deletes the emails its attempts
left.

## Events and metrics

`game_events` is append-only, written by `GameEventRecorder` in the same
transaction as the change it records: `view`, `assigned`, `step_viewed`,
`started`, `round_answered`, `completed`, `result_viewed`, `email_captured`,
`registration_cta_clicked`, `account_attributed`, `share_clicked`, `replay`
and `exit`. Each row carries the game, version, attempt, assignment, audience
and the user or the visitor's hash — ids and codes, never anything typed.
`step_viewed` (`result_viewed` for Full Result) names each step after the game
an attempt enters, the one its last pick moves it on to included.
`share_clicked` names where the result went, and is recorded on the attempt
whose result it is — for a provider only once the provider's address has been
built, for a copied link only once the clipboard took it (where it refuses,
the page shows the link to copy by hand); `registration_cta_clicked` names
how signing up began: `email` or a provider on the sign-up set into Full
Result, or `register` / `login` for the site's dialog; `exit` names where the
visitor was actually sent.

`GameMetricsQuery` reads the figures back (a registered raw-SQL boundary):

| Figure | Definition |
| --- | --- |
| Visitors / players | distinct people with a `view` in the window — guests by hash, players by account |
| Started | people who started an attempt in the window, each once however often they played |
| Completed | people who finished one of those attempts, however late |
| Completion | completed attempts over started ones — plays, a replay one more |
| Email capture | people who left an email on one of those attempts, over the people who finished a version whose flow asks for one |
| Sign-up begun | people with a `registration_cta_clicked` on one of those attempts, and how many began each way |
| New accounts | distinct people whose attempt in the window led to `registered` |
| Shared | attempts in the window whose result was shared; in the funnel, the people who shared |
| Played again | people with more than one attempt in the window |

The funnel counts people at every step, and each step's rate names the step it
is a share of — not always the one above it: Sign-up begun and New accounts
are shares of the people who completed, Email captured of those who finished a
version that asks for an email, and Played again of the players who started.
The plays behind it — and how many of them were completed — are the
Completion figure's.

Nothing is estimated and nothing claims one version is better than another;
versions are compared only inside one traffic period.

## Retention

`games:purge-expired`, daily: game events after `games.retention_days.events`
(400) and emails after `games.retention_days.email_captures` (365), in small
batches. Attempts, answers and results stay. The retention lives in
`config/games.php` without environment variables.

## Switches and access

Public routes sit inside the guest-viewing check and behind
`EnsureGamesAreEnabled` — the `allow_games` flag in Project settings, off by
default — which guards every Livewire request of the player as well. The admin
pages and the preview (`/games/{game}/preview`) need `manage-games`
(administrators with the privileged panel). The preview plays a draft or a
version as any kind of visitor in any language and records nothing; `start`
takes the landing, a round, a result for a number of matches or any step of
the flow, `framed` leaves out its banner inside an editor's device frame, and
`shape` frames every pair landscape, square or portrait.

The game page is `layouts.app` in its focused mode: no header, navigation,
search or post panel — the game draws its own bar, the site's name and Skip to
the feed — while the sign-in dialog and the toasts stay. A pair's frames follow
its pictures: 4:3 when both are wide, 3:4 when both are upright, square for a
mixed pair; a wide pair stacks on a phone. The round fills the screen and
never scrolls: the space for the verdict and Next pair is kept from the start,
and the pictures shrink, keeping their shape, into what the question leaves.
Every other step is one column, up to 480 wide, the result a share card beside
what to do with it.

The share card names the player by their @username while their account is in
good standing (`PlayerHandle`), and the site by its domain otherwise. A
player's result is shared as their own page, `/games/{slug}/r/{attempt}` —
the attempt's public id, so nobody can put a name on a result they did not
earn — and a guest's as the profile's page, `/games/{slug}/v{n}/{profile}`.
Once the account is banned, limited or deleted, the player's page opens the
profile's instead. Every played result's provider links go through its
attempt's address, `/games/{slug}/r/{attempt}/share/{provider}`, so the share
is counted on the attempt; a guest's still shares the profile's page.

Content's live preview plays the round on screen before it is saved. Each
draw of the tab hands the draft to `GamePreviewDrafts`, in the cache for 30
minutes under a stamp made from the draft itself and the administrator's id;
the preview frame's address carries the stamp, and the preview builds the set
in memory from that draft (`DraftContentSet`) instead of reading the saved
one. Pictures chosen for options but stored only on Save go with the draft as
their temporary preview addresses and show in their places. The Flow tab's step
preview hands its unsaved steps over the same way (`flow_draft`, `DraftFlow`);
a step the chosen visitor never sees gives way to the next one they do. A stamp from someone else, or one that has expired, plays the set as
saved. Nothing of the draft is written to the database.

## Translations

The player's interface is `lang/*/games.php`. A game's content is the project's
own: title and description, rounds, options, results and flow step texts are
`{field}` plus `{field}_translations` columns, and Translation Center lists
them for games that are not archived (`docs/i18n/project-translation-lifecycle.md`).
Players read the translations frozen into their version.
