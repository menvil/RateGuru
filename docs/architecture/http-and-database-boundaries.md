# HTTP and database boundaries

This document records the validation and persistence rules enforced by custom
PHPStan rules under `tools/phpstan`. The source-based Pest guard remains as a
temporary migration check and will be removed after the PHPStan rules have
covered all boundaries.

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

## Eloquent first

Application reads and writes should use Eloquent models, relationships, scopes,
and dedicated classes under `app/Queries`. `Model::query()` is Eloquent and is
the preferred explicit query entry point.

`DB::transaction()` remains allowed for atomic application work. Direct Query
Builder access and direct SQL execution are rejected by the architecture guard.

The only current Query Builder exception is the dynamic source-table read in
`LegacyRatingVoteMigrator`. Those legacy table names are selected by trusted
application code and do not have useful long-lived Eloquent models.

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

Adding another raw boundary requires updating the architecture test allowlist,
documenting the reason here, and adding a behavior test.

The PHPStan allowlist is exact and class-based. Directory-wide exclusions are
not accepted. A new exception must therefore update
`tools/phpstan/architecture.neon`, this reviewed-boundaries list, and a semantic
test for the query result.

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
- direct database facade access outside the exact infrastructure allowlist;
- `DB::transaction()` inside HTTP controllers;
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
