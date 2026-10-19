# Content Studio publication plans

A publication plan (`StudioPlan`, table `studio_plans`) is one future post,
prepared in Content Studio and published as a Persona once it is approved. It
is not a post: until then nothing of it is on the site, no `posts` row exists
for it, and it is not a queued job or a blob of JSON either — it is an ordinary
row in the main database, so the usual backups and restores carry it. The
decisions behind it are recorded in `docs/architecture/content-studio-phase-0.md`
(D7, E2, E10).

## What a plan holds

| Column | Contract |
| --- | --- |
| `status` | `StudioPlanStatus`, `draft` by default |
| `author_user_id` | the Persona the post is to be published as; nullable, restricted |
| `created_by_user_id` | the administrator who made the plan; restricted |
| `image_asset_id` | the photo, a `post_image` MediaAsset; nullable, restricted |
| `title`, `description`, `source_url` | the post's texts, each nullable |
| `category_id` | nullable; set to null if the category itself is deleted |
| `tag_ids`, `author_answer_option_ids` | JSON lists of ids: whole numbers, once each, ascending |
| `publication_mode` | `StudioPlanPublicationMode`: `now` (default) or `at_date` |
| `publish_at` | the date for `at_date`, past or future; none for `now` |
| `lock_version` | starts at 1 and rises with every save |

Nothing for later stages is here yet — no actions table, no photo analysis or
text variants, no post reference, no approval fields. They arrive with the
stage that uses them.

## Statuses

`Draft` → `Approved` → `Running` → `Completed`, or `Cancelled`. A plan is made,
edited and deleted only as a Draft. The other states are defined but nothing
moves a plan into them yet: approval, running and cancelling come with
publishing.

## A Draft may be incomplete

A Draft is saved as incomplete as the editor leaves it — no author, photo,
title, category or date at all. Whatever it does hold is checked by
`StudioPlanValidationRules`, keyed as the editor's fields, against the bounds
the post it becomes keeps (`PostContentRules`, which the public upload form
uses too): a title of 3 to 120 characters, a description of up to 2,000, a
full web address of up to 2,048, up to 10 tags, at most one author answer per
rating group, a date a post's timestamps can hold on every database. Whatever
it names exists: the author is a Persona that is not deleted, and the
category, tags and rating options are there. Whether they are still active,
and the plan complete, is asked when it is approved.

The author is a Persona and nothing else — never a member, a moderator, an
administrator or a deleted account. A Limited, Banned or Shadowbanned Persona
may still be a Draft's author: saving a Draft publishes nothing, and whether
the author can publish is asked on approval. A Draft keeps its author through
a sanction or a deletion of the account; such a Draft is saved again only once
its author is changed or cleared.

## Writes

Only an active administrator writes plans (`manage-studio-plans`,
`StudioPlanPolicy`) — a gate of its own, apart from `manage-personas`. Every
write goes through one of the actions in `app/Actions/Studio` and nothing else:

| Action | Does |
| --- | --- |
| `CreateStudioPlanAction` | a new Draft at version 1, its creator the administrator |
| `UpdateStudioPlanAction` | replaces what `StudioPlanData` holds; never the photo |
| `SetStudioPlanImageAction` | uploads or replaces the photo |
| `RemoveStudioPlanImageAction` | takes the photo off |
| `DeleteStudioPlanDraftAction` | deletes the Draft's row outright |

`StudioPlanData` holds what an editor may set and nothing more: the author,
texts, category, tags, author answers, mode and date. The status, the creator,
the photo and the version are the server's to write; the model leaves them out
of mass assignment too.

Each action asks once on the caller's copy, then, in its transaction, locks
the `users` rows in ascending id order — the administrator's, and the chosen
author's — then the plan (`LocksStudioPlanDraft`), and asks everything again
on the locked rows: the administrator may still manage plans, the author is
still a Persona that is not deleted, and the plan is still there, still a
Draft, and still at the version the editor was drawn from. A save from an
older version is refused (`CannotManageStudioPlanException::isStale()`) rather
than written over a newer one; a plan deleted meanwhile is `isGone()`, which is
also what a second delete of the same plan says, releasing nothing again.

## The photo

The photo is an ordinary post image owned by the administrator who uploaded
it, never by the author, so changing the author leaves it as it is. While a
plan points at it, the media audit counts it as used and no Release or purge
removes it; replacing it, taking it off or deleting the Draft releases it to
the ordinary media lifecycle. See "A publication plan's photo" in
`docs/architecture/media.md`.
