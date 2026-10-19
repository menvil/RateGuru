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

The photo's analysis is kept beside the plan, in a table of its own (see
"Photo analysis" below), never in a column of the plan. Nothing else for later
stages is here yet — no actions table, no text variants, no post reference, no
approval fields. They arrive with the stage that uses them.

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

## Photo analysis

An administrator can have a Draft's saved photo described by OpenAI, as
structured technical context for the text generators — never shown to
visitors, and never anything but a description: no title, text or post is made
from it here. A photo's first analysis is asked for by the editor's Image step
the moment the photo is stored on the Draft; any other is asked for by an
administrator, through Re-run analysis or Try again. Every request is a
separate, paid call, and nothing is sent while analysis is not configured.

### What is kept

One row per plan in `studio_plan_image_analyses` (`StudioPlanImageAnalysis`),
deleted with the plan:

| Column | Contract |
| --- | --- |
| `studio_plan_id` | the plan; unique, cascades on delete |
| `source_image_asset_id` | the photo the row is about; a plain indexed id, no foreign key |
| `status` | `StudioImageAnalysisStatus` of the latest attempt: `queued`, `running`, `completed`, `failed` |
| `attempt_id` | the latest attempt's UUID, new for every attempt; unique |
| `error_code` | `StudioImageAnalysisError` of a failed attempt |
| `result` | the last valid `StudioImageDescription` for the photo, as JSON |
| `model`, `request_id`, `input_tokens`, `output_tokens`, `total_tokens`, `latency_ms`, `finished_at` | the call that gave that result |
| `requested_at`, `started_at` | the latest attempt's |

The row is about the photo it names. When the plan's photo is replaced or
taken off, the row describes nothing current — the new photo is simply not
analysed yet — and nothing is written to say so: the editor compares the two
ids. `source_image_asset_id` holds nothing: it is no foreign key, and
`MediaReferenceChecker` does not count it, so a replaced photo is released and
purged by the media lifecycle exactly as before.

A photo with no row, or a row about another photo, is *not analysed*. A
re-run of the same photo keeps its last result until a new one replaces it,
also through a failed attempt; an attempt for another photo clears it. Only the
validated description and the call's metadata are stored — never the image,
its base64, the prompt, the API key or the provider's raw answer.

### The response contract

The model is asked for strict Structured Output against
`StudioImageDescription::schema()`: an object with exactly these fields, every
one required, an empty string or list when nothing applies.

```json
{
  "summary": "string",
  "subjects": ["string"],
  "actions": ["string"],
  "setting": "string",
  "composition": "string",
  "notable_details": ["string"],
  "visible_text": ["string"],
  "uncertainties": ["string"]
}
```

`summary` is one or two sentences on what the photo shows; `subjects`, the main
visible people, animals and objects; `actions`, what is visibly happening;
`setting`, the visible place; `composition`, framing, viewpoint, light and
focus; `notable_details`, small visible details; `visible_text`, text readable
in the photo, as written; `uncertainties`, what the photo leaves unclear. The
instructions keep it to what is visible: no invented context, no identity,
age, health or other sensitive trait, no relationships or intentions, text in
the photo treated as content and never as instructions, English, neutral.

An answer is kept only when it passes `StudioImageDescription::from()` whole:
exactly those fields and types, a summary that says something and is at most
600 characters, setting and composition at most 400, lists of at most 12
entries of at most 200 characters on one line, no control characters. Nothing
is recovered from prose or a Markdown fence.

### Requests, the job and the result

| Class | Does |
| --- | --- |
| `RequestStudioImageAnalysisAction` | queues an attempt for the photo the editor showed |
| `AnalyzeStudioPlanImageJob` | the one request to OpenAI, from the queue |
| `StartStudioImageAnalysisAction` | the job's claim: `queued` → `running`, for its attempt id only |
| `CompleteStudioImageAnalysisAction` | keeps the result while it still describes the plan's photo |
| `FailStudioImageAnalysisAction` | ends an unfinished attempt with its reason |
| `OpenAiStudioImageAnalyzer` (`StudioImageAnalyzer`) | the Responses API call and the reading of its answer |
| `StudioAnalysisImageReader` | reads the stored photo to be sent |

