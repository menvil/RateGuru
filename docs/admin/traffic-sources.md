# Traffic sources — operator guide

Growth → Traffic sources (`/admin/traffic-sources`), for active administrators.

## Switching it on

1. Sources → Tracking: check the IP database says what it serves (Update now
   fetches one within a minute). GeoLite2 City is the default; DB-IP City Lite
   stands in by itself while GeoLite2 has no database file.
2. Switch on **Record visits** and save. Unpaid visits appear under Logs →
   Clicks, with their country and device. **Record site visits**, right under
   it, records the feed, posts, profiles, the static pages, signing in and up
   and saved posts too — one visit a session (a new session starts each time
   the browser comes from another site, a search or a link with campaign tags
   of its own, or after 30 minutes away; a move inside the site or a page
   opened with no referrer — a bookmark, a typed address — within those 30
   minutes continues it), with the visitor
   cookie on every such page and the posts it opens kept for 90 days,
   so update the privacy page first (below). It ships switched off. Those
   visits are for Growth → Analytics (docs/admin/analytics.md): nothing on
   Traffic sources, no worth and no postback changes with them. It can only be
   on while Record visits is.
3. **Add source** → PopAds (or another network). Fill in what the preset asks
   (its note at the top of the editor) and save.
4. Campaigns → Get the link: choose the source and a game, paste the link as
   the target URL of the network's campaigns. Check a link reads a pasted link
   the way the server reads a click.
5. Run a small campaign; its clicks appear, and its campaign shows up by itself.
6. Connect the source's API (PopAds key, or ExoClick API token) in its editor.
   A day's spend closes once the network's figures came whole.
7. Value → What a new user is worth: price the outcomes once for the whole
   site — a confirmed account, its two bonuses, and a sign-in to an account the
   visitor already had (not a new user; $0 until you set it). A game's Value
   tab shows what they make each step worth and links back here for both.
   When an account from one network is worth more (or less) to you than from
   another, type its own price in Value → Accounts by source → **Account
   price**, beside what its accounts brought on average in the period, and
   **Save prices** (the button under the prices or the one under the table:
   both save everything as one new set). Blank gives it the site's price
   again. The bonuses and the country group's worth still apply on top; unpaid
   traffic always counts the site's price. Like any price change it never
   rewrites the past: visits that came before keep the price they came under,
   and postbacks already sent keep their value. A source's editor (Sources →
   the source → Spend) shows its account price, its own or the site's, with a
   link here. On a game's Value tab, picking that source shows its steps at
   its own price; All sources mixes the sources' own prices in as their
   accounts came, and says so.
8. On each game's Value tab, set the other steps by hand while the data is
   thin; then add a postback rule, **Send test**, and switch on **Send
   postbacks**. Send test calls the address with a made-up click ID and says
   what the network answered (its body, or the header a rule reads). PopAds
   refuses every click ID it did not issue, so its test always comes back
   rejected — with `X-PopAds-EC: InvalidEnvironmentException…`, even with a
   wrong aid: check a PopAds rule with a real click's postback in Logs →
   Postbacks. Send a network one event per click on each of its goals: two
   rules on one goal are counted as two conversions and their worth added up,
   and the editor says so.
   For a network that learns from conversions, choose the step its campaigns
   should buy (the flow's goal, or Halfway or Game finished while accounts are
   few). For one that bids on value, choose **Visit settled**: once a visit
   came to rest — its account confirmed, 30 minutes without a step, or a day
   after the click — it tells the best worth the visit reached. Give a Visit
   settled rule a floor (Game started by default) unless the network bids on
   value. Country groups start at Tier 1 100 %, Tier 2 60 %, every other
   country 30 % (Value → Country groups).
9. Block by hand first, then through the API on a test campaign, then let the
   block rule do it by itself (only once the connector is verified).

## Blocking

You block by hand in Report → Sites & zones: tick sites or zones, then
**Block**, or **Copy IDs** for the network's own blacklist. With the source's
API connected the block is queued and reaches the network within a minute;
without it the block is only marked here, and the IDs go into the network's
blacklist by hand. **Unblock** works the same way: a block marked here goes at
once; one on the network's list shows **Unblocking…** until the network has
taken it off, within a minute. Should the network refuse, the site stays
blocked and its badge says **Blocked · unblock failed** with the network's
reason — Unblock again to try again. Taking a PopAds campaign's last sites off
its exclusion list turns that campaign's site targeting off (PopAds keeps no
empty list to exclude); a list shared with other campaigns that would be left
empty is not changed — take its last sites off in the PopAds panel.

