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
| Traffic | `game_traffic_periods`, `game_traffic_shares` | `ApplyGameTrafficAction`, `EndGameTrafficSplitAction`, `PublishGameVersionAction` (taking traffic over) |
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
(`snapshot_schema = 2`) holds the game's texts, scoring, rounds, profiles, flow
steps, the flow's goal and every translation, and is read through
`GameVersionSnapshot`, which reads schema 1 too — a version published before
goals existed, whose goal the migration that added them wrote into
`game_versions.goal` by the rule it records. A
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

## Goals

Every flow has a goal (`GameFlowGoal`): what a play of it is for — an account
confirmed (sign-up or log-in), only a new account, an email, a share, the post
it sends the visitor to opened, voted on or rated, three votes in the feed,
the next game started, a page followed, the feed followed, a replay, or the
game finished. `game_flows.goal` is null while the flow follows its steps:
`FlowGoalRules::defaultFor()` then picks what the steps are evidently there
for. The snapshot always carries the goal in force, and so does
`game_versions.goal`; changing it is an edit of the draft that counts from the
next version published.

`FlowGoalRules::stepFor()` names the step that reaches a goal. An account is
reached where the flow asks for one — Registration, or sign-up on Full Result —
or on the site after a step that sends the visitor there, where voting or
commenting asks for an account. `GameFlowValidator` refuses a goal the flow
cannot reach (“The goal “…” needs …”), and the editor offers the step that
would.

The moments of a play the traffic tracker counts (`GameMilestone`) are
recorded by `GameMilestoneRecorder` inside the transaction of the action that
reached them: started, halfway (the round `ceil(N/2)`), completed, email,
shared, replayed (on the attempt the visitor came back from), next game
started (on the attempt whose Next Game step sent them, within 30 minutes),
page, feed and post opened, and the votes below. Each raises
`App\Events\Games\GameMilestoneReached` synchronously; when it is the goal,
`markGoal()` sets `game_attempts.goal_reached_at` with an `UPDATE … WHERE
goal_reached_at IS NULL` — at most once per attempt, the one place that does —
and records `goal_reached`.

## Ways on

Four exit steps send the visitor on: Next Game, Go to Feed (with a sort), Go to
Post and Go to Page; and Full Result can do it itself (`onward`: next step, a
post or the feed), when sign-up and sharing are not shown on it and the steps
after it never are. Go to Post opens a post the administrator linked, the post
of the option the visitor picked in the last round with one (else a fresh
one), or the newest published post outside the game that is not theirs and
they have not voted on (`GameOnwardQuery`). Go to Page opens a category or a
tag of the feed, a page of this site (the feed, a post, a profile, a game or a
static page, checked against the router), or another https site with
`{click_id}`, `{game}` and `{result}` filled in, optionally in a tab of its
own.

`PlayGame::leave('step')` hands the attempt to `LeaveGameAction`, which takes
the step from the attempt as stored — never from the page — under a lock,
decides the address, and the first time writes `onward_kind`, `onward_post_id`
and `onward_at`, `exit`, and the moment: `post_opened`, `page` or `feed`. A
post that is not published sends the visitor to the feed and counts nothing.
A post can open by itself after 3 seconds, once: coming back to the game never
sends the visitor away again. The preview only says where it would go.

The post page is the site's own: `vote=1` and `ask=k1,k2` only highlight the
vote and the rating groups. What the visitor does there is credited by
`CreditGameSiteActivityAction`, called by the voting components once a vote is
in, to the play that sent them within 30 minutes — theirs, or their browser's
before they signed in — read back from the votes themselves: `post_voted` (a
vote on that post), `post_rated` (an answer to a group the step asked for),
`feed_voted` (three votes after leaving for the feed), each once. A failure is
reported and never spoils the vote.

A sign-in or sign-up form shown — the site's dialog, or the sign-in and
sign-up pages — sends the traffic signal `signup_form` (`POST /visit/signal`,
`RecordVisitSignalAction`), which also calls
`CreditGameSiteActivityAction::signInFormShown()` with the browser's hash from
the games' own visitor cookie, so it works whether or not Record visits is on.
The dialog opened on a post's page names that post (`post`, read from the
page's `data-rg-post`); any other page names none. The form is credited as
`post_sign_in_form`, once, to the latest guest attempt of that browser whose
`onward_kind` is `post`, whose `onward_post_id` is the post named and whose
`onward_at` is within the last 30 minutes — so a form on the feed, on another
post or on the sign-in and sign-up pages is credited to none. It reaches no
milestone, and a failure is reported while the signal still answers 204.

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
index, inserted in a savepoint) lasts as long as its version keeps a share in
any open period of the audience — the general split's or a split for a source —
and stays playable. A started attempt always finishes on its own version.

