# Phase 43 Domain Refactor Review

## Review purpose

This checklist verifies that Phase 43 moved RateGuru toward a generic rating platform without prematurely replacing the legacy vote storage schema.

Phase 43 is complete only when the public application language no longer exposes the old food-specific domain, while legacy storage remains documented for replacement in Phase 44.

## Checklist

- [ ] Food domain hardcode audit is completed.
- [ ] Generic rating vocabulary document exists.
- [ ] Legacy domain compatibility document exists.
- [ ] Feed copy uses generic post wording.
- [ ] Post show and drawer copy use generic post wording.
- [ ] Upload copy uses generic post wording.
- [ ] Profile copy uses generic post wording.
- [ ] Notification copy uses generic post wording.
- [ ] Report and moderation copy uses generic post wording.
- [ ] Public Origin label is replaced by Source.
- [ ] Public Cuisine label is replaced by Category.
- [ ] SourceVoting wrapper exists.
- [ ] CategoryVoting wrapper exists.
- [ ] Public views use Source and Category voting components.
- [ ] Seed and factory content is neutralized.
- [ ] Forbidden words guardrail test exists.
- [ ] Browser and visual tests are updated for generic copy.
- [ ] No DB rename was done prematurely.
- [ ] Phase 44 migration target is documented.

## Forbidden Words

Active UI and application-layer code must not introduce new hardcoded food-domain words such as dish, dishes, food photo, cuisine, homemade, or restaurant.

The forbidden words guardrail must scan active UI and application-layer code while keeping legacy exceptions explicit.

Allowed exceptions must stay narrow and documented:

- historical migrations;
- legacy database table/model compatibility until Phase 44;
- compatibility documentation;
- minimal allowlisted legacy classes that still bridge existing storage.

## Legacy compatibility

The legacy `origin_votes` and `cuisine_votes` tables remain temporary storage during Phase 43. They must not be renamed to intermediate `source_votes` or `category_votes` tables.

Phase 44 is responsible for replacing this legacy schema with the generic `RatingGroup`, `RatingOption`, and `RatingVote` model.

## Final review questions

- Does every public screen read as a generic post rating product?
- Are Source and Category presented as temporary generic labels, not new permanent storage names?
- Are old food-domain names limited to legacy compatibility boundaries?
- Do tests describe behavior with generic rating vocabulary?
- Do `composer test`, PHP formatting checks, and `npm run build` pass?
