# HTTP and database boundaries

This document records the validation and persistence rules enforced by
`tests/Feature/Architecture/HttpAndDatabaseBoundariesTest.php`.

## HTTP validation

Every HTTP controller action that accepts user input must type-hint a dedicated
`FormRequest`. Controllers consume only `validated()` or `safe()` data.

Inline calls to `validate()`, `validateWithBag()`, `Validator::make()`, or
`validator()` are not allowed in controllers. Livewire and Filament keep their
framework-native validation lifecycle and are outside this rule.

Authorization remains in policies. A `FormRequest::authorize()` method may
delegate to a policy, but must not introduce a second authorization rule.

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

- `Comment` ranking scopes for arithmetic vote ordering.
- `FeedQuery` for arithmetic vote ordering and literal LIKE searches.
- `MatchedUsersQuery` for literal LIKE searches.
- `PostVoteResultService` for grouped vote counts.
- `RatingVotingStateLoader` for grouped rating counts.

Literal LIKE searches use `LikePattern` and an explicit SQL `ESCAPE` clause so
user-supplied `%` and `_` characters are not interpreted as wildcards.

Adding another raw boundary requires updating the architecture test allowlist,
documenting the reason here, and adding a behavior test.

## Stable pagination

Every paginated query must finish with a unique deterministic ordering column,
normally the model primary key. Sorting only by timestamps or aggregate scores
is insufficient because multiple rows may share the same value.
