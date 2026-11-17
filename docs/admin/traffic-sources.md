# Traffic sources — operator guide

Growth → Traffic sources (`/admin/traffic-sources`), for active administrators.

## Switching it on

1. Sources → Tracking: check the IP database says what it serves (Update now
   fetches one within a minute). GeoLite2 City is the default; DB-IP City Lite
   stands in by itself while GeoLite2 has no usable file.
2. Switch on **Record visits** and save. Unpaid visits appear under Logs →
   Clicks, with their country and device.
3. **Add source** → PopAds (or another network). Fill in what the preset asks
   (its note at the top of the editor) and save.
4. Campaigns → Get the link: choose the source and a game, paste the link as
   the target URL of the network's campaigns. Check a link reads a pasted link
   the way the server reads a click.
5. Run a small campaign; its clicks appear, and its campaign shows up by itself.
6. Connect the source's API (PopAds key, or ExoClick API token) in its editor.
   A day's spend closes once the network's figures came whole.
7. On each game's Value tab, set values by hand while the data is thin; then
   add a postback rule, **Send test**, and switch on **Send postbacks**. Send a
   network one event per click on each of its goals: two rules on one goal are
   counted as two conversions and their worth added up, and the editor says so.
   For a network that learns from conversions, choose the step its campaigns
   should buy (the flow's goal, or Halfway or Game finished while accounts are
   few). For one that bids on value, choose **Visit settled**: once a visit
   came to rest — its account confirmed, 30 minutes without a step, or a day
   after the click — it tells the best worth the visit reached. Give a Visit
   settled rule a floor (Game started by default) unless the network bids on
   value. Country groups start at Tier 1 100 %, Tier 2 60 %, every other
   country 30 % (Value → Country groups).
8. Block by hand first, then through the API on a test campaign, then let the
   block rule do it by itself (only once the connector is verified).

## Privacy page

The visitor cookie (`rg_game_visitor`, one year, first-party) now links an ad
click to the account made from it, and is set on sign-up and sign-in while
visits are recorded. A sentence for the privacy page: "When you arrive from an
advertisement we remember, with a cookie, which ad brought you, so we can tell
the ad network whether its visitors found the site useful. We don't keep your
IP address."
