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

- `CommentListQuery` for arithmetic comment ranking.
- `FeedQuery` for arithmetic vote ordering and literal LIKE searches.
- `MatchedUsersQuery` for literal LIKE searches.
- `RatingVoteCountsQuery` for grouped rating counts.

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
