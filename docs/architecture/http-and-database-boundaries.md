# HTTP and database boundaries

This document records the validation and persistence rules enforced by custom
PHPStan rules under `tools/phpstan`. PHPStan is the authoritative general
architecture check; focused Pest tests remain only for endpoint contracts and
behavior that static analysis cannot prove.

## HTTP validation

Every HTTP controller action that accepts user input must type-hint a dedicated
`FormRequest`. Controllers consume only `validated()` or `safe()` data.

Inline calls to `validate()`, `validateWithBag()`, `Validator::make()`, or
`validator()` are not allowed in controllers. Livewire and Filament keep their
framework-native validation lifecycle and are outside this rule.

Authorization remains in policies. A `FormRequest::authorize()` method may
delegate to a policy, but must not introduce a second authorization rule.

Model and resource abilities are defined in their model policy. Global
abilities that do not belong to one model are defined as named gates backed by
a focused policy class. Controllers, Form Requests, Livewire components, and
Filament pages may call Gate or policy abilities, but must not inspect RateGuru
roles or capabilities directly. Actions may repeat the same ability check as a
defence-in-depth boundary; they must delegate to the policy instead of
reimplementing the rule.

## Presentation writes

Controllers, Form Requests, Livewire components, and Filament classes may
validate input, invoke an Action, and shape their response or local UI state.
They must not persist Eloquent models directly. Model, Eloquent Builder, and
relationship mutations such as `save()`, `update()`, `delete()`, `create()`,
and `updateOrCreate()` belong in `app/Actions`.

This restriction is type-aware. Framework state APIs such as Filament
`$form->fill()` are not Eloquent calls and remain allowed.

HTTP controllers also do not own Eloquent reads. Route-model instances may be
passed to a Query Object, Action, or response, but query construction,
relationship loading, aggregates, and pagination belong in `app/Queries`.

Query Objects and policies are read-only. Eloquent mutations, row locks, and
transactions in either namespace are rejected. Atomic mutations and their
locks belong in Actions; policies may only decide authorization.

## Eloquent first

Application reads and writes should use Eloquent models, relationships, scopes,
and dedicated classes under `app/Queries`. `Model::query()` is Eloquent and is
the preferred explicit query entry point.

`DB::transaction()` remains allowed for atomic application work. Direct Query
Builder access and direct SQL execution are rejected by the architecture guard.

There are currently no reviewed low-level Query Builder exceptions.

## Raw expressions

Raw expressions are allowed only when Eloquent has no equivalent that preserves
the same efficient database operation. User-controlled values must always be
passed as bindings.

The reviewed boundaries are:

