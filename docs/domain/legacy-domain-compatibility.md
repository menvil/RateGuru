# Legacy Domain Compatibility

Phase: 43 - Domain Refactor: Generic Rating Platform
Task: RG-664 - Add Legacy Domain Compatibility Note

RateGuru is moving from the original food-specific voting vocabulary toward a generic rating platform. Phase 43 changes public wording and prepares compatibility wrappers, but it intentionally keeps legacy storage stable until Phase 44.

## Temporary Legacy Storage

The current voting schema remains in place during Phase 43:

- `origin_votes`
- `cuisine_votes`
- `posts.origin_truth`
- `posts.cuisine_truth`
- `posts.homemade_votes_count`
- `posts.restaurant_votes_count`

These names are legacy storage details. They should not appear as public UI labels, but they may remain in models, actions, migrations, factories, and direct schema tests until Phase 44 replaces the voting schema.

## Temporary Legacy Classes

These classes may remain temporarily because they map directly to the current schema:

- `OriginVote`
- `CuisineVote`
- `OriginType`
- `CuisineType`
- `VoteOriginAction`
- `VoteCuisineAction`
- `OriginVoting`
- `CuisineVoting`

New public-facing code should prefer Source and Category vocabulary where safe. If a rename would touch database fields, persisted enum values, event compatibility, or many cross-module tests, keep the legacy class and add a Source/Category wrapper instead.

## Public UI Boundary

Public UI should not show food-specific language after Phase 43 cleanup. The user-facing bridge terms are:

- Origin becomes Source.
- Cuisine becomes Category.
- Homemade becomes Option A or Source A.
- Restaurant becomes Option B or Source B.
- Dish becomes Post or Item.
- Food photo becomes Post image or Image.

Internally, those labels can still read from legacy tables and enum values until Phase 44.

## Why DB Rename Is Deferred

Do not rename `origin_votes` to `source_votes` or `cuisine_votes` to `category_votes` in Phase 43.

That would create a double migration path:

```txt
origin_votes -> source_votes -> rating_votes
cuisine_votes -> category_votes -> rating_votes
```

Phase 44 should migrate directly from the legacy storage to the generic target:

```txt
origin_votes + cuisine_votes -> rating_groups + rating_options + rating_votes
```

This avoids unnecessary table churn, foreign key churn, factory churn, and test churn.

## Phase 44 Target

Phase 44 is responsible for replacing legacy voting storage with generic rating primitives:

- `rating_groups`
- `rating_options`
- `rating_votes`

Phase 43 maps the legacy cuisine enum values to neutral public labels:
`italian/asian/american/mexican/other/unknown` become `A/B/C/D/OT/UN`
in compact UI and `Category A/B/C/D/Other/Unknown` in accessible labels.
`CuisineType` owns this temporary mapping so services and voting components
cannot drift. Phase 44 replaces it with configured rating option labels.

After that migration, legacy Origin/Cuisine models, actions, enums, factories, and relationship tests can be removed or rewritten around Rating Group, Rating Option, and Rating Vote.

## Compatibility Rules

- Keep historical migrations unchanged.
- Keep legacy tables and columns until the Phase 44 rating schema migration.
- Do not introduce `source_votes` or `category_votes`.
- Do not add preset-specific branches such as `if ($preset === 'food')`.
- Use Source and Category as temporary public labels only.
- Document any remaining legacy term in compatibility context.
- Keep tests for legacy schema while the legacy schema exists, but stop using food-domain wording in behavior descriptions where possible.
