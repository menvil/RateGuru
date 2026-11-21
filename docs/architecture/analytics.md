# Growth → Experiments and Analytics

Growth → Experiments compares the versions of a game inside each of its traffic
periods. Growth → Analytics tells where visitors to the whole site come from and
what they do there: the sources and campaigns they arrive by, the pages they land
on, the posts they read, the games they play, the accounts they make and whether
those accounts come back. Every figure on both is counted by RateGuru itself from
what the site records — no analytics service, script or cookie of a third party
is involved, and nothing is sent anywhere — so the page says "Counted by
RateGuru" and never marks a figure as coming from elsewhere.

Both are built on the paid traffic tracker (docs/architecture/traffic.md): the
visits it records, their sessions and campaign tags, the accounts credited to
them. Analytics adds no table of visits of its own; it reads the tracker's.

Layers follow docs/architecture/http-and-database-boundaries.md: pure logic in
`App\Support\Analytics` (sessions, keys, periods, comparisons, cohorts,
timelines, segment rules, tracking link addresses) with no config, clock or I/O;
every write in `App\Actions\Analytics` and `App\Actions\Games\HideGameTrafficPeriodAction`;
reads in `App\Queries\Analytics`, `App\Queries\Games\ComparisonPeriodsQuery` and
`GameMetricsQuery::periodFunnel()`; the screens in `App\Filament\Pages\ExperimentsPage`
and `GrowthAnalyticsPage`, each tab a class of `App\Filament\Pages\Analytics` that
reads only when its tab is open. The rules are plain values in
`config/analytics.php`, never read from the environment.

## What is recorded

### Sessions and entry keys

Every visit belongs to a session: `traffic_visits.entry_visit_id` is the visit
the session began with (`SessionRule`, docs/architecture/traffic.md "Capture").
Where that first visit came from is written on each visit of the session as it
is recorded, in four columns:

| Column | Key | Made by |
| --- | --- | --- |
| `source_key` | `paid:{source_id}` for a paid click; `{network}:{class}` for a network the dictionary knows — `reddit:organic`, `google:paid`, `email:email`; `tag.{utm_source}` for a tag it doesn't know, `host.{domain}` for a host it doesn't know; `shared`, `direct`, `internal` | `AnalyticsSource::keyFor()` |
| `campaign_key` | `paid:{campaign_id}` for a paid source's campaign, `tag:{utm_campaign}` for a campaign named by its tag | `CampaignKey` |
| `creative_key` | a paid click's creative reference; for an unpaid session `utm_content`, else `utm_term` | `OpenTrafficVisitAction`, `RecordPaidClickAction` |
| `landing_key` | the route the session entered by, then the game and the version it showed: `games.play:12:345`, `games.result:12`, `feed`, `posts.show` | `LandingRoute` |

The network of an unpaid session is named by `utm_source` when it carries one,
else by the referrer's host — Reddit, Facebook, Instagram and X by their hosts,
subdomains and Android app ids, Google and Yandex on every country domain,
Bing, DuckDuckGo, and an email by its tag or its medium. The class comes from
`utm_medium`: `cpc`, `ppc`, `display`, `cpm` and anything starting with `paid`
are paid, `email` email, `referral` referral, anything else and no tag at all
organic. So a tagged and an untagged visit from Reddit share `reddit:organic`,
while Google's tagged ads are `google:paid`, a row of their own beside
`google:organic`. A paid click is keyed by its source, campaign and creative
whatever tags its address carries.

