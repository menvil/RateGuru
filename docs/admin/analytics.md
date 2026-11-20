# Growth → Analytics — operator guide

Growth → Analytics (`/admin/analytics`), for active administrators who may
manage traffic sources. It shows where visitors come from and what they do on
the whole site — games or not — and whether the accounts they make come back.
RateGuru counts every figure here itself, from what the site records; nothing
comes from an outside tool, so nothing needs connecting.

## Before there is anything to see

Analytics counts the visits Traffic sources records:

- **Record visits** (Traffic sources → Sources → Tracking) records game visits
  and paid clicks. With it off, the page says so and has nothing new to count.
- **Record site visits**, right under it, records the feed, posts, profiles and
  the site's own pages too. With it off, Analytics sees only the game visits and
  paid clicks: a visitor who went from the feed to a game shows as "Inside the
  site", the feed is no landing page, and Site engagement shows only the
  accounts. Update the privacy page before you switch it on
  (docs/admin/traffic-sources.md, "Privacy page").

Experiments, Games and Retention work without either switch, from what playing
and the accounts record. The page says from which day visits are recorded, and a
period before that day says it is too early for those days.

## The page

Across the top: the **period**, **Device**, **Source** and **Campaign**, and
the tabs. Everything you choose — the tab, the filters, each tab's own sort and
choices — is kept in the page's address, so a link opens the same view.

