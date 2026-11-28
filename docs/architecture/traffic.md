# Traffic sources

Growth → Traffic sources tells where visitors come from, what they cost and
what they bring: paid clicks from ad networks and placements, unpaid visits by
channel, the accounts they become, the worth of every step of a game, the
postbacks that tell networks about it, and the sites and zones worth blocking.
It is written to be dark until the administrator switches it on:
`traffic_settings.capture_enabled`, `site_capture_enabled` and
`postbacks_enabled` are false on a new installation, and while they are,
nothing is recorded, no visitor cookie is issued for the tracker, and nothing
is sent to any network.

The same visits are what Growth → Analytics counts people from — every visit,
the tracker's own and the rest of the site's — and it adds no table of visits of
its own. Growth → Experiments reads none of them: only a game's assignments and
attempts and the accounts the tracker credits to them (`account_attributions`),
so it works the same with visits recorded or not (docs/architecture/analytics.md).

Layers follow docs/architecture/http-and-database-boundaries.md: pure logic in
`App\Support\Traffic\{Capture,Links,Presets,Postbacks,Worth,Spend,Reports,Blocking,Money}`
with no config, clock or I/O; HTTP in `App\Services\Traffic\{Postbacks,Connectors}`;
every write in `App\Actions\Traffic\*`; reads in `App\Queries\Traffic\*`.
Background work runs as scheduled commands, never on the one shared queue
worker. Money is US dollars only, as exact decimals (`TrafficMoney`).

## Capture

