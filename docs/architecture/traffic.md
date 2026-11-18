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
The administration, a game's preview, share redirects and the health check are
never among them, and any other page reads nothing at all. Both switches come
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
pages without a game sharing it, so a browser leaves at most one such visit
every 30 minutes; a game reached from the feed is a visit inside the site,
entered by the feed. While a paid click holds the browser, such a page records
nothing and leaves the click as it was — it neither opens it nor keeps it from
settling. Channels are found as before. A signed-in member of staff or Persona leaves no unpaid
visit, while paid clicks are recorded for everyone: the owner
checks the networks' links. The page's path, route and referrer host travel in
the arrival token, since the call that hands it back is made from another
address, and the visit's details keep the page's own path.

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
the sign-up page visited after a game never takes the account from it. A
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
`posts_created`), and a vote or a comment marks that visit's row of the post.

## Presets

`config/traffic_presets.php` holds eight networks and "Other ad network" as data:
link tokens, the postback address and how to read the answer, time zone, window,
cost unit and connector. A new source copies them (`SourceDefaults`); only
labels are read live. `PresetSchema` checks the whole file in a test. A new
network starts as a preset checked with a test postback; an API connector is
separate code added later.

## Worth

The tracker counts paid clicks and game visits only
(`TrafficVisit::scopeTracked()`: `game_id` or `source_id` set). An unpaid visit
to the rest of the site is worth nothing whatever it reaches — its value is set
to 0 without working anything out (`OpenTrafficVisitAction`,
`MarkTrafficVisitAction`) — never enters the chances or the nightly
revaluation, and no read of Traffic sources or of a game's Analytics counts it:
those figures, the worth and the postbacks are the same with Record site visits
on as off.

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
get as far as its rules ask). While a paid visit can still settle, every mark
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
it implements. Keys are kept in `traffic_api_connections.credentials`
(`encrypted:array`, hidden, redacted from logs); a backup restored without the
same `APP_KEY` makes them unreadable, and the connection asks for the key
again. Neither connector ever changes a bid or a budget or touches money, and
both are unverified until checked on a real account
(`config/traffic_presets.php`, "connectors").

## Sync

`traffic:sync-networks` is the one process that calls networks' APIs: waiting
blocks first, then each connection due — today and yesterday's spend in the
network's day, a day closed once its figures came whole, up to 30 days back
after connecting, the campaigns' own details every six hours, only for
campaigns RateGuru saw a click of. Failures wait longer each time; a refused
key, an account not in dollars or five failures running need the administrator.

## Blocking

Sites and zones are blocked by hand — marked, their IDs copied for the network's
list — or queued for the API and applied per campaign. A click the network
still sends an hour after a block is counted. `BlockJudge` calls a site bots,
losing (judged only once 30 accounts are measured) or off to a weak start by
its last 30 days; `traffic:block-losers` queues bots and losing ones by itself
every night for a source with a verified API that blocks its level, within its
daily cap.

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

## Retention

`traffic:purge-expired`, daily: visits, conversions, spend figures, the block
log and members' activity days after 400 days, click details, the posts of a
visit and postbacks after 90, all but the last 30 runs of chances. Sources, campaigns, prices and
attributions stay. A purged unconfirmed account takes its attribution, browsers
and activity days with it (docs/architecture/user-lifecycle.md).

## Privacy

The visitor cookie now links an ad click to the account made from it, and is
issued on sign-up and sign-in too while visits are recorded — and, while
Record site visits is on, on every page of the site it records. No IP address is
kept: only a keyed hash on the click's details, for 90 days. The posts a browser
opened and shared on a visit are kept 90 days from the first of them
(`traffic_visit_posts`, `traffic.retention_days.visit_details`), the same as
the path and referrer of a click; the visit's counts of votes, comments and
posts live as long as the visit. The site's privacy page is the owner's to
update (docs/admin/traffic-sources.md).
