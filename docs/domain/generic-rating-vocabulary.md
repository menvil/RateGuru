# Generic Rating Vocabulary

Phase: 43 - Domain Refactor: Generic Rating Platform
Task: RG-663 - Define Generic Rating Vocabulary

This document defines the neutral vocabulary for new RateGuru code, public UI, tests, and docs. The goal is to stop reinforcing the original food-specific domain while keeping legacy storage stable until Phase 44.

## Core Terms

| Term | Meaning | Usage |
| --- | --- | --- |
| Post | The primary object being published and rated. | Use for model-facing and UI-facing references to submitted content. |
| Item | A human-readable generic name for the thing represented by a post. | Use only when the UI needs a casual noun instead of Post. |
| Object | A generic technical name for the rated entity. | Use in architecture notes when Post is too application-specific. |
| Rating Group | A configurable group of voting options. | Phase 44 target concept. Examples: Source, Category, Quality, Style. |
| Rating Option | One selectable option inside a Rating Group. | Phase 44 target concept. Examples: Option A, Option B, Category A. |
| Rating Vote | A user's vote for a Rating Option on a Post. | Phase 44 target concept replacing legacy vote tables. |
| Source | Temporary neutral public name for the legacy Origin voting group. | Phase 43 bridge term. Backing storage may still use `origin_*`. |
| Category | Temporary neutral public name for the legacy Cuisine voting group. | Phase 43 bridge term. Backing storage may still use `cuisine_*`. |

## Public Copy Rules

Use these terms in public UI, admin-visible labels, tests that describe behavior, and new documentation:

| Legacy wording | New wording |
| --- | --- |
| Dish | Post or Item |
| Dishes | Posts or Items |
| Upload dish | Upload post |
| Food photo | Post image or Image |
| Origin | Source |
| Cuisine | Category |
| Homemade | Option A or Source A |
| Restaurant | Option B or Source B |
| Cuisine votes | Category votes |
| Origin votes | Source votes |

## Phase 44 Target Model

Phase 44 should replace the temporary Source/Category bridge with generic configurable voting primitives:

- Rating Group: the named set of options shown to users.
- Rating Option: a selectable option inside a group.
- Rating Vote: the persisted user selection for one option on one post.

New Phase 43 code should avoid creating more direct dependencies on legacy Origin or Cuisine concepts unless it is explicitly acting as a compatibility layer.

## Do Not Use In New Code

Do not introduce these terms in new active application code, public views, test descriptions, or new docs except in explicit legacy compatibility context:

- dish
- dishes
- cuisine
- food
- food photo
- homemade
- restaurant
- meal
- recipe

## Legacy Exceptions

These terms may remain temporarily where they are already tied to storage or historical context:

- old migrations;
- legacy database tables and columns until Phase 44;
- legacy models such as `OriginVote` and `CuisineVote`;
- legacy enums such as `OriginType` and `CuisineType`;
- compatibility docs that explain the transition;
- the original PlateRate prototype reference;
- tests that directly assert existing legacy schema until the schema changes.

Do not create intermediate table names such as `source_votes` or `category_votes`. Phase 44 should migrate directly from legacy voting storage to the generic rating schema.