`CaptureTrafficArrival` (web middleware, GET only) records a paid click before
any route's own middleware can send the visitor elsewhere: an address carrying
`rg_src` with a source's key. Without `rg_src`, a game page or a game's result
page carries its unpaid arrival in an encrypted token: the channel it was
reached through, the referrer's host, the page's route and path. While Record
site visits (`site_capture_enabled`) is on too, so do the site's own pages —
the feed, a post, a profile, the static pages, signing in and up, saved posts
(`CaptureTrafficArrival::SITE_ROUTES`) — when asked for as a page; one that
turns out to be none, a redirect or an error, takes back the cookie it gave.
The administration, a game's preview, a game page opened by a valid test link
to one of its versions (`VersionTestLink`, docs/architecture/games.md "Test
links"), share redirects and the health check are never among them, and any
other page reads nothing at all. Both switches come
from one read of the cache (`TrafficSetting::captureFlags()`), kept until the
row is saved again, so with recording off such a page asks the database
nothing. Only a game's result page reached from outside the site is a shared
result; the feed reached with no referrer is `direct`, from a search engine
`search`, from another site `referral` and from the site's own pages
`internal`. One network click ID is one visit (`traffic_visits` unique on
source and click hash); a token the network left unfilled counts as no value; a
click with an unknown campaign creates the campaign, an archived one comes
back. Unpaid
visits are created only by the page's first script call (`OpenTrafficVisitAction`
through an encrypted arrival token or the game's `wire:init`), so a crawler that
runs no script leaves no row. A page that is no game hands its token back from
`resources/js/traffic.js` once the page is shown, and again for each page a
`wire:navigate` move brings in, every token once; `POST /visit/signal` takes 60
signals a minute from a browser, by its cookie, and 120 from an address without
one (the `traffic-signal` limiter). The visitor's browser is the
`rg_game_visitor` cookie's SHA-256 — the same as the games use.

Every visit belongs to a session: `traffic_visits.entry_visit_id` is the visit
the session began with, and where that one came from — its source
(`source_key`, `App\Support\Analytics\AnalyticsSource`: `paid:{source_id}`, a
known network and the class of its traffic, an unknown host, `shared`,
`direct`, `internal`), campaign (`campaign_key`), creative (`creative_key`) and
landing page (`landing_key`, `LandingRoute`: route, game, version) — is copied
on each of its visits as it is recorded, so a read groups and counts people by
a column of the visit itself. A paid click always begins a session of its own.
An unpaid arrival continues the session of the browser's last unpaid visit
when it moves inside the site or opens a page with no referrer, while that
visit was seen less than `analytics.session_minutes` ago (`SessionRule`): the
same visit again on the same game, a new visit of the session otherwise.
Another site's referrer, a search or a result page reached from outside the
site or with no referrer begins a new session, while a result page reached
from the site's own pages continues it; a page the session entered by,
reached again from the same place — a reload — is the same visit. Every page
of the site that shows no game is one visit of its session, the session's
pages without a game sharing it, so a session leaves at most one such visit
(an arrival from another site, a search or with campaign tags of its own
begins a new session, and with it a new one); a game
reached from the feed is a visit inside the site, entered by the feed. While a
paid click holds the browser, such a page records
nothing and leaves the click as it was — it neither opens it nor keeps it from
settling. Channels are found as before. A signed-in member of staff or Persona leaves no unpaid
visit, while paid clicks are recorded for everyone: the owner
checks the networks' links. The page's path, route and referrer host travel in
the arrival token, since the call that hands it back is made from another
address, and the visit's details keep the page's own path. How the keys are
made, read and labelled is in docs/architecture/analytics.md, "Sessions and
entry keys".

The campaign tags of the address a session entered by — `utm_source`,
`utm_medium`, `utm_campaign`, `utm_content`, `utm_term`
(`App\Support\Analytics\UtmTags`: trimmed, without control characters, at most
120 characters, a network's unfilled placeholder counting as none) — travel in
the arrival token too, cut to one length when they would make it longer than
is read back, and are kept on each visit of the session for as long as the
visit (`traffic_visits.utm_*`). They name the session's source with the
referrer: `utm_source` names the network, `utm_medium` the class of its
traffic, so a tagged and an untagged visit from Reddit share `reddit:organic`
while `google` tagged `cpc` is `google:paid`. `utm_campaign` is the campaign
(`tag:{utm_campaign}`), `utm_content`, else `utm_term`, the creative. An
unpaid arrival with tags of its own other than the session's begins a new
session, and a page reached again from the same place counts as a reload only
with the same tags. A paid click keeps the tags its address carries too, while
its keys stay those of its source, campaign and creative.

The posts a browser opens and shares go on the visit it is on
(`RecordVisitPostAction`): the paid click holding it, else the visit it was
seen on last within `analytics.session_minutes`; with neither, while visits
aren't recorded, or for a crawler, nothing is written. A visit to the rest of
the site that one of them goes on is seen again, so its session lasts while
the visitor reads in the feed without loading a page; a paid click or a game
visit is seen only by its pages, as before. One row a visit and post
(`traffic_visit_posts`) counts the times it was opened, the moment it was first
shared and whether it was voted on or commented on there. A post is opened
through its own page — its id travels in the arrival token and counts when the
page's script hands it back, or, on a page given no token while Record site
visits is off, as the page is drawn — the feed's detail column in the split view
(`FeedPage::selectPost()`) and every overlay (`PostDrawer::setSelectedPost()`),
once a request however many of them answer one click. A share through
Facebook, X, Telegram, WhatsApp, Reddit or Pinterest is counted by the site's
own redirect (`SharePostController`); by email, a copied link or the system's
share sheet by the script's `post_shared` signal, which names a published post
or is refused. A post shares once on a visit, however often.

## Accounts

A new account records, as it signs up, what it will be credited to
(`RecordAccountSignupAction`): the last game its browser finished within 7 days
that no other account took, and the paid click holding the browser — within its
source's window, 2 days for PopAds, 7 by default — else its latest unpaid
visit to a game of those 7 days, else its latest unpaid visit, so the feed or
the sign-up page visited after a game never takes the account from it. The
same write copies what Analytics → Retention groups and filters the account
by from what it credits — the visit's source key and device, the attempt's
game (`credited_source_key`, `credited_device`, `credited_game_id`,
`AccountAttribution::creditedKeys()`) — so Retention never reads a visit; a
click passed to another browser before any page of it ran takes its new
device to an account already credited to it. A
sign-in and a sign-up form shown go, the same way, to the visit of the day the
tracker counts before any visit to the rest of the site
(`VisitorVisitQuery::latestTrackedFirst()`). A click whose window had closed is
told the account came late. The account counts
once confirmed (`ConfirmAccountAttributionAction`): an email confirmed, a claim
with a confirmed address, signing up through Google or Facebook, or linking one
later. Unconfirmed accounts are purged after 7 days and never count. Signing in
to an existing account marks the visit known — unless it brought an account or
was a known visitor's already as it came — and reaches the goal "Account
confirmed". A first post within 30 days and a return on another day are the
account's bonuses.

A member who votes on a post or answers a rating question for the first time,
comments or replies, posts, or starts a game while signed in has that day
recorded (`RecordUserActivityAction`, `user_activity_days`): one row a UTC day
with a flag for each, and the day of the account's life — whole UTC days since
the day it signed up. Personas and staff record nothing, and Content Studio's
votes never reach it. Doing something on day 1 to 7 (`analytics.activation_days`)
activates a new account once: its attribution gets `activated_at`, and once the
account is confirmed its credited visit gets the Activated mark — counted, never
told to a network. The day it signed up never activates it. Each new vote,
comment or reply and post also counts on the visit of the browser it came from,
found as for a post opened (`traffic_visits.votes`, `comments`,
`posts_created`), the first of them keeps its moment there (`acted_at`), and a
vote or a comment marks that visit's row of the post.

### Registration steps

Between the sign-in dialog and an account a visit records the steps the
registration funnel counts, each once, from the deploy that added them on —
nothing is filled in for the visits before. `RecordRegistrationStepAction` puts
them on the visit the browser is on — the paid click holding it, else the
visit it was seen on last within the session (`analytics.session_minutes`) —
while visits are recorded and the visit is no crawler's; a game's own form
goes on the game's visit. The first moment of each step stays, and the first
opener of the dialog and the first provider gone on to with it:

- `auth_opened_at` and `auth_trigger` — the sign-in dialog opened, or a page or
  a game showed a sign-in or sign-up form of its own, and what opened it first
  (`AuthTrigger`: the header's sign-up or log-in, an upload, a vote, a rating or
  a comment vote by a guest, a game, a game's own form, the standalone pages);
- `register_form_at` — the registration form shown;
- `social_started_at` and `social_provider` — Continue with Google or Facebook
  pressed, a sign-up or a sign-in alike: only the provider's answer tells which;
- `signup_submitted_at` — the email sign-up form sent, whatever came of it;
- `signup_refused_at` and `signup_refusals` — a sign-up refused, and every
  reason the visit met as a bit of `SignupRefusal`: an address already taken,
  any other mistake in the form, the challenge failed, stopped as a likely bot,
  a limit reached, something else. Only a real refusal counts: report mode
  refuses nothing. Never a value the form held.

They are counted only: no step of worth (`TrafficStep::ofEvent()`), no
conversion and no postback, and they never revalue a visit or keep a paid
click from settling.

A session landing on a post's own page keeps that post on each of its visits
(`landing_post_id`, from the arrival's `post`, a paid click's too), beside the
route its landing already keeps (`landing_key`), for the funnel's landing
filter.

## Presets

`config/traffic_presets.php` holds eight networks and "Other ad network" as data:
link tokens, the postback address and how to read the answer, time zone, window,
cost unit and connector. A new source copies them (`SourceDefaults`); only
labels are read live. `PresetSchema` checks the whole file in a test. A new
network starts as a preset checked with a test postback; an API connector is
separate code added later.

## Worth

The tracker counts paid clicks and game visits only — the tracked visits
(`TrafficVisit::scopeTracked()`: `game_id` or `source_id` set). An unpaid visit
to the rest of the site is worth nothing whatever it reaches — its value is set
to 0 without working anything out (`OpenTrafficVisitAction`,
`MarkTrafficVisitAction`) — never enters the chances or the nightly
revaluation, and no read of Traffic sources or of a game's Analytics counts it
(`TrafficReportQuery`, `TrafficLogsQuery` and `GameMetricsQuery`'s visit reads
all go through the scope): those figures, the worth and the postbacks are the same with
Record site visits on as off. Growth → Analytics alone reads every visit,
tracked or not.

`traffic:work-out-worth` works out nightly, from the matured visits of the last
120 days (`TrafficChanceSampleQuery`), the chance that a new visitor who reached a
step ends up a confirmed account, for every slice of game, source or channel,
device and country group, each shrunk towards the slice above while it has fewer
than 30 accounts: `p = (x + 30) / (n + 30 / p0)`. A step is worth that chance
times what an account is worth — its price plus its bonuses by how often they
happen, times its country group's share (`StepWorth`). A sign-in to an account
the visitor already had is an outcome priced on its own: the sign-in price
times the group's share is what the visit is worth so far, and nothing more is
expected of it; it never enters the chances and is never told to a network, the
visitor being a known one. Other known visitors are worth nothing; a value set
by hand on a game's Value tab counts always or while the data is thin — never
for the account or a sign-in, which are priced on Traffic sources → Value: for
the whole site, an account also per paid source. Prices
and country groups are immutable sets: every visit keeps the set it arrived
under, so a price change never recounts the past.

A paid source — an ad network or a banner or link — can have an account price
of its own (`traffic_price_sets.source_account_prices`, source id → price),
because an account from one network can be worth more to the site than from
another. It lives in the set like every other price: saving it adds a set,
saving the site's prices or the country groups carries it over, a blank takes
it away, and a visit keeps the set it arrived under — so a later price for its
source never moves what the visit is worth, and postbacks already sent keep
their value. An account from such a source is worth its own price in place of
the site's, with the bonuses and the group's share on top as ever
(`WorthPrices::forSource`); unpaid traffic always counts the site's price, and
the chances never change — they are probabilities, not prices. The same price
reaches everything that values an account: a step's worth and a visit's
(`StepWorth`, `VisitValue`, `TrafficVisitWorth`), the nightly revaluation,
settling and `{value}`/`{value_total}`, a game's Value tab for that source,
and Traffic sources → Value. Where every source counts at once — a game's
Value tab on All sources, what a new visitor is worth site-wide — the sources'
own prices are mixed in by how many of the slice's confirmed accounts in the
last run came from each, the rest at the site's price (`WorthPrices::mixed`,
`TrafficWorthQuery::pricesFor`), and the tab says so. A visit's worth so far and
still to come (`VisitValue`) is kept on the visit and brought up to date
nightly. The starting set prices an account $0.50, a first post $1 more, a
return $0.20 more and a sign-in $0, and counts Tier 1 at 100 %, Tier 2 at 60 %
and every other country — a visit whose country isn't known among them — at
30 %.

## Conversions

`MarkTrafficVisitAction` is the one writer of what a visit reached, under a lock
on the visit and inside the transaction of whatever reached it — a game
milestone through `GameMilestoneReached`, an account through the attribution
actions. The first time only: the mark, the visit's worth again, and for a paid
click and an event a network can be told of, a `traffic_conversions` row with
the step's worth at that moment and a postback waiting for each rule on for it,
unless `PostbackPlanner` gives the reason not (known visitor, source not active,
no click ID, window or click ID past, no rule, or a settled visit that didn't
get as far as its rules ask). While Send postbacks
(`traffic_settings.postbacks_enabled`) is off, the conversion is still written
but no postback is queued for it, and switching postbacks on later does not
send it. While a paid visit can still settle, every mark
but settling counts as the visit seen (`last_seen_at`), and so does every round
answered; a late mark — an account, a return, a first post — leaves it alone.

## Settling

A network that learns from one conversion per click is told of a visit once it
came to rest: its account confirmed, 30 minutes without a new step, or 24 hours
after the click, whichever comes first (`config/traffic.php`, `settle`). The
account settles it in the same transaction; `traffic:settle-visits` settles the
rest every minute, at the moment each came to rest, and leaves alone a visit
older than twice the settle hours. A settled visit is worth the best step it
reached — the account's worth once it has one, a sign-in's once its visitor
signed in, though a known visitor's is never told — which is also what
`{value_total}` says. A Visit settled rule can ask for a visit that got at least
to a step (started, halfway, finished, the flow's goal, an account; a step
higher up the list, an account above all, meets it too): a network
that counts conversions rather than bidding on value learns nothing when every
visit is one. A network can't change a conversion it was told of, so a later
account is told to it only by a rule of its own on another goal.

## Postbacks

`traffic:send-postbacks` sends the queue every minute from the table itself: a
batch claimed under a lock, sent eight at a time outside any transaction, each
answer written back only if the claim still stands; retries after 1, 5 and 30
minutes, 2 and 9 hours. A network's answer is read by the source's response
rules (ExoClick answers 200 to everything). Addresses go out as safely as an
import (`UrlImportValidator::validateFor`): http or https on 80 or 443, every DNS
answer public, the connection pinned to the checked IP, no redirects, no proxy,
16 KB of answer at most. The click ID is filled in only at sending, so the log
never keeps it in the address.

## Spend

A click is taken to cost what its campaign, else its source, counts
(`ClickSpend`). A day's figures — from the API, a file, a typed total or a
placement's fixed price — are counted from one origin, a typed total first, and
`traffic:allocate-spend` shares them over the visits they cover, narrowest figure
first, so the visits add up to the figures exactly (`SpendAllocator`). A day is
the network's day, in the source's reporting time zone, which cannot change once
visits came through it.

## Connectors

`App\Services\Traffic\Connectors`: PopAds and ExoClick, each checking its key
and reading spend and campaigns and editing block lists through the interfaces
it implements — `EditsBlockLists` extends `ReadsBlockLists`, whose
`blockLevels()` and `blockLists()` read campaigns' lists and never write one;
the sync's read of the lists is given a `ReadsBlockLists` alone. Keys are kept in `traffic_api_connections.credentials`
(`encrypted:array`, hidden, redacted from logs); a backup restored without the
same `APP_KEY` makes them unreadable, and the connection asks for the key
again. Neither connector ever changes a bid or a budget or touches money, and
each is unverified until checked on a real account
(`config/traffic_presets.php`, "connectors"): an unverified one reads and
blocks when an administrator asks, never by the rule alone. PopAds is
checked — its days' impressions and spend, by campaign, matched its own
report in UTC to the cent; ExoClick waits for the owner's token.

PopAds replaces a campaign's website list whole and refuses one to exclude
without a site (a validation error, which PopAds sends with HTTP 500): the last
site taken off turns the campaign's targeting off (`mode=none`) instead, and a
list shared through `global` targeting that would be left empty is left alone
(`TrafficConnectorFailure::SharedList`). A campaign with its targeting off
excludes nothing, whatever sites PopAds still keeps saved for it: a block
added there is the only site the list then excludes — the saved ones are
never excluded again — and taking one off writes nothing. An error PopAds explains in an
`errors` list is a refusal (`Rejected`), whatever its status — never an API
that is down. A list read without its sites, or with an entry that is no
site ID (`UnexpectedResponse`: never a blank read as every site taken off),
or a campaign listing what to allow rather than what to block
(`Unsupported`), is never rewritten; the answer to a change is judged by the
site IDs it holds, any other entry left out, so a change that went is never
taken for one that didn't; reading
the lists alone leaves such a campaign out, as it does one the network
doesn't know. A change is judged site by site, or zone by zone, from the
list the campaign reads back ("Blocking").

## Sync

`traffic:sync-networks` is the one process that calls networks' APIs: waiting
blocks first, then each connection due — every five minutes — today and
yesterday's spend in the network's day, a day closed once its figures came
whole, up to 30 days back after connecting, and the campaigns' own details,
only for campaigns RateGuru saw a click of, so a new campaign is named within
minutes of its first click; and every six hours (`block_lists_read_at`) each
running campaign's block list, read and never written
(`ReadTrafficBlockListsAction`, "Blocking"). A list that can't be read — an
answer of a shape RateGuru doesn't know, the API down for that call — changes
nothing and fails nothing but itself: the other campaigns' lists are read and
recorded all the same (`BlockListsRead`), but for those after an API that is
down, slow or busy, which wait for the next read rather than cost a call each
that would fail the same way. The first such failure is reported and kept on
the connection — why (`block_lists_error`, a `TrafficConnectorFailure`) and
whose list, by the network's campaign ID (`block_lists_error_campaign`, which
the connector names on the `ConnectorException`) — until the next read reads
the list again and clears it once it goes through; the source's Blocking pane says "Couldn't
read EU popunder's block list at 17 Nov 12:00: …" until then, while the API
stays connected — disconnected, nothing reads it again. A key refused
there fails the connection as anywhere else. Each turn then queues
RateGuru's own blocks for the running campaigns they are missing from
(`QueueMissingTrafficBlocksAction`, "Blocking"), so a campaign that has just
had its first click is sent them within minutes. The same figures again
change nothing: a day is
shared out over its visits again only when the network's figures for it
changed. Failures wait longer each time; a refused
key, an account not in dollars or five failures running need the administrator.

A connector that `ReadsImpressionsByCountry` (PopAds) is asked for the same
days by country, after the turn's spend is stored, into
`traffic_spend_day_countries`, and for three older days a turn from the first
day the API reported (`country_cursor`). That table is never counted as spend.
A failed or unreadable country report keeps the figures already there and
leaves the cursor, and does not count as a failure of the connection.

## Blocking

Sites and zones are blocked by hand — marked, their IDs copied for the network's
list — or queued for the API and applied per campaign. A click the network
still sends an hour after a block is counted. `BlockJudge` calls a site bots,
losing (judged only once 30 accounts are measured) or off to a weak start by
its last 30 days, a pure function of the site's figures, its source's
figures over the same window and the thresholds that apply:

- **Bots**: at least `block_bot_clicks` clicks, and fewer opened the page than
  `block_open_below` % *and* than `block_open_below_average` % of the source's
  own average over the same rows the judge reads — the lower share is the
  line — or fewer than `block_open_floor` % whatever the average: the line is
  `max(floor, min(absolute, relative × average))`. Pop traffic opens the page
  far less often than a banner, so the same absolute share would call a whole
  pop source bots: averaging 40 %, its sites are bots under 20 %. The floor
  (5 % to start; 0 turns it off) catches what a share of the average can't: a
  source that is nearly all bots averages too little to hold its sites to
  anything, and a source with a single site can never fall below a share of
  itself — yet a site that opens the page that rarely never pays back on pop
  prices, bots or not. A floor above the absolute share lifts the line above
  it too. A source with no clicks to average is held to the higher of the
  absolute share and the floor; one none of whose clicks opened the page
  holds its sites to the floor alone, and with the floor off none is bots by
  this rule. `BlockJudge::openBelowFromFloor()` and `openBelowFromAverage()`
  say which of the three the line came from, so the badge's tip and the
  source's Blocking pane name it.
- **Losing**: ROI below `block_roi_below` % once the site has cost enough:
  `block_target_multiple` × the target price of an account of the campaign
  its clicks came through (a site in several campaigns takes its source's),
  else of its source (`target_cpa`), else `block_min_spend` spent or
  `block_min_clicks` clicks. Only once the worth stands on 30 accounts, and
  not before `block_grace_days` days after the site's first click in the
  rule's 30 days (`MIN(arrived_at)`; the rule reads it in the same pass as
  its figures): accounts confirm within 7 days, and the worth already counts
  what the steps reached are expected to bring. A site first clicked on the
  window's first day waits for no days — the window can't see further back,
  so it was most likely there before it opened, and counting from there
  would keep a long-losing site waiting for ever once the days come near
  30. A site that would be losing inside those days is `waiting` — Below
  the line in Sites & zones, a "Judged from …" badge, never blocked. The
  report judges its period's figures but takes each site's and zone's first
  click from the rule's own 30 days (`TrafficReportQuery::firstClicks()`,
  one grouped read a render), so a short period never shows a long-running
  site as new.
