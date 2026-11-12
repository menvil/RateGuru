# Growth → Games — operator guide

Games are short interactive pieces visitors play at `/games/{slug}`: a few
rounds of choices, then a result they can share. The first mechanic is Versus —
two pictures per round, and the player picks one.

## Before anything is public

Games stay invisible until **Project settings → Feature flags → Allow games** is on.
Prepare and preview a game first; switch games on when it is live.

## Setting up a game

1. **Growth → Games**, then open the game. The admin panel does not make new
   games: the library lists the ones there are. A game starts as a Draft with
   one content set, "Standard", and one flow, "Result First".
2. **General.** Public title, internal name, address (slug), description (up
   to 240 characters) and a cover picture; beside them an example round shows
   what the mechanic asks of a player. The address is locked once the game is
   first published.
3. **Content → Rounds.** Each round is a question with two options:
   - from a **post** — the round's point goes to the post the site's voters
     liked more. Both posts need at least 10 votes, and their upvote shares
     must differ — by any amount, or by at least the gap you set for the
     content set in Results (both settable per content set). Persona votes
     count like everyone's. The editor says when a pair is too close or has
     too few votes, and offers to pick another post or to mark a correct
     answer instead;
   - from an **upload** — then you mark which option is correct.
   A round can be **unscored**: it is played but counts for nothing. Drag a
   round in the list to change its place, or use Move up and Move down in its
   menu.
   Beside the round, **Live preview** plays it on the screen players see on a
   phone, with your changes before you save them. On a narrower screen it
   opens from **Preview round**.
4. **Content → Results.** Points per round and the result profiles. Every
   number of matches, from none to all scored rounds, must belong to exactly
   one profile; "Fit ranges" spreads them again after rounds change.
5. **Flow.** The screens around the game, in order: landing, intro,
   instructions, the game, result preview, email capture, full result,
   registration, share, and the next game or the feed. Each step can be shown
   to everyone, only to anonymous visitors or only to logged-in players. Full
   Result can ask anonymous visitors to sign up right there — the email, then
   a name and a password, or Google or Facebook where they are switched on —
   or leave sign-up to a Registration step after it. With "Show profile" off,
   the result card shows neither the profile's picture nor its icon. Next Game
   is skipped for visitors the chosen game gives no version to. A flow may end
   on Share, which then offers the way to the feed.
6. **Preview.** Play the draft or any version as an anonymous visitor or a
   logged-in player, in any language, from the landing, from a given round,
   or straight to a result. Nothing is recorded.

Games are not created in the admin: each arrives with the release that adds
it, as a draft in Growth → Games — the first is **Trust Your Taste** (Versus),
with an empty Standard content set and a Result First flow. Fill it, publish a
version, give it traffic and set it live.

## Publishing and traffic

- **Saving and publishing.** Saving changes the draft only; players keep
  playing the published version. While a tab holds saved changes they don't
  see, a notice under the tabs says so and opens Versions ready to publish
  them. If another administrator saved in between, renaming or restoring a set
  or flow asks you to reload, like Save does.
- **Versions.** Pick a content set and a flow, read the checks, and publish.
  A version is frozen: today's votes, texts, results and translations stay as
  they were, whatever changes in the draft afterwards. Publishing gives the
  new version no traffic unless you ask it to take over another version's
  share.
- **Traffic.** Share each audience — anonymous visitors and logged-in
  players — between versions in whole percentages adding up to 100, and apply.
  Every change starts a new period; compare versions only inside one period.
  Visitors keep their version while it keeps a share; a started game always
  finishes on the version it started on. If the shares changed after you
  opened Traffic — someone published and took a version's traffic over —
  Apply changes nothing and shows the shares in effect now.
- **Status.** Go live once a version has traffic. Pause stops new games but
  lets started ones finish. Archive ends the game; its shared result pages say
  it has ended.

If a post a version shows is hidden or deleted, that version can no longer be
played: its share goes to the others and Library, Versions and Traffic warn
about it. Publish a new version without the post.

## Translating

The player's buttons and messages are translated with each release. A game's
own texts — title, description, questions, captions, results and flow screens —
are translated in **Translation Center**, like the rest of the project's
content. Players see a translation once a version is published after it.

## Analytics

The Analytics tab shows, per audience and over the last 7, 30 or 90 days or all
time: visitors, starts, completion, emails, new accounts and sign-ins (or, for
logged-in players, shares and repeat players), the funnel, how many attempts
reached each round, which results players got, the versions, flows or content
sets side by side, and the traffic periods. Library shows each game's plays,
completion and sign-ups of the last 30 days.

The funnel counts people: someone who played three times started once. Each
step's rate says what it is a share of — Played again, for instance, is of the
players who started, not of those who shared. Completion is the share of plays
finished, replays included. Between the finished players and the new accounts,
**Sign-up begun** shows who started signing up from the game and how — email,
Google, Facebook, the site's sign-up dialog or Log in.

## Privacy

- To keep a guest on the same version, the site sets a first-party cookie,
  `rg_game_visitor`, for a year. It holds a random token; only a hash of it is
  stored. **Mention it on your privacy page** (Project settings → Static pages).
- Email capture stores the address and, separately, whether the visitor agreed
  to marketing — unticked by default. Nothing is sent from a game. The step's
  default wording says just that — the email is kept with the result, and news
  goes only to whoever ticks the box — so keep any wording you write to the
  same promise.
- Game events are deleted after 400 days and captured emails after 365.
  Deleting an account deletes the emails its games left.