Because the keys are on the visit, a read groups and counts people by one
column of the visit itself and never joins the visit the session began with:
someone who came from two hosts of one network counts once for it. How a key
reads on screen — its label, medium (`paid_search`, `organic_social`,
`email` …), group (Paid, Organic, Email, Referral, Unattributed, Inside the site)
and what it was recognised by ("Tagged", "Referrer", "62% tagged · 38%
referrer", from the share of its visits that carried tags) — comes from the
dictionary when it is drawn, so a better label never needs a key rewritten.
What the dictionary learns later does not move past visits: a host recorded as
`host.{domain}` stays that key.

A landing on a game's page learns its version only once the game assigns one;
`LandingRoute::withVersion()` completes the key then. Visits recorded before the
session columns existed were each made a session of their own, their keys
worked out from what they had kept; a visit with no key at all is still
counted, as "Not recorded" or "Visits before landings were recorded", never
dropped.

### Campaign tags

The five tags of the address a session entered by — `utm_source`, `utm_medium`,
`utm_campaign`, `utm_content`, `utm_term` (`UtmTags`: trimmed, without control
characters, at most 120 characters, a network's unfilled placeholder counting as
none) — are kept on every visit of the session for as long as the visit. An
unpaid arrival with tags of its own other than the session's begins a new
session; a link inside the site carries none, so it continues the session. A
tracking link is known by its five tags alone, with no parameter of its own:
the visits of a session that came by a link's tags are the link's, including
the game started and the account made after a link to the feed.

### Site visits and tracked visits

While Record site visits (`traffic_settings.site_capture_enabled`) is on, the
feed, posts, profiles, the static pages, signing in and up, and saved posts are
recorded too, one visit a session for all the pages that show no game. Such a
visit is never tracked: the tracker counts paid clicks and game visits only
(`TrafficVisit::scopeTracked()`), so Traffic sources, a game's Analytics, the
worth and the postbacks are the same with site visits on as off. Analytics reads
every visit, tracked or not, once its page ran in a browser (`opened_at`): a
click whose page never ran is no person.

With Record site visits off, Analytics sees the game visits and the paid clicks:
a visitor who came from the feed to a game is a visit inside the site, there is
no landing on `/`, and Site engagement shows only the accounts. Experiments,
Games, Retention and Tracking links to games work in full either way. With
Record visits off nothing new is recorded at all; Experiments and Retention
still read what playing and the accounts record.

### What visitors do

The posts a browser opens and shares are counted on the visit it is on
(`RecordVisitPostAction`, `traffic_visit_posts`): one row a visit and post with
the times it was opened, the moment it was first shared, and whether it was
voted on or commented on there. A browser's post history is kept 90 days from
its first row — the same as a click's path and referrer — so a period reaching
further back shows no post figure at all rather than a part of one ("Post views
are kept for 90 days"). Each new vote, comment or reply and post also counts on
the visit (`traffic_visits.votes`, `comments`, `posts_created`, and the moment
of the first in `acted_at`), which lives as long as the visit. Where a post is counted opened and how a share is counted is
in docs/architecture/traffic.md, "Capture".

### Activity, activation and retention

A member's day on the site is a row of `user_activity_days` (UTC day, a flag
each for voted, commented, posted and played, and the day of the account's
life — whole UTC days since the day it signed up, worked out in PHP by
`ActivityDay` as the row is written, never by date arithmetic in SQL). It is
written by `RecordUserActivityAction` after what the member did is in, so a
failure there is reported and never spoils it; Personas and staff record
nothing, Content Studio's votes never reach it, and only a new vote counts.
Activity days are kept 400 days and go with an unconfirmed account when it is
purged.

An account is **activated** when it does one of those things on day 1 to 7
(`analytics.activation_days`) of its life — never on the day it signed up,
which is why a sign-up followed by a vote minutes later is not yet activation.
Its attribution gets `activated_at` once, and once it is confirmed its credited
visit gets the Activated mark (`TrafficEvent::Activated`), counted and never
told to a network. An account is **matured** once its days to activate are over:
it signed up before the start of today, UTC, less the activation days — on any
day up to eight days ago (`ActivityDay::maturedBefore()`). An activation rate is
read only over matured accounts; a row none of whose accounts has matured reads
"Too early".

A **qualifying return** is the same: a later day of the account's life with a
vote, comment, post or game played. Having an account, staying signed in or
receiving an email doesn't count. Retention puts confirmed new accounts of
members (never a Persona or staff) into cohorts by the week they signed up —
weeks from Monday, UTC, this one and the eight before, none before the week the
first account was recorded — and counts who came back on day 1, 7 and 30 and on
any of days 1–7 (`RetentionQuery`, `RetentionCohorts`). A cohort is measured on
a day only once that day is over for every account in it; until then its cell
is "not matured", never 0 %. An account's source is the `source_key` of the
visit it is credited to — the key of the session that visit belonged to — its
device that visit's, and its game the game of the attempt it is credited to.
History starts with the first account recorded: there is nothing to backfill.

## How it is read

### People, not visits

Analytics counts people; Traffic sources counts visits. A person is a browser
(`visitor_hash`, the SHA-256 of the `rg_game_visitor` cookie); every total is
`COUNT(DISTINCT visitor_hash)` and nothing stitches one person's devices
together — except Journeys, which follows a member through every browser they
signed up or signed in with (`account_browsers`). In a breakdown a person counts
in each group they came through, so the rows can add up to more than the total,
and each table says so. Votes, comments, posts and spend are counted as made.

New visitors are those not known as they came (`known` false): only they can
become a new account. So the rates are shares of different people, and every
screen names which:

| Rate | Of |
| --- | --- |
| Start rate | every visitor |
| Completion | those who started |
| Registration (accounts) and activation | new visitors |
| Every rate in Experiments | the visitors assigned to the version, whatever they did after |

A row's rate reads "—" until what it divides by reaches
`analytics.row_min_denominator` (30). The same figures behind every tab come
from `VisitorFigures`, so a step of a funnel and the tile beside it agree.

### Periods

`AnalyticsPeriod` reads the days asked for — Today, Yesterday, Last 7, 30 or
90 days, This month, Last month, All time, or a range of whole days — as every
report reads its period: network days (a paid visit's day in its source's time
zone, UTC for every unpaid one), today included. The presets and their days
are every report page's (`ReportPeriod::PRESETS`, `ReportPeriod::presetDays()`),
so Traffic sources and a game's Analytics offer the same ones. All time starts
on the first visit's network day (`AnalyticsVisitsQuery::firstDay()`, looked
up only for All time), never more than `analytics.max_range_days` back: past
that it is the last 93 days, and the days the picker shows say so. A range is
at most `analytics.max_range_days` (93) days inside the 400 days visits are
kept; one longer, backwards or out of reach reads as the default, Last 30
days. Each period is compared with the window of the same length right before
it — This month with the same days of last month, as many as it has, and Last
month with the whole month before; All time with nothing — none once that
would start before the oldest day kept. Today alone is compared with
the day before only as far into it as today has gone: that window carries a
moment (`arrivedBefore`) that `AnalyticsFilter::arrivedBefore()` stops every
read's arrivals at, so no query needs to know. It bounds arrivals only: every
figure is of the visits that arrived in a window, and a visit that arrived
before the moment counts with every step it took, before it or after — the
change says so ("vs people who arrived yesterday by this time"). Bounding
each step by the moment too would mean a time bound per column in every
read. A game's Analytics (`GameAnalyticsWindow`) cuts its window before the
same way and counts the same way: views and plays begun by then, each play
with whatever came of it later. A chart splits a period into
hours of a single day — today's not yet begun marked still to come — days up
to 31, else blocks of seven days from the first, the last as long as the days
left (`FigureChange` says the change; "No earlier data" when the window before
counted none).