The page itself stores nothing: the first Livewire request (`wire:init`)
assigns and records the `view`, so a client that runs no script is neither
assigned nor counted.

### Splits for a source

A period has a scope (`TrafficScope`): everyone — the general split, with
`source_id` and `campaign_id` empty — or the paid clicks of one traffic source
(`source_id`), or of one of its campaigns (`campaign_id` too). A game has one
open period per audience and scope: `open_scope` holds `everyone`,
`source:{id}` or `campaign:{id}` while the period is open and is cleared when
it ends, under the unique index `game_traffic_periods_open_scope_unique`
(game, audience, open scope) — NULLs repeat, so ended periods do too, on every
engine. Sources and campaigns are never deleted, so both references restrict.

Splits are for anonymous visitors only. The arrival's scope is the paid click
that brought the visitor to this game: the click `CaptureTrafficArrival`
recorded before the page was drawn, named by the page's arrival token
(`PlayGame` reads it once, at mount, for both the drawn version and the
assignment `init` makes), or the visit `StartGameAttemptAction` starts the
attempt under — in either case only when the click's game is this game. A
signed-in player, an unpaid arrival and a visitor whose click went to another
game get the general split. While Record visits is off no click is recorded,
so splits give nobody anything.

`GameTrafficQuery::assignable()` picks the open period that covers the arrival
most closely and has a playable share — the campaign's split, then the
source's, then the general one — and the versions with a playable share in any
open period of the audience, which a visitor already given one keeps. So a
visitor who comes back from another source keeps their version; only the
visitors a split gave a version are in its period (`game_assignments.period_id`),
so comparisons never mix; and a version taken out of a split moves only the
visitors who have it nowhere else. A split none of whose versions can be played
sends its clicks on to the next split that covers them. Without splits this is
the general period alone, as before.

A split is applied like the general split (`ApplyGameTrafficAction` with a
scope: the same share rules and stale check, at least one share and one
playable version, anonymous visitors only), and ended by
`EndGameTrafficSplitAction`: the split's period ends and stays in the history,
even one nobody was sent through — never deleted, as a visitor being assigned
at that moment may already be writing into it. A version
published to take another's place takes its share in every split too. Each
split is its own comparison period: Growth → Experiments, Analytics →
Experiments, the game's Traffic history and its Analytics periods name it by
its source and campaign (`TrafficPeriodScopesQuery`), and a general period
that ran beside a split reads as “Everyone else”. Value, postbacks and Traffic
sources stay per game.

### Test links

