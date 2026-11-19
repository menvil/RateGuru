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
   or straight to a result. Nothing is recorded. With a published version
   still active chosen, **Copy test link** copies a link to the game's page
   that plays that version for whoever opens it, signed in or not, for seven
   days while the game is live or paused — never while it is a draft — and
   records nothing; once it expires, or the version is archived, it opens the
   ordinary game page.

Games are not created in the admin: each arrives with the release that adds
it, as a draft in Growth → Games — the first is **Trust Your Taste** (Versus),
with an empty Standard content set and a Result First flow. Fill it, publish a
version, give it traffic and set it live.

## Publishing and traffic

- **Saving and publishing.** Saving changes the draft only; players keep
  playing the published version. While a tab holds saved changes they don't
  see, a notice under the tabs names them — "2 changes since V2: Full Result ·
  Way-on title, Round 2 · Question"; past three, the first three and how many
  more, with every change listed behind "All n changes" — and opens Versions
  ready to publish them. It compares what a new version would freeze with the
  version players play, so a save that changed nothing they meet — a setting
  the step's other choices hide, a text saved again as it was — shows no
  notice. A flow saves from the top bar or from Save flow at the foot of the
  selected step's settings. If another administrator saved in between,
  renaming or restoring a set or flow asks you to reload, like Save does.
- **Versions.** Pick a content set and a flow, read the checks, and publish.
  A version is frozen: today's votes, texts, results and translations stay as
  they were, whatever changes in the draft afterwards. Publishing gives the
  new version no traffic unless you ask it to take over another version's
  share.
- **Traffic.** Share each audience — anonymous visitors and logged-in
  players — between versions in whole percentages adding up to 100, and apply.
  Every change starts a new period; compare versions only inside one period —
  Growth → Experiments does, and each period's **Results** opens it there.
  Visitors keep their version while it keeps a share; a started game always
  finishes on the version it started on. If the shares changed after you
  opened Traffic — someone published and took a version's traffic over —
  Apply changes nothing and shows the shares in effect now.
- **Splits for a source.** Under the general split, **Split for a source**
  gives anonymous visitors from one paid source — an ad network or a banner of
  Traffic sources — or from one of its campaigns shares of their own: choose
  the source, a campaign or every campaign, share the versions as above and
  save. The closest split wins: the campaign's, then the source's, then the
  general one, which then reads as "Everyone else". Logged-in players and
  unpaid visitors always get the general split, and the ad link stays one per
  game. A visitor keeps their version when they come back from another source.
  Each split is its own period in Experiments and Analytics, named by its
  source and campaign; **Edit** changes it, **End** sends its clicks back to
  the general split and keeps the split in the history as an ended period.
  Splits need Traffic sources → **Record visits** on: only a recorded click
  knows its source.
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

