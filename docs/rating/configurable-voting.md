# Configurable Voting

Phase 44 replaces hardcoded public voting choices with database-backed rating groups, options, and votes.

## Data model

### `rating_groups`

Each row defines one independently configurable voting question.

Important fields:

- `key`: stable integration key such as `source` or `category`.
- `label` and `description`: admin-managed display copy.
- `min_options` and `max_options`: allowed active option range.
- `is_active`: controls public availability.
- `sort_order`: controls group ordering.

The database enforces a unique key and `min_options <= max_options`.

### `rating_options`

Each row belongs to a rating group.

Important fields:

- `key`: stable key unique within its group.
- `label` and `description`: admin-managed display copy.
- `is_active`: controls whether the option can receive new votes.
- `sort_order`: controls option ordering.
- `archived_at`: records explicit archival without losing identity.

Votes point to an option ID, never to its label. Labels and ordering can therefore change without rewriting vote history.

### `rating_votes`

Each row stores a user's selected option for a post and group.

The unique constraint on `user_id`, `post_id`, and `rating_group_id` enforces one vote per user, post, and group. Selecting another option in the same group replaces the existing row through `VoteRatingOptionAction`.

The action also verifies that:

- the user may vote;
- the post is public and voteable;
- the user is not voting on their own post;
- the group and option are active;
- the option belongs to the selected group.

## Runtime configuration

`RatingConfigurationManager` returns active groups and active options in configured sort order. It validates that each active group has between its configured minimum and maximum number of active options.

`RatingVoteDistribution` uses aggregate queries to return each option's count and percentage for a post and group. Zero-vote groups return zero counts and percentages.

## Public components

`RatingVoting` is the generic Livewire component. It receives a post and `groupKey`, renders configured options, records votes through `VoteRatingOptionAction`, and displays the selected option and distribution.

`SourceVoting` and `CategoryVoting` are compatibility wrappers. They hardcode the `source` and `category` group keys but use `rating_votes` for all new votes. They no longer create rows in the legacy vote tables.

## Default configuration

`DefaultRatingConfigurationSeeder` creates idempotent defaults:

- Source: `source_a`, `source_b`.
- Category: `category_a`, `category_b`, `category_c`.

The explicit legacy migration may add `category_d` and `category_other` so every valid historical category choice maps to a distinct generic option.

## Admin management

The Filament Rating Groups resource is admin-only. Moderators and regular users cannot change global voting configuration.

Admins can:

- create and edit groups;
- change group labels, descriptions, ranges, state, and sort order;
- create and edit options;
- change option labels, descriptions, state, and sort order;
- see option and vote counts;
- archive options while retaining vote history;
- delete only unvoted options when the minimum active count remains satisfied.

The admin UI enforces 2-10 options through group validation and prospective active-option checks.

## Deletion and archive rules

`DeleteRatingOptionAction` blocks physical deletion when votes exist. This prevents the option foreign key from cascading away historical votes.

`ArchiveRatingOptionAction` sets `is_active` to false and records `archived_at`. Archiving and deleting an active option are both blocked when the change would reduce the group below `min_options`.

Never reuse an archived option ID for a different meaning. Create a new option instead.

