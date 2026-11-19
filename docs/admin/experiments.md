# Growth → Experiments — operator guide

Growth → Experiments (`/admin/experiments`), for active administrators who may
manage games. It shows how a game's versions did against each other, period by
period.

## What an experiment is here

Nothing is set up on this page. Every time you apply shares on a game's
**Traffic** tab, a new traffic period starts; when a period gives its visitors
to two versions or more, it compares them. That period is the experiment. To
start one, give a second version a share in Traffic; to stop it, give one
version everything again. A split for a source (Traffic → **Split for a
source**) is a period of its own too, so it is compared on its own.

The navigation shows how many comparisons are running now.

## The list

Every traffic period of the games not archived, the latest started first,
across every game, 50 to a page:

- **Game**, **Group** (anonymous visitors or logged-in players, and for a split
  its source and campaign — "Everyone else" for the general split while splits
  run beside it), **Period** and how many days it ran, **Versions** with their
  shares, **Visitors** assigned.
- **Data**: whether the versions can be compared yet — **One version** (nothing
  to compare), **No visitors yet**, **Too early** until every version has 10
  confirmed accounts (for logged-in players, who make no accounts, 10 players
  who reached the goal), then **Enough data**. A game's Analytics tab badges its
  periods by the same rule.
- **Status**: **Current** while the period is open, **Ended** after.

Search finds a game by its title, internal name or address. **Show** narrows
to comparisons (two versions or more), **Status** to current or ended periods.
Everything is kept in the page's address, so a link opens the same list.

A period that shared no version at all — an audience left with no traffic —
isn't listed.

## A period's results

Click a row or **Results**. Each period row on a game's Traffic tab and in the
Traffic periods card of its Analytics has a **Results** link to the same page.

- The header names the game, the audience and, for a split, its source and
  campaign; whether the period is current or ended; the data badge; and the
  days, shares and visitors.
- **Periods for …** switches to the other periods of the same game, audience
  and split. Each of the general split's says "Everyone else" when a split ran
  beside it, so it reads apart from one that had every visitor.
- A strip says what the figures show so far: enough data in every version
  (read the metrics together — a version can capture more emails and still
  activate fewer users), too early and what each version still lacks, only one
  version ran, or nobody was sent yet.
- The table has a row per version — its share, visitors assigned, and its
  **Game starts**, **Completions**, **Email captured**, **Registrations**,
  **Activated · 7 days** and **Goal reached**, each a share of the visitors
  assigned to the version, with the count and the difference from the
  **Reference** in percentage points. The reference is the version with the
  largest share. Once the period has enough data, a difference of 0.3 points or
  more is coloured.
- A dash says why a figure can't be read: **Not in this flow** (the version's
  flow never asks for an email), **Already signed up** (registrations and
  activation of logged-in players), **Needs 7 days** (no account has had its 7
  days to come back yet) or **No visitors yet**.
- **Versions** and **Analytics** open the game's tabs; Analytics opens on the
  period's days. **Open Traffic**, in the top bar, opens the game's Traffic tab
  to change the shares.

Rates count everyone a version was given, whatever they did after, so a
version that loses visitors early is not flattered by those who stayed. The
data badge follows confirmed accounts for anonymous visitors and players who
reached the goal for logged-in ones.

For the evidence behind each difference — strong, ahead or behind but not sure
yet, no clear difference — and a check that the split matches the shares, open
the same period on Growth → Analytics → **Experiments** (docs/admin/analytics.md).
Both read the same figures.

## Deleting a period

**Delete period**, in the top bar of an ended period, hides it from this list
and from the switcher, and comes back to the list with **Undo**. Nothing is
lost: its numbers stay, the game's own tabs still show it, and its address
still opens it with "Hidden from Experiments" and **Show in Experiments**. A
current period can't be deleted — it is where visitors go now. Who hid or
showed a period is kept in the application log.