- **Weak start**: whenever the money doesn't judge the site — below its
  spend to judge (`block_min_spend` or `block_min_clicks`, or its target
  multiple) or while the worth isn't measured — at least
  `block_start_clicks` clicks and, of those who opened the page, the game
  started below `block_start_percent` % as often as on the source. Bots and
  a weak start wait for neither the days nor the target; once a target is
  reached and the worth is known, the money's verdict takes the place of a
  weak start. Pop traffic brings few accounts, so the worth can stay
  unmeasured for months while a site costs far more than its gate: it is
  held to its start all that time rather than left too early to judge.

`BlockRule` holds the thresholds: the general ones on `traffic_settings`, and
a network source's own — the same column names on `traffic_sources`, null
keeping the general one — merged per source (`BlockRule::effective()`), with
one set of validation rules for both editors. The rule, Sites & zones, the
report's tree, Worth blocking and Campaigns' counts judge each site by its own
source's thresholds and target, row by row in a view of every source; each
badge's tip names the values that applied, the source's average among them.
`traffic:block-losers` queues bots and losing ones by itself
every five minutes for an active source set to, with a verified API that
blocks its level: only those with a click in the last 3 days, the most spent
first, within the source's daily cap (a UTC day), never one blocked or queued
already, nor one an administrator unblocked in the last 30 days — in RateGuru,
or by taking it off every campaign's list in the network's own panel, as a
read of the lists saw it (below). An administrator can still block it again.
`traffic:sync-networks` applies them within a minute. A run with no such
source reads no figures. `TrafficBlocksQuery` reads what the rule and a
source's Blocking pane share: the source's blocks counted by level, maker and
network state in one query, the rule's blocks today, the sites and zones
unblocked lately (`unblockedLately()`: an administrator's `unblock` entries in
`traffic_block_events`, and the `gone` entries of `network_panel` a read wrote
for what was taken off every list) and the accounts the worth stands on; and
the running campaigns RateGuru's own blocks are still to be sent to
(`missingFromCampaigns()`, below). The rule's blocks today are summed from its own `queued` entries in
`traffic_block_events`, which an unblock leaves in place, so a block taken off
again never frees room under the cap the same day. Sites & zones lists a block
in force that sent no click in the period too, with no figures, while the
filters go no narrower than the source, so its Blocked segment matches the
pane. Its Select all ticks every row that matches, however many; Block and
Push again split the ticks by source and level, and each group into batches
of `BlockTrafficRefsAction::MOST_REFS` (200), the most the action takes at a
time, each batch its own transaction and line of the log. When the rule last
ran is kept in the cache (`TrafficSetting::blockRuleRanAt()`), not on the settings row, whose saves are
held to their `lock_version`.

