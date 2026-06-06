# Phase 44 Configurable Voting Review

## Purpose

This checklist verifies that Phase 44 replaced hardcoded public voting behavior with configurable rating groups and options while preserving an explicit legacy migration path.

## Schema and models

- [ ] `rating_groups` exists with a unique key and valid min/max range constraint.
- [ ] `rating_options` exists with a group-scoped unique key and `archived_at`.
- [ ] `rating_votes` exists with foreign keys and the user/post/group unique constraint.
- [ ] `RatingGroup`, `RatingOption`, and `RatingVote` models and factories exist.
- [ ] Votes reference `rating_option_id`, not labels.
- [ ] Option and vote relationships are covered by tests.

## Configuration

- [ ] Default Source and Category configuration is seeded idempotently.
- [ ] New default keys are generic and do not add domain-specific storage.
- [ ] `RatingConfigurationManager` returns only active groups and active options.
- [ ] Groups and options are returned in configured sort order.
- [ ] Minimum 2 and maximum 10 active options are enforced.
- [ ] Invalid group ranges are rejected by application validation and the database.

## Voting

- [ ] Generic `RatingVoting` renders configured labels and options.
- [ ] Guest, authenticated, selected, inactive group, and inactive option states are tested.
- [ ] `VoteRatingOptionAction` creates and replaces a vote safely.
- [ ] One vote per user, post, and group is enforced by the database.
- [ ] Options cannot receive new votes when their group or option is inactive.
- [ ] `RatingVoteDistribution` uses aggregate counts and handles zero votes.

## Compatibility wrappers

- [ ] `SourceVoting` delegates to generic voting with `groupKey=source`.
- [ ] `CategoryVoting` delegates to generic voting with `groupKey=category`.
- [ ] New Source votes are written to `rating_votes`, not `origin_votes`.
- [ ] New Category votes are written to `rating_votes`, not `cuisine_votes`.
- [ ] Existing semantic events and public layouts remain compatible.

## Legacy migration

- [ ] `rateguru:rating:migrate-legacy-votes` exists.
- [ ] `--dry-run` performs no persistent writes.
- [ ] Source legacy values map to distinct generic options.
- [ ] Every valid category legacy value maps to a distinct generic option.
- [ ] The command is idempotent.
- [ ] Existing generic votes win conflicts.
- [ ] Unmapped legacy values are reported.
- [ ] Legacy tables remain unchanged and are not deleted.
- [ ] Production backup and run instructions are documented.

## Admin

- [ ] The Rating Groups Filament resource exists.
- [ ] Only admins can manage global rating configuration.
- [ ] Group key, label, description, range, state, and sort order are editable.
- [ ] Rating options can be created and edited inside a group.
- [ ] Option keys are unique within their group.
- [ ] Active option min/max limits are enforced in admin workflows.
- [ ] Option vote counts are visible.
- [ ] Voted options cannot be physically deleted.
- [ ] Voted options can be archived when the minimum remains satisfied.
- [ ] Unvoted options can be deleted when the minimum remains satisfied.
- [ ] Archive preserves the option ID and vote history.

## Scope review

- [ ] No multilingual labels were added.
- [ ] No project presets or settings system was added.
- [ ] No theme switcher or mobile redesign was added.
- [ ] No favorites, follows, profile 2.0, or external URL import was added.
- [ ] No monitoring vendor, public API, multi-tenancy, React, Vue, or Inertia was added.
- [ ] Legacy tables were not removed.

## Final checks

- [ ] `composer test` passes.
- [ ] PHP formatting checks pass.
- [ ] `npm run build` passes.
- [ ] `php artisan migrate:fresh --seed` passes.
- [ ] Configurable voting documentation matches the deployed behavior.
- [ ] Legacy migration documentation has been reviewed before production use.