A game's Preview offers, for a published version still active, a test link
(`VersionTestLink`): the game's public page with `v`, signed
(`URL::temporarySignedRoute`) for seven days. Only `v` and `expires` are
checked against the signature, so a parameter a channel adds on the way —
`fbclid`, `utm_*` — leaves the link working; another spelling PHP reads as
one of them (`%76`, ` v`, `v[]`) would replace its value, so it is checked
too and breaks the link. `PlayGamePageController`, the
action of `games.play`, draws the version through the admin preview's
component (`PreviewGame`) for a valid link to an active version of that game
while the game is live or paused — never a draft, which nobody plays yet —
as whoever holds it, a signed-in player or an
anonymous visitor, in the site's language, under the preview's banner.
Nothing is recorded: `CaptureTrafficArrival` leaves the request alone (no
click, arrival token or cookie), and the preview assigns nobody and keeps its
picks in its own locked state — no attempt, answer, email, event, milestone or
conversion; its result's share links go straight to each provider, never
through the share counter. A link unsigned, tampered with or expired, or to a version archived
or of another game, is the game's ordinary page.

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
`started`, `round_answered`, `halfway`, `completed`, `result_viewed`,
`email_captured`, `registration_cta_clicked`, `account_attributed`,
`share_clicked`, `replay`, `exit`, `post_opened`, `post_voted`, `post_rated`,
`feed_voted`, `goal_reached`, `account_confirmed` and `post_sign_in_form`. Each row carries the game, version, attempt, assignment, audience
and the user or the visitor's hash — ids and codes, never anything typed.
An attempt's event carries the attempt's own game and the moment it was
recorded, never earlier than the attempt's start: `GameMetricsQuery` reads an
attempt's events by that game and from the window's start, so whatever writes
events — an import or a backfill too — keeps to both. Whatever changes what a
period's funnel counts of an assignment — a play started from it, completed,
a result seen (`GameEventRecorder` tells of `result_viewed` itself), an email
left, the goal reached, an account credited to one of its plays confirmed or
activated, or a row of any of them deleted — tells the funnel in the same
transaction (`MarkAnalyticsPeriodHourAction`; docs/architecture/analytics.md,
"The hours of a period's assignments"), an import or a backfill too. Every
change to an attempt after it began that a game's people count — completed,
its goal reached, taken by an account, an account credited to it confirmed,
shared, sent on, a sign-up begun — comes with its event, recorded now
(`completed`, `goal_reached`, `account_attributed`, `account_confirmed` and the
events the funnel counts people by): `GameEventRecorder` tells the people of
the attempt's day of it once the day has closed (`MarkAnalyticsGameCellsAction`;
docs/architecture/analytics.md, "The people of a game's days"), and a game's
figures find an open day's attempts changed since it was counted by those
events, and by the moments the attempt keeps of them (`completed_at`,
`goal_reached_at`, `account_outcome_at`), which stay when an event goes. So
whatever changes those columns of an attempt records the event
with the change, at the same moment or after it — an import or a backfill too
— and whatever deletes views, attempts or their events tells the people of
their days first.
`step_viewed` (`result_viewed` for Full Result) names each step after the game
an attempt enters, the one its last pick moves it on to included.
`share_clicked` names where the result went, and is recorded on the attempt
whose result it is — for a provider only once the provider's address has been
built, for a copied link only once the clipboard took it (where it refuses,
the page shows the link to copy by hand); `registration_cta_clicked` names
how signing up began: `email` or a provider on the sign-up set into Full
Result, or `register` / `login` for the site's dialog; `exit` names where the
visitor was actually sent.

`GameMetricsQuery` reads the figures back (a registered raw-SQL boundary) —
the visitors, the people behind the attempts, the accounts they signed in and
what their events tell, from the people of the game's days where those are
kept and read (PostgreSQL with the roaringbitmap extension;
docs/architecture/analytics.md, "The people of a game's days"), the same
figures:

