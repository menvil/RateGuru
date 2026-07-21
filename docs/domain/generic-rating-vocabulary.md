# Generic Rating Vocabulary

RateGuru uses neutral, configurable concepts so the same application can rate any kind of content.

## Core terms

| Term | Meaning |
| --- | --- |
| Post | The primary piece of content published by an author. |
| Category | An optional single classification selected by the author. |
| Tag | An optional reusable label; a post may have multiple tags. |
| Rating Group | A configurable question shown for a post. |
| Rating Option | One selectable answer inside a Rating Group. |
| Rating Vote | A user's selected Rating Option for one Post and Rating Group. |
| Author Answer | The author's optional answer for a Rating Group at publication time. |

## Independence rules

- Category and tags are independent: choosing either is optional.
- Category and Author Answers are independent and are persisted separately.
- Rating Groups and Rating Options are configured in the database, not hardcoded in public components.
- Public post cards render every active Rating Group in configured order.

## Naming rules

Use `post`, `category`, `tag`, `rating group`, `rating option`, and `rating vote` in new identifiers and public copy. Content-specific words belong in admin-managed labels, categories, tags, or user content rather than in schema, model, action, or component names.