The Analytics tab shows, per audience and over today, yesterday, the last 7,
30 or 90 days, this month, last month, all time or a range of days picked on
the period picker's calendar (click the first day, then the last) — the same
presets as Traffic sources and Growth → Analytics: visitors, starts,
completion, emails, new accounts and sign-ins (or, for logged-in players,
shares and repeat players), the funnel, how many attempts reached each round,
which results players got, the figures broken down by version, flow, content
set, device, country or country group, and the traffic periods. Each figure is
compared with as many days right before the period — yesterday with the day
before, this month with last month as far into it as this month has gone,
last month with the whole month before, and today with the day before up to
the same time, since the rest of today hasn't come yet; all time has nothing
to compare with. Cut short at the same time — today's day before, or as far
into last month as this month has gone — the earlier days count the visitors
who opened the game and the plays begun by then ("compared with 17 Nov 2026,
visits and plays begun by the same time"), with whatever came of those plays
later: a play begun at 9:00 and finished at 15:00 counts as completed against
today at noon. The period, the version, the audience, the device and the
country are kept in the page's address, so a link opens the same view; a range
that ends after today, runs backwards or reaches further back than the 400
days visits are kept opens the last 30 days instead. An address that names no
period opens the period you last chose on any game's Analytics. Library shows
each game's plays, completion and sign-ups of the last 30 days.

The game's own records know no device or country: those come from the visits
Traffic sources recorded. With a **Device** or **Country** chosen — a country,
or a country group of Value → Country groups, the last group holding every
other country and the visits whose country isn't known — every card but
Traffic periods counts only what came on such a visit: a play by the visit it
started on, a visitor by their visits of the game (a logged-in player by a
browser they signed up or in on, or by the visit a play of theirs came on).
Plays with no recorded visit — before Record visits was switched on, say —
drop out, and the page says so.
The Traffic sources card's own device switch is there only while the page is
of every device.

Each traffic period shows how many visitors it sent to a version and whether
its versions can be compared yet: **One version** when only one had a share,
**No visitors yet** while nobody has been sent to a version, **Too early**
until every version has 10 confirmed accounts (logged-in players
make none, so their goals reached count instead) — the bar the comparison of
versions on paid traffic uses — and **Enough data** after. Each period is read
whole, as Growth → Experiments reads it: all of its visitors, whatever the
days, device and country chosen for the rest of the page, and the card says
so. A split for a source is listed by its source and campaign, and the general
split beside one as "Everyone else". **Results** opens the period in Growth →
Experiments, where its versions are compared metric by metric
(docs/admin/experiments.md); the same link is on each period of the Traffic
tab. A period deleted there stays listed here.

The same analytics, with a chooser of the games not archived, is Growth →
Analytics → **Games** (docs/admin/analytics.md), read for the period and device
chosen there. Growth → Analytics also follows a game's visitors from the page
their session began on (Landings), the campaign that brought them (Campaigns)
and whether their accounts come back (Retention).

The funnel counts people: someone who played three times started once. Each
step's rate says what it is a share of — Played again, for instance, is of the
players who started, not of those who shared. **Completed** above it counts
people too; for logged-in players its note also says how many plays were
finished, replays included. Email captured is of the people who finished a
version that asks for an email, each once however many such versions they
finished. Between the finished players and the new accounts,
**Sign-up begun** shows who started signing up from the game and how — email,
Google, Facebook, the site's sign-up dialog or Log in.

Where a flow sends players on from the game, the funnel follows them, just
before **Sign-up begun** (for logged-in players, after **Shared result**), with
a row only for the ways on the versions shown offer: **Went on to the post**,
then **Voted on the post** and — where the step asks for ratings — **Rated the
post**, both of the people sent to the post (a rating, while another version
shown sends to a post without asking, of the people the versions that ask sent
there); **Went on to the feed**, then
**Voted in the feed** (three votes); **Went on to a page**; **Went on to the
next game**. Each way on is of the people who completed. A vote or a rating
counts when it comes within 30 minutes of leaving the game, and a vote needs an
account. Only the flow's own way on counts, never the link to the feed at the
top of every screen. A guest who signs in on the way still counts with the
guests, so a way on shown only to logged-in players also gets a row for
anonymous visitors.

For anonymous visitors sent to a post, three rows between **Went on to the
post** and **Voted on the post** follow the way from the post to an account,
each a share of the row above it:

* **Saw the sign-in form there** — the sign-in or sign-up dialog opened on
  that post within 30 minutes of going on to it, usually on trying to vote. It
  counts for the latest play of that browser that went on to that post within
  those 30 minutes; a dialog opened on the feed or on another post, and the
  sign-in and sign-up pages, count for none. It's counted whether or not
  **Record visits** is on. Plays from before this row existed count by their
  recorded visit, if it was marked as having seen a sign-up form within 30
  minutes of going on, so paid traffic already shows it. That mark is set
  wherever on the site the dialog opened, since the visit doesn't keep where,
  so it counts only for plays that went on before the game's first sign-in
  form recorded on a post; from then on only the dialog on the post counts.
  The figures of plays from before that first form may include a dialog
  opened elsewhere — on the feed or another page — within the 30 minutes.
  Someone who signed up on the post counts as well, since signing up takes the
  form.
* **Signed up there** — made a new account starting from that post within 30
  minutes, credited to the play.
* **Confirmed that account** — confirmed the email, or signed up with Google
  or Facebook, within 7 days. An account never confirmed is removed after 7
  days, so it drops out of **Signed up there** as well.

The logged-in funnel has none of these rows: players already have an account.

Under the funnel, **Sign-ups** shows for anonymous visitors how many signed up,
how many confirmed, how many never confirmed and how many are still inside the
7 days they have to. An account never confirmed is removed with everything it
did, so the game's own figures lose it; these come from the game's visits that
Traffic sources recorded instead, confirmed counted from the same visits, and
can differ from **Account confirmed**, which counts accounts by the play they
are credited to. While Traffic sources → Sources → **Record visits** is off
there is nothing to count from: the card shows dashes and says where to switch
it on.

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
