# Growth → Games — operator guide

Games are short interactive pieces visitors play at `/games/{slug}`: a few
rounds of choices, then a result they can share. The first mechanic is Versus —
two pictures per round, and the player picks one.

## Before anything is public

Games stay invisible until **Project settings → Feature flags → Allow games** is on.
Prepare and preview a game first; switch games on when it is live.

## Making a game

1. **Growth → Games → New game.** Give it a public title, an internal name and
   an address (slug). A new game starts as a Draft with one content set,
   "Standard", and one flow, "Result First".
2. **General.** Title, description (up to 240 characters) and a cover picture.
   The address and the mechanic are locked once the game is first published.
3. **Content → Rounds.** Each round is a question with two options:
   - from a **post** — the round's point goes to the post the site's voters
     liked more. Both posts need at least 100 votes and a gap of at least 5
     percentage points between their upvote shares (both settable per content
     set). Persona votes count like everyone's. The editor says when a pair is
     too close or has too few votes, and offers to pick another post or to
     mark a correct answer instead;
   - from an **upload** — then you mark which option is correct.
   A round can be **unscored**: it is played but counts for nothing.
4. **Content → Results.** Points per round and the result profiles. Every
   number of matches, from none to all scored rounds, must belong to exactly
   one profile; "Fit ranges" spreads them again after rounds change.
5. **Flow.** The screens around the game, in order: landing, intro,
   instructions, the game, result preview, email capture, full result,
   registration, share, and the next game or the feed. Each step can be shown
   to everyone, only to anonymous visitors or only to logged-in players.
6. **Preview.** Play the draft or any version as an anonymous visitor, a
   visitor whose email is known, or a logged-in player, in any language, from
   the landing, from a given round, or straight to a result. Nothing is
   recorded.

## Publishing and traffic

- **Versions.** Pick a content set and a flow, read the checks, and publish.
  A version is frozen: today's votes, texts, results and translations stay as
  they were, whatever changes in the draft afterwards. Publishing gives the
  new version no traffic unless you ask it to take over another version's
  share.
- **Traffic.** Share each audience — anonymous visitors and logged-in
  players — between versions in whole percentages adding up to 100, and apply.
  Every change starts a new period; compare versions only inside one period.
  Visitors keep their version while it keeps a share; a started game always
  finishes on the version it started on.
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

## Privacy

- To keep a guest on the same version, the site sets a first-party cookie,
  `rg_game_visitor`, for a year. It holds a random token; only a hash of it is
  stored. **Mention it on your privacy page** (Project settings → Static pages).
- Email capture stores the address and, separately, whether the visitor agreed
  to marketing — unticked by default. Nothing is sent from a game.
- Game events are deleted after 400 days and captured emails after 365.
  Deleting an account deletes the emails its games left.