- **Period.** Today, Yesterday, Last 7, 30 or 90 days, This month, Last
  month or All time — the same presets as Traffic sources and a game's
  Analytics tab — or click a start day and an end day on the calendar beside
  them. Today is included. A range can be up to 93 days ("Up to 93 days at a
  time"), within the 400 days visits are kept. All time starts on the day of
  the first recorded visit, but Analytics reads at most 93 days at a time, so
  once visits go back further it covers the last 93 days; the days beside the
  period's name say which. Every figure with a change beside it is compared
  with as many days right before the period — Yesterday with the day before,
  This month with the same days of last month ("vs the same days of last
  month"), Last month with the whole month before ("vs the month before"),
  and Today with the people who arrived yesterday by this time ("vs people
  who arrived yesterday by this time"), since the rest of today hasn't come
  yet. The time bounds when they arrived, not what they did next: someone who
  arrived yesterday at 9:00 and finished a game at 15:00 counts as a game
  completer against today at noon, as every figure here counts the people who
  arrived in a window, whenever they went on. All time has nothing earlier to
  compare with, so it shows no changes. The period is always in the address, Last 30
  days too. Opened from an address that names no period, the page shows the
  period you last chose here, unless it is a range older than the visits are
  kept, which opens the last 30 days.
- **Device.** Mobile, desktop or both.
- **Source.** Where a visit's session came from: a paid source by its name; a
  network and the kind of traffic it sent — Reddit, Google (its search results)
  apart from Google Ads, Email — whether the visitor's link was tagged or not;
  Shared results; Direct / Unknown; Inside the site. A site no list knows is
  named by its domain. A chosen source shows as a chip with ×.
- **Campaign.** A paid campaign, or a campaign named by its `utm_campaign` tag.
  With a source chosen it lists that source's campaigns; choosing another source
  drops a campaign that isn't its.
- **Figures read HH:MM UTC.** Every tab but Journeys keeps its figures for you
  for 10 minutes once read, so visits that just arrived may not show yet.
  Refresh reads them again now. Saving, deleting or restoring a segment, or
  saving a tracking link, also reads them again.

A visit counts for the source, campaign and landing page its session began
with: someone who came from Reddit to the feed and then opened a game counts
for Reddit on the game too.

### People, not visits

Analytics counts people — browsers — where Traffic sources counts visits. A
visitor on two days, or with two visits, is one visitor. In a table, a person
counts in every row they came through, so rows can add up to more than the
total, which counts each person once. There is no linking of one person's phone
and computer.

The rates say what they are a share of: the start rate of every visitor,
completion of those who started, registration and activation of **new
visitors** — those not already known as they came, since only they can make a
new account. A row's rate shows "—" until what it divides by reaches 30.

An account is **activated** when it comes back on one of the 7 days after the
day it signed up and votes, comments, posts or plays a game. An account made in
the last 8 days can still activate, so activation reads **Too early** until a
row has accounts old enough.

### Filters that don't apply

Some parts read only the period and the device, and say so: the Overview's
sources table, Referral performance and Data notes, Site engagement's Compare
sources and Content engagement, and the paid sources and segments on
Campaigns. Games and Retention ignore the source and campaign, and say so while
one is chosen. Experiments and Journeys ignore every filter: an experiment
reads the visitors each version was given.

## Overview

- **Tiles**: Visitors, New visitors, Game starters, Game completers, Accounts
  created (confirmed new accounts), Activated users, and Visitor → registration
  and Visitor → activation (both of new visitors), the first six against the
  days before.
- **Acquisition funnel**: landing visitors, game starters, completers, reached
  the goal, saw the sign-up form, signed up, accounts confirmed, activated. Each
  step counts people, not attempts. **See an example journey** opens one of the
  funnel's people on the Journeys tab.
- **Visitors per hour, day or week**: one day by the hour, up to 31 days by the
  day, longer periods by blocks of 7 days. Today's hours not yet begun say
  "still to come" instead of a count. Marked days say what happened: a
  version published, a paid campaign's first click, a comparison started.
- **Traffic sources**: the ten sources the most people came from, then "Other
  referrals", with starts, completions, accounts and activations; **Attribution
  basis** says how each was recognised (a tagged link, the referrer, or both, or
  a paid source's own link). A registration rate well below the table's is
  marked. Click a row to filter the whole page by it, click again to clear it.
  **Sort** by visitors or a rate.
- **Referral performance**: share clicks on game results, results shared, the
  visits that came through a shared result, and how far those visitors went. A
  share click doesn't prove the link was posted.
- **Data notes**: why figures can differ from an ad network's — visits nothing
  names, crawlers and link previews among paid clicks, paid clicks whose page
  never ran, and the spend.
- **Tracking**: whether Record visits, Record site visits and Send postbacks are
  on, the IP database, the network API connections, and since when visits are
  recorded. **Tracking settings** opens Traffic sources → Sources.

## Site engagement

Everyone who came to any page of the site, games or not:

- **Cards**: unique and returning visitors (came on two days of the period, or
  before it), post views and unique post viewers, votes, comments, shares
  started (post shares and result share clicks), accounts and activated users —
  each against the days before, but the post viewers.
- **Website funnel**: website visit → viewed a post → saw the sign-up form →
  account created → activated user.
- **Compare sources**: pick two of the six biggest sources and read them side
  by side — visitors, who viewed a post and who of them voted, votes per voter,
  comments per 100 viewers, returning visitors, and registered and activated of
  new visitors.
- **Content engagement**: the ten posts opened most, with views, viewers,
  votes, comments, engagement (viewers who voted or commented) and the
  registrations of sessions whose first opened post it was; "Not attributed"
  counts the accounts whose session opened no post.

A browser's post history is kept 90 days. A period reaching further back shows
"—" for the post figures rather than part of them.

## Campaigns

- **Where spend comes from**: how each paid source counts what it spent — the
  network's API, the price in each click, your rate, a fixed price, or figures
  you type in — for reading only. Change it in the source's editor.
- **Paid sources**: each ad network and placement with its visitors and spend.
  **Settings** opens the source's editor; clicking the row filters the page by
  it.
- **Segments** (with a paid source chosen): your own slices of the source's
  clicks. **Add segment**, name it and add up to 8 conditions — site, zone,
  campaign (its ID in the network), creative, device or country, each "is any
  of" or "is not any of" some values; a click is in the segment when it meets
  every one. The drawer previews how many visitors the rules catch as you type.
  Each row shows visitors, game starts, registrations, the registration rate,
  activated users, cost per registration and a **Verdict** against the whole
  source: **Strong** at 1.25 times the source's accounts per new visitor or
  more, **Block candidate** at half or less, **Average** between, **Too little
  data** below 100 new visitors in the segment or 10 accounts on the source.
  Segments can overlap; **Not in any segment** is the rest. Segments count past
  visits too, since every visit keeps its parameters. Edit with the pencil;
  delete in the drawer, with Undo. To block a bad site or zone, go to Traffic
  sources → Report.
- **Campaign performance**: a tree from campaigns — a paid source's by name,
  others by their `utm_campaign` — to their creatives, then the games their
  visitors played. Each row has spend (tagged "estimated" while part of it is),
  visitors, cost per visitor, game starts, completion, registrations and
  activated users of new visitors, and their costs. A campaign with no spend is
  **Unpaid**; spend that reached no visit in these days has a row of its own.
  The best creative by activation, among those with 500 visitors or more, is
  marked **Best activation rate**. A game row opens the game on the Games tab.
  **Sort** by visitors, either rate or cost per registration.

## Tracking links

Tagged addresses for your posts, ads and emails, so their visitors show up as
a campaign:

1. **Create tracking link.** Choose where it leads: a **Game**, a **Site page**
   (the home feed, hot or top posts, an active category) or a **Custom** path or
   address on this site.
2. Choose the **Source** (Instagram, Facebook, Reddit, Google, Email, or your
   own name) and the **Medium**, then the campaign, content and term. The name
   is made from them until you type one.
3. Copy the **Generated URL**. Don't add an ad platform's click IDs (gclid,
   fbclid): the platform adds them itself.

After saving, the link's details open with **Copy link**. Each row shows where
the link leads, its tags, and its visitors, registrations and activated users
in the period — "No visits yet" until the first. **View analytics** opens
Campaigns on its campaign and creative, marked under a banner. **Duplicate**
starts a new link from it; change at least the content, since two links can't
carry the same five tags ("Another link already uses these tags: TL-12"). Once
a visit has come through a link, only its name can change, so what it counted
stays its own.

A link counts every visitor whose session began with its tags: a link to the
feed is credited with the game started and the account made after it.

## Landings

One row per page a session began on and what it showed — the home feed, a
post, a game's version, a shared result — with everything the session went on
to do: visitors, start rate, completion, registration and activation of new
visitors. A game's row names the version's flow ("Result First · V7") and what
the version had of the game's traffic in those days ("Only version", "80% ·
since 24 Oct", a split by its source); it opens the game on the Games tab.

Beside it, the comparison started last of those still running sets each version
against the reference; **Experiment results** opens it on the Experiments tab.

## Games

A game's own Analytics — the same cards as its Analytics tab in Growth → Games
(docs/admin/games.md, "Analytics") — for the period and device chosen here.
Pick the **Game** among those not archived; the group, version, country and
**Break down by** are the game's own.

## Experiments

The latest twelve comparisons, newest first, each named by its game, audience
and split; **All in Experiments** opens the full list. For the one chosen:

- its days, shares, primary metric (accounts for anonymous visitors, players who
  reached the goal for logged-in ones) and visitors assigned;
- each version's content set, flow and steps;
- what the figures say so far: a winner only on strong evidence; while the game
  is paused, that nobody new is being assigned; otherwise no winner yet, or for
  an ended period no clear difference;
- every metric of every version against the **Reference**, the version with the
  largest share: its rate, how many of how many, the relative difference, and
  how sure it is — **Strong evidence** (95 %), **Ahead, not sure yet** or
  **Behind, not sure yet** (80 %), **No clear difference**, **Too early** before
  10 on each side, **By design** where a flow never asks for it. The primary
  metric is marked **Primary**;
- whether the split looks off against the shares (a sample ratio check);
- the rates among those who completed the game.

The figures are the same as on Growth → Experiments (docs/admin/experiments.md).

## Retention

Whether new accounts come back and do something — a vote, comment, post or
game on a later day. Having an account, staying signed in or receiving an email
doesn't count.

- **New users**: accounts created in the period. **Returning users**: members
  who came back with an action in it. **Day 1**, **Day 7**, **Day 30**: how many
  came back on that day of their account's life, over every cohort old enough.
- **Registration cohorts**: the accounts of each of the last nine weeks, by the
  week they signed up, and the share back on day 1, 7 and 30. "—" means the day
  hasn't passed for the whole cohort yet — it isn't 0 %; "n < 30" means too few
  accounts for a rate. Pick a chip to cut them by a source or a game.
- **Retention by acquisition source** and **by originating game**: the biggest
  five, then the rest, adding up to the period's new users; a row filters the
  cohorts. The source is that of the session the account is credited to, the
  game the one it finished before signing up.

Only confirmed accounts of members count; Personas and staff never do. The
history starts with the first account recorded.

## Geography

Countries the most people came from — the top ten, then every other country and
"Location unknown" — with each one's share, start rate and registration and
activation of new visitors. Click a country to see its cities with 20 visitors
or more, the rest of the country together. **Sort** by visitors or a rate. A
place is worked out from the IP address at each visit, approximately; the
address itself is never kept.

## Journeys

Look up one visitor's or member's way through the site:

- `visitor_` and the first 8 characters of a visitor ID, or `user_` and an
  account's number. Names and email addresses can't be searched. When several
  browsers share the characters, pick one from the list.
- Without a lookup, the first example's journey is shown; **Examples** offers
  the latest accounts and players without one.
- The timeline: each visit with where its session came from, the posts it
  opened and shared, what its member voted, commented on and published (when
  they first did), the versions the games gave, attempts and how far they got,
  the sign-up and share buttons pressed, an email left (never the address),
  signing in, and for an account its sign-up, confirmation, activation and the
  days it came back. A sign-up with Google or Facebook is confirmed at once, so
  it is one step. Older visits didn't keep when votes and comments happened:
  those are drawn at the earliest moment they can have been, and the step says
  so.
- **Attribution**: the first touch, the touch the account is credited to and
  the last touch that wasn't direct. **Identity**: the visitor ID or browsers,
  the user ID, how many sessions and attempts, and where the latest visit came
  from.

A member's journey joins every browser they signed up or signed in with. Every
lookup is written to the application log with who looked — "Admins only ·
lookups are logged". A visitor ID in Traffic sources → Logs (a click, a
milestone or a postback) opens here with that visitor looked up.
