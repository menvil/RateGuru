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
blacklist by hand.

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
Growth → Analytics have — offers Yesterday and the last 7, 30 or 90 days
beside a calendar: click the first day of a range, then the last, and the
period is taken at once, with no Apply. Days before the oldest day visits are
kept (400 days) can't be picked. Days are each network's own. The period is
kept in the page's address (`period`, or `from` and `to` for a range); a range
in an address that ends after today, runs backwards or starts before the
oldest kept day opens the last 30 days instead. A game's Analytics links here
with its own period and device.

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