### What a tab reads

A visit is wide — every moment of every step, its worth, its settling — and
the people of a period are counted by sorting every visit of it, so Analytics
reads its own narrow rows of the visits instead (`analytics_visits`, one per
visit, the same id, gone with it): the browser as a number, the first 64 bits
of its hash read as a signed integer (`AnalyticsVisitRow::key()`, worked out in
PHP so every engine stores the same); each step a flag rather than the moment;
the account's sign-up only once it confirmed, what decides whether its days to
activate are over; the sums; and every column a split or a filter names. A
second table keeps the first network day each browser's page ran
(`analytics_people`), so whether a visitor of a period had come before it is a
look-up, not a search of every earlier visit. Measured on 4.5 million visits
over 90 days (50,000 a day), with nothing kept from an earlier read: every tab
of 30 days together in about a minute instead of three, of 90 days in under two
instead of seven; the figures of 30 days in about a second instead of eight,
and a game's figures narrowed to a device in under half a minute instead of a
minute and a half.

`analytics:refresh-visits` alone writes them (`RefreshAnalyticsVisitsAction`),
every minute for up to 45 seconds:

- the network days whose visits still change by themselves — from the day
  before the earliest day any time zone is on to the latest one
  (`AnalyticsRollupWindow`) — today's UTC day and later every minute, the
  earlier ones every fifth: each visit is made into its row and compared with
  the one written before, and only a row that changed, or is new, is written,
  so a day read again costs reading it, about two seconds for 50,000 visits;