### Where a block stands on the network

A block sent through the API is for every running campaign of its source, or
the one it names. `ApplyTrafficBlocksAction` sends a campaign one change with
all its sites or zones and judges each of them on its own by the list the
campaign reads back; where each stands on each campaign's list is kept in
`traffic_block_listings` (`TrafficBlockListing`: `listed`, and `error` — the
`TrafficConnectorFailure` a campaign refused it with, `taken_off` once taken
off in the network's panel, or, on a list still holding it, why taking it off
failed). A call the network refused changed nothing: a list that held a site
holds it still. One that failed only because the network was slow, down or
busy (`TrafficConnectorFailure::passes()`: `timeout`, `unavailable`,
`rate_limited`) may have landed all the same — the write gone through, its
answer or the read back lost — so the listing it leaves may be on the list
(`TrafficBlockListing::mayBeListed()`). `BlockStanding::of()` turns the listings into the block's
`network_state`, so applying and reading judge alike:

- `applied` — on every campaign's list it is for;
- `partial` — on some, not all: "Partly blocked · N of M campaigns", its tip
  naming each campaign it isn't on and why. It is neither applied nor failed
  in the Blocking pane, which counts it on its own (`partial`);
- `failed` — on none, with the reason (`no_campaign` when the source had none
  to send it to);