- `AnalyticsContentQuery` for Analytics → Site engagement, which counts the
  posts a period's visits opened: conditional `COUNT(DISTINCT CASE WHEN … THEN
  visitor_hash END)` and sums over the rows each visit keeps of its posts — in
  all, per source, and per post, only the posts opened at all (`HAVING`), the
  most opened first — and the different browsers of the accounts whose session
  first opened each post, through a `NOT EXISTS` on an earlier post of the
  session.
- `AnalyticsVisitsQuery` for Growth → Analytics, which counts people rather
  than visits: conditional `COUNT(DISTINCT CASE WHEN … THEN visitor_hash END)`
  and sums over a period's visits — in all, per dimension of the visit with
  the people past the top groups, or too few to list, counted together and
  those of no value counted apart or left out when asked, per value asked for,
  per group of a dimension's columns among values of its first — the five
  campaign tags of the sessions of some campaigns, which tell tracking links
  apart — per pair of two dimensions' values, per day, or per hour or block of days
  between bound boundaries — ordered by the people of each group; the values of a dimension ordered by their people, for the page's
  filters; the people who came back, through an `EXISTS` on an earlier visit of
  the browser; the sums of what the paid clicks cost, their pages run or not,
  per dimension or nested dimensions; the first day each value came on, as
  `MIN(network_day)`; and the result shares of the attempts played on the visits.
- `CommentListQuery` for arithmetic comment ranking.
- `ComparisonPeriodsQuery` for literal LIKE searches over the titles, internal
  names and slugs of the games whose traffic periods Growth → Experiments lists.
- `FeedQuery` for arithmetic vote ordering and literal LIKE searches.
- `GameMetricsQuery` for a game's figures: conditional and distinct counts of
  its attempts, answers, emails and events, per version, flow or content set,
  per device, country or country group of the recorded visits behind them, or
  per traffic period and version from the visitors each period assigned — the
  result screens among them read with an `EXISTS` on the attempt's events —
  and the people who started more than one attempt.
- `GamePostPickerQuery` for literal LIKE searches over the titles and author usernames of the posts a game round may show.
- `GamesQuery` for literal LIKE searches over game titles, internal names and slugs.
- `MatchedUsersQuery` for literal LIKE searches.
- `MediaAuditIssuesQuery` for a literal LIKE search over issue paths, and the
  most severe issues first, ranked with a `CASE` over their severity.
- `ModerationCommentsQuery` for literal LIKE searches over comment text, author
  usernames and post titles.
- `ModerationPostsQuery` for literal LIKE searches over post titles and author
  usernames.
- `ModerationReportsQuery` for literal LIKE searches over reported content,
  reporters, reasons and messages, and for how many open reports point to each
  listed report's target: a correlated `COUNT` subquery.
- `ModerationUsersQuery` for literal LIKE searches over usernames, names and
  email addresses, and for the reports on each listed person's posts and
  comments: a correlated `COUNT` subquery.
- `PersonasQuery` for literal LIKE searches over Persona usernames, names, short
  descriptions and characters.
- `RatingVoteCountsQuery` for grouped rating counts.
- `RetentionQuery` for Analytics → Retention, which follows new accounts in
  weekly cohorts: per source and game, conditional sums of the confirmed
  accounts that signed up between each week's bound boundaries, of those of
  them that came back on day 1, day 7, day 30 and any of days 1–7 — whether
  one did read from its days of activity by a correlated subquery — of those
  inside the period, and of the period's, over one read of the accounts,
  never a row per account and never arithmetic on dates.
- `StudioPlansQuery` for literal LIKE searches over publication plan titles and
  over their authors' usernames, display names and names.
- `StudioActionsQuery` for literal LIKE searches over the Action queue: the
  publication's title and its Persona's username, display name and name.
- `TrackingLinksQuery` for literal LIKE searches over tracking links' names,
  sources, campaigns, contents and terms.
- `TrafficChanceSampleQuery` for the chances of an account: conditional counts
  of the matured visits that reached each step and of those that became
  accounts, per game, source, channel, device, country and rounds answered.
- `TrafficReportQuery` for the traffic reports: conditional sums and counts of
  a period's visits per level of the tree, site or zone, campaign, stage and a
  game's versions, ordered by the accounts they brought; and their sign-ups —
  confirmed, never confirmed or still waiting against a bound cutoff, and how
  soon confirmed — per dimension, only groups with a sign-up, the most first.
- `TrafficSegmentsQuery` for a paid source's segments on Analytics →
  Campaigns: which segment each of the source's clicks is in, each segment's
  conditions bound once in a `CASE`, then the people of the whole source, of
  each segment and of none as conditional `COUNT(DISTINCT CASE WHEN … THEN
  visitor_hash END)` and sums over those flags in one read; and the people a
  draft's conditions catch, through a bound `WHERE`. Every column comes from
  `SegmentParameter` or `AnalyticsDimension`.
- `TrafficSpendQuery` for reconciling spend: the sums of the visits' prices and
  counted spend and of the day's figures by origin, and the spend no visit took.
- `TranslationAiUsageQuery` for Translation AI usage: sums conditional on a
  call's status, and the UTC hour or day a call falls in, written per engine;
  and, per provider and model, the calls inside a period counted beside the
  latest `completed_at` of all its calls.

Literal LIKE searches use `LikePattern` and an explicit SQL `ESCAPE` clause so
user-supplied `%` and `_` characters are not interpreted as wildcards.

Adding another raw boundary requires updating the executable registry,
documenting the reason here, and adding a behavior test.

The PHPStan allowlist is exact and class-based. Directory-wide exclusions are
not accepted. A new exception must therefore update
`tools/phpstan/architecture.neon`, this reviewed-boundaries list, and a semantic
test for the query result. Those semantics are certified against the
[SQLite runtime contract](database-support.md); cross-engine migration smoke
jobs do not expand that guarantee.

## Static enforcement

PHPStan rejects the following patterns before merge:

- inline `Request::validate()`, `Validator` facade, or `validator()` calls in
  HTTP controllers;
- access to unvalidated request input in HTTP controllers, regardless of the
  request variable name;
- direct RateGuru role/capability checks such as `isAdmin()`, `isModerator()`,
  or `canCreateContent()` in controllers, Form Requests, Livewire components,
  and Filament classes;
- direct Eloquent persistence from those presentation classes;
- controller-owned Eloquent reads and pagination;
- writes, locks, or transactions inside Query Objects and policies;
- direct database facade access outside the exact infrastructure allowlist;
- `DB::transaction()` anywhere in the presentation layer;
- raw Eloquent or Query Builder methods outside the exact reviewed allowlist.

The rules use resolved PHP types, class names, and namespaces. Calls such as
`Auth::guard()->validate()`, `$client->get()`, `Model::query()`, relationship
queries, Filament `$form->fill()`, framework-native Livewire validation, and
`DB::transaction()` inside Actions or Services are intentionally allowed.
Every rule has fixture tests covering both violations and false-positive cases.

## Stable pagination

Every paginated query must finish with a unique deterministic ordering column,
normally the model primary key. Sorting only by timestamps or aggregate scores
is insufficient because multiple rows may share the same value.

Eloquent pagination may only be created by a Query Object registered in
`architecture.paginationBoundaries`. Every entry identifies the owning class
and method, its unique final order, and behavior tests that request consecutive
pages with tied sort values and assert that rows neither move nor overlap.

## Review enforcement

The `Architecture & static analysis (PHPStan)` CI check is the executable
contract. Its custom rules are covered by violation and false-positive fixtures.
The pull request template requires an explicit architecture review, and
`.coderabbit.yaml` gives CodeRabbit the same path-specific boundaries as a
supplemental reviewer. Neither the checklist nor AI review replaces the CI
check.

RateGuru has no PHPStan baseline: the previous 226 suppressed findings were
resolved and `phpstan-baseline.neon` was removed. CI publishes a suppression
inventory in the workflow summary, PR comment, and downloadable JSON artifact;
all counters must stay at zero. Reintroducing any suppression fails the baseline
guard, and `rateguru.*` architecture identifiers are never suppressible.