| Figure | Definition |
| --- | --- |
| Visitors / players | distinct people with a `view` in the window — guests by hash, players by account |
| Started | people who started an attempt in the window, each once however often they played |
| Completed | people who finished one of those attempts, however late |
| Completion | completed attempts over started ones — plays, a replay one more |
| Email capture | people who left an email on one of those attempts, over the people who finished a version whose flow asks for one |
| Sign-up begun | people with a `registration_cta_clicked` on one of those attempts, and how many began each way |
| New accounts | confirmed accounts credited to an attempt in the window (`account_attributions`): signed up within 7 days of finishing it, on any page of the site, then confirmed — an email or Google or Facebook. An account never confirmed never counts |
| Shared | attempts in the window whose result was shared; in the funnel, the people who shared |
| Played again | people with more than one attempt in the window |
| Ways on | people one of those attempts sent on to a post (`post_opened`), the feed, a page or the next game (an `exit` whose `step_type` is the attempt's own `onward_kind`, so the link to the feed every screen has counts for nothing), and the people credited with `post_voted`, `post_rated` or `feed_voted` |
| From the post to an account | of the attempts sent to a post: people who saw the sign-in form within 30 minutes of `onward_at` — a `post_sign_in_form`, or `signup_form_at` on the attempt's own recorded visit (`traffic_visit_id`) between `onward_at` and 30 minutes on, which paid visits had before the event existed — only on attempts whose `onward_at` is before the game's first `post_sign_in_form` (one bound `MIN` read per game; on every attempt while it has none), since any sign-up dialog sets that mark, the feed's too — or a sign-up there, which takes the form; people with an account credited to the attempt (`account_attributions.credited_attempt_id`) whose `signup_place` is `post` and `signed_up_at` within those 30 minutes; and of those, the people whose account is confirmed |

Narrowed to a device or country (a `VisitFilter` in the scope), or split by
them, the figures read the recorded visits, since the game's tables keep no
device or country: an attempt counts when the visit it started on
(`game_attempts.traffic_visit_id`) is one the filter admits, and a visitor
when they have such a visit of the game — a guest by the browser's hash, a
player by an account the browser signed up or in on (`account_browsers`) or
by the visit a play of theirs came on. A play with no recorded visit is left
out, and a visitor who came from two places counts in each. A country group
is the current price set's: the countries it names, or for the last group
every country the others don't name and an unknown one. A visit counts in a
window while it arrived before the window ends and was last seen after it
begins; no visit is seen on past the longest attribution window a source can
have (30 days), so only arrivals from a day more than that before the window
are read — a click id that came back later counts in the window it began in.
The visits are Analytics' own rows of them (`analytics_visits`, which keep a
tracked visit's browser hash and when it was last seen) once those are filled
and up to date (docs/architecture/analytics.md, "What a tab reads").

The funnel counts people at every step, and each step's rate names the step it
is a share of — not always the one above it: Sign-up begun and New accounts
are shares of the people who completed, Email captured of those who finished a
version that asks for an email (each person once, however many such versions
they finished), and Played again of the players who started. The Completed
figure counts people as well; for logged-in players its note counts the plays
behind it, and how many of them were completed. The ways on come before
Sign-up begun (after Shared result for logged-in players), a row only for those
the versions in view offer the audience (`GameVersion::waysOn()`). A guest who
signs in on the way is led on as a player while their attempt stays a guest's,
so a way on offered only to logged-in players gets a row for anonymous
visitors too, and a rating such a step asks for counts with them. Went on to
the post, to the feed, to a page and to the next game are shares of the people
who completed; Voted on the post and Rated the post — only where a step sending
to a post asks for ratings (`GameVersion::asksForPostRatings()`) — of the
people sent to the post, and Voted in the feed of those sent to the feed. While
another version in view sends to a post without asking, Rated the post is of
the people asked to rate instead: those the versions that ask sent to a post,
each person once however many of them sent them (`GameMetricsQuery::sentToPost()`).
For anonymous visitors only, Saw the sign-in form there, Signed up there and
Confirmed that account come between Went on to the post and Voted on the post,
each a share of the row above it. Adding 30 minutes to `onward_at` is the one
expression each engine writes its own way (`GameMetricsQuery::minutesAfter()`);
the rest of the query is the same on all three.

Goal reached counts the attempts of the window that reached their flow's goal,
however late — an account confirmed within 7 days on any page of the site
included. The Value tab shows what each step of a play is worth to the paid
traffic tracker (docs/architecture/traffic.md, "Worth"), and Analytics
compares the two best versions on paid traffic by accounts per new visitor
(TwoProportionTest): too early with fewer than 10 accounts on either, a leader
from 95 % confidence. Outside paid traffic, versions are compared only inside
one traffic period: Growth → Experiments reads each period's visitors by the
version they were assigned (`GameMetricsQuery::periodFunnel()`,
`PeriodComparison`), and the badge on a period here follows the same rule
(docs/architecture/analytics.md, "Experiments"). A period hidden from
Experiments (`hidden_at`) is still listed here.

## Retention

`games:purge-expired`, daily: game events after `games.retention_days.events`
(400) and emails after `games.retention_days.email_captures` (365), in small
batches, each telling a period's funnel the hours of the assignments whose
results seen and emails it deletes, and the people of the games' days the
days of the views it deletes and of the attempts whose shares, sign-ups begun
and ways on go; then the numbers of the guests' browsers no cell of those
people holds any more are let go, never the highest. Attempts, answers and
results stay. The retention lives in `config/games.php` without environment
variables.

## Switches and access

Public routes sit inside the guest-viewing check and behind
`EnsureGamesAreEnabled` — the `allow_games` flag in Project settings, off by
default — which guards every Livewire request of the player as well. The admin
pages and the preview (`/games/{game}/preview`) need `manage-games`
(administrators with the privileged panel). The preview plays a draft or a
version as any kind of visitor in any language and records nothing — on the
game's public page too, for a valid test link (“Test links”); `start`
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