- `taken_off` — on none, a list having held it before: taken off in the
  network's panel.

A block found in the network's panel (below) is `applied` while any list
holds it, never `partial`: RateGuru never sent it to the others.
`network_error` keeps the reason to show: why taking it off failed where a
list still holds it, else why a campaign refused it. Unblocking takes a block
off the campaigns whose lists hold it, or may — an add that failed only
because the network was slow, down or busy; while none of its listings is
known, as for a block from before listings were kept, every running campaign
it is for, or the one it names, ended or not — and deletes it once no list
holds it; where one refused, it stays, `partial` or `applied`, its badge
saying the unblock failed. A block whose listings show no list that holds it
or may — every campaign refused it — goes at once.
The batch's outcome — listings, states and its line of the log — is written
in one transaction on the source locked, then its blocks, the order every
writer of blocks takes them in (`LocksTrafficRecords`), so an unblock, a push
or a read of the lists waits for it, or it for them. The network is called
before that transaction, so a block may be unblocked, blocked again or pushed
again meanwhile. Every write that queues a block — blocking again, Push
again, an unblock, a mark for the campaigns missing it — counts one more
request (`traffic_blocks.request_version`), and the batch settles a block
only while it is still the request the batch read. One unblocked and blocked
again while the network was being called is `pending` as the batch read it,
yet a request of its own: it stays `pending` for the next batch, which sends
it everywhere it is for, the campaign that refused it included. Only a block
off every list is deleted whichever unblock asked for it — nothing was sent
for it in between. **Push again** (`BlockTrafficRefsAction::push()`)
sends ticked blocks back to `pending`, to every campaign they are for, and
an administrator blocking again through the API does the same for a
`failed`, `partial` or `taken_off` block, whoever made it — the rule never
sends a block again; a block marked by
hand, or found in the panel, pushed again becomes RateGuru's own (`api`).