- the older days something changed — a late mark, a visit opened or counted on
  days after it came, spend shared out again, a click id that came back — each
  writer telling `MarkAnalyticsDayAction`, which keeps the day with a token
  rewritten on every change, so a change made while the day is read leaves it
  marked for the next run;
- until every day has been, the days not filled yet, the earliest first, from
  the first visit's.

Once a night it reads the last `analytics.rollup.recheck_days` (60) days again,
a check that nothing was missed. No index of the rows leads with the browser:
a planner would walk it for the people's order instead of sorting, reading the
table at random.

The same rows serve the posts of Site engagement (`AnalyticsContentQuery`, the
sessions joined by `entry_visit_id`), the visit an account is credited to on
Retention (`RetentionQuery`), and a game's Analytics — its traffic figures
(`TrafficReportQuery`'s game reads, by `game_id` and arrival) and the plays
and visitors a device or country narrows to (`GameMetricsQuery`, which is why
a tracked visit's row keeps its browser hash and when it was last seen).

`AnalyticsVisitsQuery` and the others read these rows only once they are filled as far back
as the visits go, while they were brought up to date within
`analytics.rollup.stale_minutes` (30) and while `analytics.rollup.read` allows
it (`AnalyticsRollupGate`, decided once a request); otherwise it reads the
visits themselves, slower but never behind. The same conditions on the same
days and arrivals give the same figures either way, and its tests read every
call both ways and compare them.

Everything else that keeps a tab quick is deliberate and kept together:

- the session's keys and tags are columns of each visit, so no read joins the
  visit a session began with;
- hours and blocks of days are conditional counts between bound boundaries,
  never date arithmetic or a branch per engine;
- a period is at most 93 days;
- asking for the page reads no visit: the open tab is read once the page is in
  a browser (`wire:init`), and only the open tab, its Source filter's options
  with it;
- every tab reads a bounded number of queries however many sources, campaigns,
  links, segments, posts or visitors there are, each checked by a test;
- the tabs slow to read on a busy site — every tab but Journeys
  (`GrowthAnalyticsPage::READ_CACHED`) — and the Source and Campaign options are
  kept for one administrator for `analytics.read_cache_minutes` (10) minutes
  once read (`AnalyticsReadCache`), keyed by the tab and what in the address its
  figures depend on (a filter the tab ignores is left out); the page says
  "Figures read HH:MM UTC" and offers Refresh, and saving, deleting or restoring
  a segment, or saving a tracking link, drops everything kept, for every
  administrator, so it is read afresh.

Other thresholds, each a product rule in `config/analytics.php`: a city needs
20 visitors for a row of its own, the rest of its country counted together; the
Overview lists the ten sources the most people came from and the rest as "Other
referrals" ("Other sources" when tagged visits are among them); a chart marks
at most six things — a version published, a paid campaign's first click, a
comparison started; a segment needs 100 new visitors before its rate is shown
and its source ten accounts before it gets a verdict; a creative needs 500
visitors before it can be named a campaign's best.

### Filters

`AnalyticsFilter` holds the period, a device or either, a source key and a
campaign key — both together when both are chosen, never one silently winning —
and the path a breakdown has opened, every column of it named by
`AnalyticsDimension`. The Campaign filter lists the campaigns of the chosen
source; choosing a source a chosen campaign doesn't belong to drops the
campaign. Some parts of the page read fewer of the filters, and each says so:

| Part | Reads |
| --- | --- |
| Overview's sources table, Referral performance and Data notes; Site engagement's Compare sources and Content engagement; Campaigns' Paid sources and a paid source's segments | the period and the device |
| Games, Retention | the period and the device; "The source and campaign filters don’t apply on this tab." while one is chosen |
| Experiments, Landings' comparison | none: a comparison reads the visitors each version was assigned |
| Journeys | none: a journey is one visitor's or member's, whatever the period |

### Raw SQL

Each read that Eloquent cannot express without loading millions of rows is a
registered boundary in `tools/phpstan/architecture.neon`, with its reason in
docs/architecture/http-and-database-boundaries.md and a behaviour test:

- `AnalyticsVisitsQuery` — the people of the period's visits, in all, per
  dimension with the rest counted together, per bucket, and who came back,
  from Analytics' own rows of the visits or from the visits themselves;
- `AnalyticsContentQuery` — the posts the period's visits opened, and the
  accounts whose session opened each first;
- `TrafficSegmentsQuery` — a paid source's segments, each click's segment
  worked out once and every segment counted in one read;
- `RetentionQuery` — weekly cohorts as conditional sums over one read of the
  accounts;
- `TrackingLinksQuery` and `ComparisonPeriodsQuery` — literal searches with
  `ESCAPE`;
- `GameMetricsQuery::periodFunnel()` — how far the visitors each period sent to
  each version got.

`JourneyQuery`, `LandingExperiencesQuery`, `AnalyticsMarkersQuery` and
`AnalyticsRollupQuery` are Eloquent alone. Everywhere every value is bound, every grouping column comes
from `AnalyticsDimension` or `SegmentParameter`, and there is neither date
arithmetic nor a branch per engine, so each read means the same on PostgreSQL,
SQLite and MariaDB.

## Experiments

A traffic period is an experiment: each Apply on a game's Traffic tab starts one
(docs/architecture/games.md, "Traffic"), and a period that shares its visitors
between two versions or more compares them. Nothing is set up on Experiments;
there is no experiment of its own to start, pause or stop.

`GameMetricsQuery::periodFunnel()` reads, per period and version, the visitors
the period assigned (`game_assignments.period_id`) and how far each got —
started, completed, saw a result, left an email, reached the flow's goal — and
the confirmed accounts credited to those attempts, of them the matured and the
activated. A visitor assigned again in a later period stays this one's here.
`PeriodComparison` turns that into the comparison, one rule for Growth →
Experiments, Analytics → Experiments, the Landings card and the badge on a
game's Analytics periods:

- every rate is a share of the visitors assigned (intent to treat);
- the reference is the version with the largest share, the lower number among
  equals; every other version differs from it by percentage points (Growth →
  Experiments) and relatively (Analytics → Experiments);
- the primary metric is the confirmed accounts for anonymous visitors and,
  since logged-in players make none, the players who reached the goal;
- the data badge is One version, No visitors yet, Too early until every version
  has `TwoProportionTest::MIN_ACCOUNTS` (10) of the primary metric, then Enough
  data;
- how sure a difference is comes from `TwoProportionTest` (pooled
  two-proportion z-test, 10 conversions on each side): Strong evidence from
  95 %, Ahead or Behind, not sure yet, from 80 % (whichever side leads), else No
  clear difference;
- a rate that can't be read says why: Not in this flow (the version never asks
  for an email), Already signed up (registrations and activation of logged-in
  players), Needs 7 days (no account has matured), No visitors yet.

`ComparisonVerdict` says what the period shows so far: a winner only on strong
evidence on the primary metric (the reference itself when every other version
is strongly behind), Paused while the game of an open period is paused, no
winner yet while it runs, and for an ended period that the versions didn't
clearly differ or that there was too little to tell. `SampleRatioCheck` checks
the assignments against the shares (chi-square, untested below five expected
visitors a version) and flags a split that looks off below p < 0.001.

Delete period hides an ended period (`game_traffic_periods.hidden_at`,
`HideGameTrafficPeriodAction`): Experiments stops listing it and offering it in
the period switcher, its own address shows it with "Hidden from Experiments",
and Undo puts it back. A period still open can't be hidden. Nothing of it goes —
its shares, assignments and attempts stay, a game's own tabs and Landings still
read it — and who hid or showed it is a log line (`games.period.hidden`,
`games.period.shown`). The navigation counts the comparisons running now: open,
not hidden periods of games not archived with two versions or more.

### Splits and test links

A split for a source (docs/architecture/games.md, "Splits for a source") is a
period with a scope: anonymous visitors from one paid source, or one of its
campaigns, get shares of their own, the closest split winning. Each split is
its own comparison period, named by its scope (`TrafficPeriodScopesQuery`:
"Trust Your Taste · Anonymous visitors · ExoClick tests"), and a general period
that ran beside one reads "Everyone else". Only the visitors a split gave a
version are in its period: a visitor keeps their version when they come back
from another source, and stays in the period that first assigned them, so
comparisons never mix. Splits need a recorded click, so with Record visits off
they assign nobody. Value, postbacks and Traffic sources stay per game.

A test link to a version (docs/architecture/games.md, "Test links") plays it
through the preview and records nothing — no click, cookie, assignment,
attempt or event — so it never reaches Experiments or Analytics.

## Segments and tracking links

A segment (`traffic_segments`) is an administrator's slice of a paid source's
clicks by the parameters they carry — site, zone, campaign, creative, device,
country — each condition "is any of" or "is not any of" its values, every one
met (`SegmentRules`: at most 8 conditions of 25 values; a source holds up to 20
segments). A campaign is typed by its id in the network and kept as the id of
one of the source's own campaigns. Its verdict compares its accounts per new
visitor with its source's: Too little data below 100 new visitors or 10
accounts on the source, Strong from 125 % of the source's rate, Block candidate
at 50 % or less, Average between. Saving holds to `lock_version`; deleting only
marks the row, so Undo can bring it back (`SaveTrafficSegmentAction`,
`DeleteTrafficSegmentAction`, `RestoreTrafficSegmentAction`). Blocking stays on
Traffic sources → Report.

A tracking link (`tracking_links`, "TL-{id}") is a tagged address to a game, a
page of the site or a path of its own (`TrackingLinkUrl`: the path's own
parameters kept, any `utm_` ones replaced). Its five tags are unique across
links — `utm_content` and `utm_term` stored as `''` when empty — so the visits of
one set of tags are one link's; another link with the same tags is refused
("Another link already uses these tags: TL-12"). Once a visit came through a
link only its name can change, so what it counted stays its own; edits hold to
`lock_version` (`SaveTrackingLinkAction`). A link can be made before any visit,
and its figures are those of the sessions that came by its tags, as Campaigns
counts them.

## Journeys

A visitor is looked up as `visitor_` and the first hex digits of its visitor
hash — at least eight; a range of hashes, so every engine's index serves it,
and a list to choose from when browsers share the digits. A member is `user_`
and the account's id, their journey every browser they signed up or signed in
with. `JourneyQuery` reads the latest steps of each kind (`JourneyTimeline`
shows at most 200): visits with their page and referrer while the details are
kept, the posts each session opened, versions assigned with the period's
shares, attempts, sign-up and share buttons pressed, the moment an email was
left — never the address — and the account's sign-up, confirmation, activation
and returns. Beside them, the first, converting and last non-direct touch.

A session's posts are one step at the first opened; what its member voted,
commented and made is another, at the moment the visit kept for the first
(`acted_at`). A visit from before that moment was kept says only how many, so
the step is drawn at the earliest it can have been, and says so. A guest can
neither vote nor comment, so that is when the browser came to be signed in
during the session: its first sign-in, or the sign-up when the account was
signed up in that browser (the sign-up's `visitor_hash`), or the sign-up
anywhere when the browser has no sign-in of its own. Otherwise it is the first
post the session opened, or the session's start. If a later visit of the same
session did keep its moment, that moment isn't taken for the first, and the
step is never drawn later than it. A sign-up Google or Facebook confirmed within a minute is one step
with its confirmation.

Every lookup is written down (`LogJourneyLookupAction`, `analytics.journey.lookup`:
who looked, the ID, and whether it was typed, picked or opened from an
address) — once an ID is complete, never what was typed on the way to one.

## Access

Experiments is an active administrator's who may manage games (`manage-games`);
Analytics one's who may manage traffic sources (`manage-traffic-sources`). The
navigation item, every page and every Livewire request ask, and so does every
action. A moderator sees neither.

## Data kept

| What | Kept |
| --- | --- |
| Visits, with their session's keys and tags and their counts of votes, comments and posts | 400 days (`traffic:purge-expired`) |
| The posts a visit opened and shared (`traffic_visit_posts`) | 90 days from the first |
| Members' activity days (`user_activity_days`) | 400 days; an unconfirmed account's go with it |
| Segments, a deleted one only marked | for ever |
| Tracking links, never deleted | for ever |
| Hidden periods | for ever, as every period |
| Analytics' own rows of the visits (`analytics_visits`) | as long as their visit |
| Each browser's first day (`analytics_people`) | 400 days from that day, as the visits (`traffic:purge-expired`) |
| A tab's figures and the filter options, per administrator (cache) | 10 minutes (`analytics.read_cache_minutes`), or until Refresh, a segment saved, deleted or restored, or a tracking link saved |

The privacy page is the owner's to update before Record site visits is switched
on (docs/admin/traffic-sources.md, "Privacy page").