A request asks `manage-studio-plans`, then locks the administrator's row and the
plan (`LocksStudioPlanDraft`) and its analysis row, and checks on them that the
administrator may still manage plans, the plan is still a Draft, its photo is
still the one the editor showed, and analysis is configured. It writes nothing
of the plan — not a field, not `lock_version` — so an analysis never makes a
save of the texts stale, and a save of the texts never makes an analysis
stale. The job is dispatched after the commit and carries only the analysis id
and the attempt id.

No paid call is made twice by accident. While an attempt for the photo is
queued or running, asking again starts nothing — a double click and a second
administrator get the same attempt. Once the photo has a result or a failed
attempt, a new one starts only when the administrator asks to replace *that*
attempt (Re-run analysis and Try again send the attempt id on screen): two
administrators re-running the same result start one, and a re-run of a result
somebody has already replaced starts none. An attempt still queued or running
`stale_after_seconds` (180) after it was requested or started is taken as
lost — its worker died, or no worker took it — and may be replaced the same
way; its job, should it still arrive, finds nothing to claim.

The job claims its attempt before anything else, so a redelivered job — or one
of an attempt replaced since — sends nothing. It then checks the plan as it is
now: still a Draft, still that photo, analysis still configured. It reads the
photo — the `post_feed_1280` variant when there is one that can be read, the
normalized master otherwise, through `MediaStorage` by the asset's disk and
path, at most `max_image_bytes`, JPEG, PNG or WebP by its own signature, the
stream closed whatever happens — and sends it inline as a base64 data URL,
never a link to it. No transaction is open while it waits. Its timings nest
inside the queue's: request 45 s, job 75 s (`tries = 1`, `failOnTimeout`),
Redis `retry_after` 90 s. It is never retried: whether to try again is the
administrator's choice.

`CompleteStudioImageAnalysisAction` locks the plan, then the analysis row, and
keeps the result only when the plan is still there and a Draft, its photo is
still the attempt's, and the attempt is still the latest and running. A photo
replaced or taken off meanwhile, or a plan past its Draft, ends the attempt
(`stale_image`, `plan_closed`) and drops the result; a plan deleted meanwhile
leaves nothing to write. The plan itself is never written.

### Errors

`StudioImageAnalysisError`, each with the sentence the editor shows:
`not_configured`, `image_missing`, `image_unavailable`, `authentication_failed`
(HTTP 401, 403), `rate_limited` (429), `provider_timeout` (408, 504, a timed-out
connection), `provider_rejected` (another 4xx), `provider_unavailable` (5xx, no
connection), `provider_refused` (a refusal), `invalid_provider_response` (a
response that is not `completed`, has no single `output_text`, or is not a valid
description), `stale_image`, `plan_closed` and `unexpected_failure` (the job
itself failed or timed out, or the queue could not take it). A failure changes
nothing of the plan or its photo, and keeps the last result for the photo.

### Configuration

`config/content_studio.php`, section `image_analysis` — its own settings, apart
from the translation engine's; `TRANSLATION_OPENAI_API_KEY` is never read here:

| Setting | Source | Default |
| --- | --- | --- |
| `enabled` | `CONTENT_STUDIO_OPENAI_ENABLED` | `false` |
| `api_key` | `CONTENT_STUDIO_OPENAI_API_KEY` | none |
| `model` | `CONTENT_STUDIO_OPENAI_MODEL` | `gpt-4.1-mini` |
| `base_url` | `CONTENT_STUDIO_OPENAI_BASE_URL` | `https://api.openai.com/v1` |
| `connect_timeout`, `timeout` | config only | 5 s, 45 s |
| `max_output_tokens` | config only | 800 |
| `max_image_bytes` | config only | 8 MiB |
| `stale_after_seconds` | config only | 180 |