RateGuru's own blocks sent through the API (`api` and `auto`; never one marked
by hand or found in the panel) are meant for every running campaign they are
for, so a campaign that comes later doesn't leave them partly blocked. Each
sync turn, after the campaigns and their lists are read,
`QueueMissingTrafficBlocksAction` asks `TrafficBlocksQuery::missingFromCampaigns()`
— one query — which running campaigns each `applied`, `partial` or `failed`
block lacks: no listing there (a campaign that came after it, its first click
or one back from the archive), or one not on the list with nothing said
against it (a list read without it). A listing that failed only because the
network was slow, down or busy (`timeout`, `unavailable`, `rate_limited`) is
one of those once a read of that campaign's own list finds the block isn't
there: the read clears the failure, the add didn't land. So a passing failure
is tried again once a read of the lists, never in a loop — and never while
that campaign's list can't be read, when the add may have landed and an
unblock still takes the block off there (`mayBeListed()`). Never one whose listing
records a refusal or any other failure, nor one taken off there in the panel (`taken_off`,
the owner's doing); never a block `taken_off` every list, one whose unblock
failed on a list still holding it — an administrator wants it off — nor one
whose listings aren't known yet, which waits for the read to know them, but
for one sent while the source had no campaign (`no_campaign`). On the source
locked, then the blocks, it marks each such listing `pending`
(`TrafficBlockListing::PENDING`), sets the block `pending` and logs one
`queued` line of `system` a level, which the rule's cap doesn't count.
`ApplyTrafficBlocksAction` then sends a block with marked listings to those
running campaigns alone — a call a campaign, with only what it lacks — keeps
where it stands on the others, a refusal and the `partial` that goes with it
included, and judges the batch's line by the campaigns it sent to. No mark
outlives the batch: one for a campaign that ended meanwhile, or on a block
unblocked since, goes with it, and `BlockStanding` never takes one for a
refusal. A batch whose blocks were marked only for campaigns archived before
it went sends nothing and logs nothing: they are back where they stood. A
batch that went part of the way is logged with `partial` beside `ok`, its note
counting the campaigns that took all of it. A click through a campaign whose
listing says it doesn't hold the block is no blocked click
(`RecordBlockedHitAction`).

### What the network's panel blocked

Every six hours the sync reads each running campaign's block list at every
level its API blocks (`ReadsBlockLists::blockLists()`, a second between
PopAds campaigns) and records it in one transaction on the source locked,
writing nothing to the network. A block that changed while its lists were
read — sent, sent again or unblocked meanwhile, by another sync whose lock
ran out or by an administrator, or deleted and blocked again: its id, state
and `request_version` are no longer those the read began with — stands newer
than the lists read and is
left to the next read, and a site of a block deleted meanwhile is never
taken for one blocked in the panel:

- a site or zone on a list that RateGuru has no block for was blocked in the
  network's own panel: it becomes a block of method `panel`, `applied`, with
  a listing for each list holding it — none for a campaign that never did,
  so a click through one, or one naming no campaign, isn't counted as still
  sent. It counts as blocked
  everywhere a block does — the Blocked segment, Worth blocking, the rule,
  which leaves it alone — and its badge says "Blocked on PopAds", "Blocked in
  the PopAds panel, not by RateGuru", with how many campaigns' lists hold it
  (counted with the blocks on each render, never loaded). Once no list holds
  it, it was taken off there, and goes, whatever an unblock that failed left
  it as;
- RateGuru's own block keeps where it stands on each list it is for: a list
  holding it has it (a campaign that came after the block and doesn't is sent
  it, above), one that held it and holds it no longer had it taken off in the
  panel — marked `taken_off`, never put back, which would undo what an
  administrator did there. A campaign that ended without taking
  it is no longer one it is for: its listing goes, so a refusal there never
  keeps a block `partial` — but not one taken off there in the panel, the
  owner's doing, kept so that neither that campaign, back from the archive,
  nor another one for its going, is sent the block; nor one the network may
  hold it on all the same (`mayBeListed()`), kept so an unblock takes it
  off there. A block from before listings were kept starts from
  what it said — an unblock that failed, on the lists holding it, a refusal
  on those that don't;
- a block only marked here, or on its way to or off the lists, is left to its
  own way.

RateGuru takes nothing off a list that it did not put there, but for a block
an administrator unblocks: a `panel` block unblocked comes off the lists that
hold it, as any other. A PopAds list shared through `global` targeting is
read for each campaign that shares it, and left alone when it would be left
empty, as before. The log says what a read found (`found`) and what it saw
taken off (`gone`), lines of `network_panel` (`TrafficActor::NetworkPanel`)
naming every site or zone each is about in `refs`, 200 a line: taken off
every list it was on — a block found in the panel gone, or RateGuru's own now
`taken_off` — which the block rule leaves to the owner for 30 days, as it
does a site an administrator unblocked, "Taken off in the panel" in Logs →
Blocks; or taken off some lists only (`partial`), "Taken off some lists in
the panel", still blocked on the others. ExoClick reads its campaigns'
blocked zones the same way, unverified like the rest of its connector.

## Reports

`TrafficReportQuery` reads every figure straight from the visits of a period of
network days — the visits the tracker counts, conditional sums only, every
value bound, every column named by `TrafficDimension`. `ReportTree` draws a level as its ten biggest groups and the
rest; `TwoProportionTest` compares a game's two best versions on paid traffic.

Sign-ups are read from the visits too (`SignupFigures`), since a purged
unconfirmed account takes its attribution and its plays with it: signed up,
confirmed, never confirmed — signed up at or before
`UnconfirmedAccountsQuery::cutoff()`, bound — and still waiting after it, and
how long after the sign-up the confirmation came, from the two `*_after_seconds`
columns. How accounts come and Sign-ups and confirmation share one definition:
the card's total is the summary's sum, its paid part How accounts come's. A
game's Analytics tab counts its anonymous sign-ups the same way from the game's
visits.

## Reach

`TrafficReachQuery` sets the impressions a network billed beside the visits
that reached RateGuru — Report → On the way here, the reach columns and the
Low reach hint of Sites & zones, Spend → Day by day. The impressions are
`traffic_spend_days.reported_impressions` as the sync (PopAds'
`report_advertiser` by campaign and website) or a file stores them, whatever
the source's spend mode: whether a figure counts toward spend (`counted`,
`matched_visits`) plays no part in the figures, since a source priced by the
cost in each click never counts its API's figures. Only rows carrying
impressions and no clicks count — `reported_clicks` null or 0: where a network
counts clicks beside its impressions an impression may be a view rather than
a visit, never meant to arrive. ExoClick's connector fills clicks whenever its
API returns them, so its campaigns, popunders included, are left out. A
source's day is read from one origin, the first
in the spend order with impressions — a file before the API; a typed total and
a fixed price carry none — so nothing counts twice, chosen over all the day's
campaigns, so a campaign's slices are the ones the whole source counts.