**Block bots and losing sites by itself** (a source's editor → Blocking) lets
the block rule do it every 5 minutes, over each site's or zone's last 30 days:
likely bots and losing ones, as Report → Worth blocking sets them — losing only
once the worth stands on 30 accounts. Only those with a click in the last 3
days, the most spent first, up to the source's cap a day. It needs an active
source with its API connected and verified, and an API that blocks the level
chosen; the pane lists what is missing. A site you unblock stays unblocked: the
rule leaves it alone for 30 days. The cap counts every block the rule queued
that UTC day, so unblocking one frees no room for another until the next day.

The rule is gentle with traffic it can't judge yet:

- **Likely bots** needs at least 100 clicks on the site: on pop traffic a few
  dozen say too little. Then it means fewer clicks opened the page than the
  rule's share (50 %) *and* than half the source's own average — or fewer than
  the floor (5 %), whatever the average. Pop traffic opens the page far less
  often than banners: with a pop source averaging 40 %, a site is bots under 20 %; with
  banners averaging 85 %, under 42.5 %. Set the share of the average higher
  (Worth blocking → "of the source's own average") to let the rule's own 50 %
  be the line for banners. The floor is for sources half of whose average
  says nothing: one that is nearly all bots averages 3 %, and one with a
  single site is its own average — below 5 % such a site is bots anyway,
  since on pop prices it can't pay back whoever clicks. Set it in Worth
  blocking ("whatever the average"), or per source; 0 turns it off. The
  site's badge says when the floor was the line.
- **Losing** waits 3 days after a site's first click in the last 30 days,
  so its accounts can arrive; until then such a site shows "Judged from …"
  under Below the line. The report counts those days the same way whatever
  period it shows, so a site losing for weeks isn't "new" on Yesterday.
  Where the click's campaign — else the source — has a target price of an
  account, a site is judged once it has spent twice that target (a $0.60
  target: from $1.20); without one, from $2 spent or 200 clicks. The badge on
  a site says which applied.
- Weak starts and bots wait for neither.
- **Low reach** — paid impressions that mostly never arrive (Impressions that
  never arrive, below) — is only shown and counted in Worth blocking: the
  rule never blocks a site for it, and it never hides the rule's own verdict.

Each network can keep the general rule or set **Own thresholds for this
source** in its editor → Blocking: any field left empty keeps the general
value shown in it, and **General rule** empties them all when you save.
Switch back to **Own thresholds for this source** before saving and they come
back as they were. They are saved with the source. Worth blocking names the sources with thresholds of their own,
and How blocking works on the pane shows the values that apply, marking the
source's own, with its average over the last 30 days.

The Blocking pane shows what the source has blocked now — sites and zones, by
hand and by the rule — and the rule's blocks today against the cap, and when
the rule last ran. With an API connected it shows where the blocks it sent
stand (on the network's list, waiting, failed); without one only how many are
marked here, since RateGuru can't see the network's own blacklist. **See
blocked in Sites & zones** and **Open the blocks log** open the list and
Logs → Blocks for the source in a new tab. The Blocked segment of Sites & zones
lists every block in force, whatever the period: one that sent no click in it
shows with no figures.

## Period

The period picker in the header — the same as a game's Analytics tab and
Growth → Analytics have — offers Today, Yesterday, the last 7, 30 or 90 days,
This month (from the 1st up to today), Last month (the whole of it) and All
time beside a calendar: click the first day of a range, then the last, and the
period is taken at once, with no Apply. All time starts on the day of the
first recorded visit, and goes back no further than visits are kept; the days
beside the period's name say what it covers. Days before the oldest day visits
are kept (400 days) can't be picked. Days are each network's own. The period is
always kept in the page's address (`period`, Last 30 days too, or `from` and
`to` for a range); a range in an address that ends after today, runs backwards
or starts before the oldest kept day opens the last 30 days instead. An
address that names no period opens the period you last chose on this page, and
the last 30 days once that is a range older than visits are kept. Each tab's
link names the period on screen, so a tab opened in a new browser tab shows
that period even if another browser tab has chosen a different one since. A
game's Analytics links here with its own period and device.

## Impressions that never arrive

A pop network such as PopAds bills every impression it shows, yet an
impression becomes a visit only once the page opens: the visitor closed the
window before it loaded, the browser blocked the pop, the connection broke, or
a bot never loaded it. Those impressions are paid for and never reach
RateGuru. The network's API — or its file with an `impressions` column —
brings each day's impressions by campaign and site, whatever the source's
spend mode, so a source priced by the cost in each click has them too. Only
rows with no clicks of their own count here — no clicks column, or 0 clicks:
where a network counts clicks beside its impressions, an impression may be
only a view, never meant to arrive. ExoClick's API fills clicks whenever it
has them, so its campaigns, popunders included, show nothing here.

**Report → On the way here**, under the tiles, shows them beside RateGuru's
own way whenever the period has any: **Paid impressions (network)**,
**Reached RateGuru** with its share of them, **Opened the page** with its share
of those that reached, and **Started the game**, then a line such as "Paid,
never reached: 156 (21%) · ≈ $0.29 of the network’s $1.38". Reached counts the
visits of the day, campaign and site the network reported — its figure for a
campaign with no site counts the campaign's other visits that day — so the
visits of a site or a day the network reported nothing for count on neither
side. Never reached is the impressions less those visits, never below
nothing; its cost is an estimate, the network's amount shared evenly over its
impressions. A day is closed once the network's figures for it are final — an
API day once the sync closes it, six hours into the next day; a file's once
the day is over. The card counts the days not closed yet too and names them:
"Includes today, not closed yet: the network’s figures can lag a few minutes
behind." It follows the source, campaign and type of traffic chosen and the
period. Filtered by device or game the network has no such split: the card
shows only the networks' visits, opened and started, and says the impressions
are left out.

**Sites & zones** shows **Paid impr.** and **Reached** — the share of them that
became visits, with how many — for every site the network reported, sortable
like the other columns; a zone shows them only where its network reports
zones (PopAds reports sites), and any other row shows "—". A site with at
least 500 paid impressions over the period's closed days, reached below 70 %
of its source's share over the same days, is marked **Low reach** and counted
in Worth blocking, its badge saying why: "Paid impressions mostly never reach
RateGuru: 41% reached against 79% for the source, over 1,200 paid impressions
of closed days". It is a hint only: the block rule never blocks a site for it.
It stands in for **Too early** and **Below the line**, never for a verdict of
the rule: a site the rule calls bots, losing or a weak start keeps that badge,
and a losing site still waiting keeps **Judged from …**. It stands in for
**Profitable** only where the site's spend counts the visits that came and not
what the network billed: priced by the cost in each click, its ROI looks
better than the network's bill, and the hint is where that shows. Where its
spend was shared out from the network's own figure — through the API, a file
or a typed total — it already pays for the impressions that never arrived, so
a profitable site stays **Profitable**.

A site the network billed on closed days that sent no visit at all — 0 %
reached, the worst reach there is — is a row of Sites & zones too once those
days carry at least as many paid impressions as Low reach asks (500): no
visits, **Paid impr.** and **Reached** at 0 %, and always **Low reach**, never
**Profitable** on the nothing it spent through a visit, counted in Worth
blocking and in a campaign's "to block" when the network billed it through
that campaign alone. It is ticked, copied and blocked like any other site. A
zone like that is a row only in the list by zone, under its site; a site whose
clicks came with zone IDs has visits, as its zones, so it is no such row. One
with fewer impressions stays out of the list, its impressions in On the way
here's totals all the same — and so does every such site of a source none of
whose impressions reached RateGuru on those days, as when its tracking link
lacks the site macro: with nothing reached to compare with, Low reach says
nothing there. **Hide under** never hides a Low
reach row, a row without a single click included: its paid impressions judge
it, not its clicks. Sorted by **Reached**, those that reached nothing come
first upwards and last downwards, before the rows the network reported no
impressions for; by spend they come last, having spent nothing through a
visit.

**Spend → Day by day** shows each day's **Impressions** and how many
**Reached** RateGuru, with their share, for the source chosen.

## Sign-ups that never confirm

Report → **How accounts come** follows paid traffic from the click to a
confirmed account. An email sign-up has 7 days to confirm; after that it is
removed with everything it did — **Never confirmed** — and inside them it is
**Still waiting**. Google and Facebook confirm at once.

**Sign-ups and confirmation**, under it, shows every sign-up on the filters,
unpaid traffic too, split by source (unpaid traffic by its channel), campaign,
site, zone, country, device, sign-up method or where they signed up: signed up,
confirmed, never confirmed, still waiting, and how soon after signing up the
confirmed ones confirmed. Its **Of it paid traffic** row is How accounts
come's figures. Many email sign-ups with few confirmations from one zone or
country usually means bots: **Find in Sites & zones** opens a network's zone
in the list where it is blocked.

## Logs

Logs → Clicks, Milestones and Postbacks (in a postback's path too) name each
row's visitor by its journey ID, `visitor_` and the first 8 characters of its
visitor ID, a link that opens every step of that visitor in Growth → Analytics
→ Journeys for an administrator who may open Analytics (the lookup is logged,
as every lookup is). The link names the visitor by the first 16 characters, so
it opens that visitor's journey even when another browser's ID starts the same.

A click's **Spend** in Logs → Clicks shows an amount under a cent to three
significant digits ($0.000759, not $0.0008), and hovering it says, with the
exact amounts, where the figure comes from: the price in the click, and what
was counted when that is not it — the rate you set, the campaign's price, or a
fixed price, a typed-in or CSV figure or the network's bill shared out over the
day — whether it is still an estimate, or that it is not counted (yet: a click
on a fixed price is not counted until that price is first shared out).

## Privacy page

The visitor cookie (`rg_game_visitor`, one year, first-party) now links an ad
click to the account made from it, and is set on sign-up and sign-in while
visits are recorded. A sentence for the privacy page: "When you arrive from an
advertisement we remember, with a cookie, which ad brought you, so we can tell
the ad network whether its visitors found the site useful. We don't keep your
IP address."

**Record site visits** sets the same cookie on every page of the site it
records, keeps which site or link each visit came from, and keeps the posts a
browser opened and shared for 90 days. The site asks no consent for it, so
whether to switch it on is the owner's decision; for visitors from the EU a
year-long cookie linked to an account is a risk to weigh. Before switching it
on, add to the privacy page (Project settings → Static pages), for example:
"We count visits to this site ourselves, with a cookie of our own that lasts a
year: which site or link brought you, the pages and posts you open, and
whether you sign up and come back. We keep which posts a browser opened for
90 days and the rest of a visit for 400 days. Nothing is shared with an
analytics company, and we don't keep your IP address."