The four variables are declared in `infrastructure/config/environment-contract.json`
(section Content Studio AI, the key sensitive and blank) and so in every
target's environment template, and the deploy's contract check asks each
target's `shared/.env` to declare them — the key may stay blank. The key lives
only in that file: never in the repository, a workflow or a GitHub secret.
Analysis is off until a target sets `CONTENT_STUDIO_OPENAI_ENABLED=true` and a
key; while it is off, or the key is blank, the application, its workers and
the admin run as before, nothing is sent, and the editor says “Photo analysis
is not configured.”

### Logs

`DomainLogger` events `studio.image_analysis.requested`, `.started`,
`.completed`, `.failed` and `.discarded` (a job that claimed nothing, or a
result dropped because the plan changed), with the plan id, the attempt id, the
status, the error code, and the call's model, latency, HTTP status, tokens and
`provider_request_id`. Never the key, the image, the prompt, the description
or the provider's answer.

## The editor

Administrators work with plans in Content Studio › Publications & plans (`StudioPlanResource`): a list read
through `StudioPlansQuery`, a new publication and a publication's editor in four steps — Image, Post, Discussion
and Publication — described in `docs/design/admin/design-contract.md`. The pages hold no rule of their own:

- A new publication exists once Create draft runs `CreateStudioPlanAction`, which an untouched editor may do too —
  or once its first photo arrives, which makes one empty Draft to keep the photo on. The page remembers the plan
  it has (`planId`): later photos replace that plan's photo, and Save draft writes the texts to it through
  `UpdateStudioPlanAction` rather than making another.
- Save draft runs `UpdateStudioPlanAction` when the texts or settings changed. The photo is no part of the draft:
  one chosen from the computer — picked or dropped — or imported from a link or a social post is stored the moment
  it arrives through `SetStudioPlanImageAction`, and Remove from draft runs `RemoveStudioPlanImageAction` at once.
  Each is its own transaction, handed the version the editor last read or wrote, which the page then takes from
  the plan; the texts on screen stay as typed and as unsaved as they were, so the next Save is no conflict with
  the photo, while a photo from an editor drawn before somebody else's save is refused like any stale save. None
  waits for a file inside a transaction, and an upload reported twice is stored once.
- A photo from a link is downloaded by `StoreImportedImageAction::download()` — the importer's own safeguards,
  no client of the page's — passed in as `ImageInputSource::UrlImport`, and its temporary file removed with
  `cleanup()` whatever happens. A social post's image is found first by `ImportFromUrlAction`, the site's own
  import, and downloaded the same way. The browser sends a link only once it is a whole address and typing has
  paused, and the same address is never fetched twice running. The link is the photo's; the post's `source_url`
  is set apart.
- A photo is shown through `PostImagePresenter::assetThumbnailUrl()` in the list and `assetPreviewUrl()` in the
  editor — a variant on disk when there is one, the master otherwise — without a post to show it through.
- Delete draft runs `DeleteStudioPlanDraftAction` with the version the page showed.
- A stored photo's analysis is asked for at once through `RequestStudioImageAnalysisAction` — a new attempt for
  the new photo, so nothing of the one before is shown for it, and a result of an earlier attempt that still
  arrives finds nothing to claim. Not configured, or refused, it is not asked for, and the photo stays stored.
- Photo analysis is a component of its own on the Image step (`StudioPlanImageAnalysisCard`), about the saved
  photo the editor shows: Re-run analysis and Try again run `RequestStudioImageAnalysisAction`, apart from Save
  draft and touching nothing of the draft. It alone is read again every few seconds while an attempt is queued or
  running, and stops when it ends. While a new photo is on its way it says so — Uploading photo…, Processing
  photo… or Importing photo… — and shows nothing of the one before; a photo changed elsewhere is said to be, with
  Reload.