Visits are matched much as `SpendAllocator` shares spend out: per source and
network day, the narrowest figure first — a zone's, a site's, a campaign's,
the source's — takes the visits of its campaign, site and zone that no
narrower figure took, each visit once. Of figures equally narrow, one naming
its campaign, then its site, goes before one for any, so a zone's figure of
any campaign never takes the visits a zone's figure of their own campaign
the same day would. `SpendAllocator` still breaks that tie by figure id
alone, so where two figures equally narrow match the same visits, a visit's
spend and its reach can follow different figures. Visits of a slice the
network did not report count on neither side. Two grouped reads — the figures
by slice, and the visits by source, day, campaign, site and zone with
conditional counts of those that opened the page and started the game — are
streamed with `cursor()`, both ordered by source and network day, and matched
one source's day at a time: a visit group is looked up only under the slices
that day's figures name. `TrafficReachQuery::tally()` folds the slices as they come into
`ReachTally` — by source and campaign the sum, the open days and each site or
zone over its closed days and the others; by source each day and the closed
days together — so the page never holds a list of every slice: what it keeps
grows with the sites reported, not with every day of each.

On one country (`tally(country:)`) the figures are that country's rows of
`traffic_spend_day_countries` and the visits are those located there, matched
the same way. A day whose first origin is a file or a typed total is left out,
since only the API reports countries. The Low reach hint is not judged on a
country: a block takes the whole site.

