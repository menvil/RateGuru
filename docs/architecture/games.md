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
| Draft | `games`, `game_content_sets`, `game_rounds`, `game_round_options`, `game_result_profiles`, `game_flows`, `game_flow_steps` | the editors' actions, under `lock_version` |
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

## Scoring

A Versus round is **crowd** or **objective**.

- **Crowd.** The point goes to the post the site liked more: its share of
  upvotes, `up / (up + down)`, from the posts' own counters, persona votes
  included. The comparison is exact (`upA·nB` against `upB·nA`, no floats). A
  round is publishable only when both posts are published with a ready picture,
  differ, have at least `crowd_min_votes` votes each (100 by default), and their
  shares differ by at least `crowd_min_gap` percentage points (5; 0 turns the
  gap rule off). Both limits are set per content set. This is a product rule,
  not a significance test, and the editor says so.
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
`unique(attempt_id, round_index)` makes a repeated answer harmless.

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
nobody yet, in which case it is updated. A live game cannot be left with no
traffic in both audiences.

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

Signing up or signing in claims the guest's attempts of the last day
(`Registered` and `Login` listeners, `ClaimGuestGameAttemptsAction`): under a
lock on the user row, with `canSignIn()` checked again, only attempts of this
session with no user yet. `Registered` records `registered`, `Login`
`signed_in`. A failure is reported, never thrown at the visitor.

Email capture stores the address and a separate marketing consent, unticked by
default, with the consent wording the guest saw. Nothing is ever sent.
Deleting an account deletes the emails its attempts left.

## Events and metrics

`game_events` is append-only, written by `GameEventRecorder` in the same
transaction as the change it records: `view`, `assigned`, `step_viewed`,
`started`, `round_answered`, `completed`, `result_viewed`, `email_captured`,
`registration_cta_clicked`, `account_attributed`, `share_clicked`, `replay`
and `exit`. Each row carries the game, version, attempt, assignment, audience
and the user or the visitor's hash — ids and codes, never anything typed.

`GameMetricsQuery` reads the figures back (a registered raw-SQL boundary):

| Figure | Definition |
| --- | --- |
| Visitors / players | distinct people with a `view` in the window — guests by hash, players by account |
| Started | attempts started in the window |
| Completion | completed of those, however late they finished |
| Email capture | emails of those, over the completed attempts of versions whose flow asks for one |
| New accounts | distinct people whose attempt in the window led to `registered` |
| Shared | attempts in the window whose result was shared |
| Played again | people with more than one attempt in the window |

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
version as any kind of visitor in any language and records nothing.

## Translations

The player's interface is `lang/*/games.php`. A game's content is the project's
own: title and description, rounds, options, results and flow step texts are
`{field}` plus `{field}_translations` columns, and Translation Center lists
them for games that are not archived (`docs/i18n/project-translation-lifecycle.md`).
Players read the translations frozen into their version.