Sums are kept raw (`ReachFigures`): one slice's visits can outrun its
impressions when the network's clock and RateGuru's cut a day differently, and
the total keeps that. Only what is shown is held to sense: never reached is
`max(0, impressions − reached)` of the sum, reach at most 100 %. What never
arrived cost about the network's amount × never reached ÷ impressions
(`ReachFigures::lostAmount()`), an estimate: no report of a day says what each
impression was billed at.

A day is closed once it is over in the source's reporting time zone and, for
the API's figures, once the sync closed it (`spend_closed_through`) while the
source is connected; a source no longer connected gets no newer figures. The
report counts open days too and names them. The hint reads closed days only:
a site — or a zone, where the network reports zones — with at least
`traffic.reach.min_impressions` impressions and a share reached below
`traffic.reach.below_percent_of_source` % of its source's over the same days
and all its campaigns (`ReachFigures::fallsShortOf()`, compared in whole
numbers, so a share on the line is not below it). It is a state of the report,
`low_reach` — Worth blocking counts it — and never a `BlockJudge` verdict, so
`traffic:block-losers` never acts on it. It takes the place of `early` and
`watch`, never of a verdict (`bots`, `losing`, `weak_start`) nor of `waiting`,
which the rule will judge losing anyway; and of `profit` only where no closed
figure of the site is `counted` — its visits' spend came from the cost in each
click, not from a network amount that already pays for the impressions that
never arrived — or where the site has no visit at all, profitable on a spend
of nothing. Filtered by what a network never
splits its impressions by — device, country, city, game, version — the
report leaves the impressions out.

A site or zone with impressions but no visit at all has no row in
`TrafficReportQuery::zones()`, which reads the visits. Sites & zones, Worth
blocking and the campaigns' "to block" read their places through
`TrafficSourcesView::places()`, which adds every such place the hint picks
out — at least `traffic.reach.min_impressions` impressions over its closed
days, none reached — with no figures, no first click, and the campaign of the
network's figures when there is one (`ReachTally::placeNames()`, which also
names a zone's site). Judged with no figures the rule says `early`, or
`profit` once `block_min_spend` is 0 and worth is measured, and the hint
takes the place of both, so such a row is always `low_reach`. A source whose
closed days reached nothing at all — a tracking link without the site macro,
say — gives `fallsShortOf()` no share to compare with, so none of its places
is added. A zone is added only to the list by zone, and a site only where no
visit carried it, as a site or under one of its zones.
`SiteZoneList::clears()` keeps an unblocked `low_reach` row past the clicks
threshold, since impressions, not clicks, judge it.

Each row of `zones()` also counts its visits with any bot flag and with each
(`BotSignals`; `bot_flags <> 0` and `(bot_flags & ?) <> 0`), read from the
visits only — Analytics' rows of them keep no flags, so the game sums that
share `metrics()` never ask. `repeatAddresses()` adds, in one more grouped
read joined to the visits' details, how many of a place's visits have an
address hash and how many share theirs with another visit of the same place
(`ReportFigures::withAddresses()`); details are kept 90 days, so the share is
of the visits that still have one. Sites & zones shows both as **Flagged** and
**Repeat address**, sortable (`SiteZoneList::SORTS`), and `BotSignals::words()`
names the flags in Logs → Clicks and in a journey's visit step.

The list keeps, past its clicks threshold and search, only the rows whose share
in one column (`SiteZoneList::SHARES`, the share each column shows under its
figure) is above or below a percent (`SiteZoneList::sharePasses()`, rounded
to a tenth of a percent as the columns show it, strictly); a row with nothing
to take the share of is on neither side. "Find in Sites & zones" clears the
share filter with the clicks threshold, so the row it looks up shows, and
only the list reads `repeatAddresses()` — `places()` skips it for the rule's
counts (`withAddresses`). The page keeps the column, the side and the percent in its
address (`share`, `than`, `pct`).

`block_start_clicks` defaults to 1,000 (a migration moved a settings row still
on the first default of 60): a few hundred pop clicks bring a start or two,
too few for a share of the source's.

## Retention

`traffic:purge-expired`, daily: visits, conversions, spend figures, the block
log and members' activity days after 400 days, click details, the posts of a
visit, the post a session landed on (`landing_post_id`, emptied on the visit
kept, its day read again) and postbacks after 90, all but the last 30 runs of
chances. Sources, campaigns, prices and
attributions stay — an attribution whose credited visit is purged keeps the
source key and device copied from it, so Retention keeps the account's
source and device. A purged unconfirmed account takes its attribution, browsers
and activity days with it (docs/architecture/user-lifecycle.md).

## Privacy

The visitor cookie now links an ad click to the account made from it, and is
issued on sign-up and sign-in too while visits are recorded — and, while
Record site visits is on, on every page of the site it records. No consent is
asked for it, so Record site visits ships off and switching it on is the
owner's decision. No IP address is kept: only a keyed hash on the click's
details, for 90 days. A visit keeps its session's source, campaign, creative,
landing and campaign tags for as long as the visit, 400 days; an account's
attribution keeps the source key and the device of the visit it is credited
to — a network and its class or a paid source, mobile or desktop — for as
long as the attribution. The posts a
browser opened and shared on a visit are kept 90 days from the first of them
(`traffic_visit_posts`, `traffic.retention_days.visit_details`) — what a
browser read is personal history to keep short, as the path and referrer of a
click are — and a period reaching further back shows no post
figure at all; the visit's counts of votes, comments and posts live as long as
the visit. The site's privacy page is the owner's to update, with a sentence
for each switch in docs/admin/traffic-sources.md, "Privacy page".
