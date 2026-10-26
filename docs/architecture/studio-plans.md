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
| `approved_by_user_id`, `approved_at` | who approved it and when, in UTC; nullable, written only by its approval; the account restricted |
| `image_asset_id` | the photo, a `post_image` MediaAsset; nullable, restricted |
| `title`, `description`, `source_url` | the post's texts, each nullable |
| `category_id` | nullable; set to null if the category itself is deleted |
| `tag_ids`, `author_answer_option_ids` | JSON lists of ids: whole numbers, once each, ascending |
| `publication_mode` | `StudioPlanPublicationMode`: `now` (default) or `at_date` |
| `publish_at` | the date for `at_date`, past or future; none for `now` |
| `lock_version` | starts at 1 and rises with every save |

The photo's analysis and the text suggestions written from it are kept beside
the plan, each in a table of its own (see "Photo analysis" and "Post text
suggestions" below), never in a column of the plan, and so are the discussion
it prepares (see "The discussion" below), the votes it prepares (see "The
votes" below) and the actions approval writes (see "Approval" below). The post a plan becomes is its
`create_post` action's result (`result_type`, `result_id`), not a column of the
plan.

## Statuses

`Draft` → `Approved` → `Running` → `Completed`, or `Cancelled`. A plan is made,
edited and deleted only as a Draft; approval moves it on to Approved, after
which no editor's write — a save, a photo, a deletion — touches it. Its first
action claimed, it is Running; once none of its actions is left to run or to
decide, it is Completed when its post was published and Cancelled when it never
will be (see "Running a plan" below). A held or failed action keeps it Running,
waiting for an administrator, who may also stop what is left of an Approved or
Running plan in the Action queue — settling it the same way (see "The Action
queue" below).

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
| `UpdateStudioPlanAction` | replaces what `StudioPlanData` holds; never the photo, and no date its discussion or votes would run past (see "The discussion" and "The votes") |
| `SetStudioPlanImageAction` | uploads or replaces the photo |
| `RemoveStudioPlanImageAction` | takes the photo off |
| `DeleteStudioPlanDraftAction` | deletes the Draft's row outright, its discussion and votes with it |
| `SaveStudioDiscussionTreeAction` | saves the Draft's whole discussion (see "The discussion") |
| `SaveStudioVotesAction` | saves every vote the Draft prepares (see "The votes") |
| `RequestStudioDiscussionGenerationAction` | asks for a discussion, or a part of it, to be written (see "Discussion generation") |
| `ApplyStudioDiscussionGenerationAction` | takes a generation's proposal into the Draft's discussion |
| `ApproveStudioPlanAction` | approves a complete Draft and writes the action that will publish it |
| `ExecuteStudioAction` | runs one sent action — today the post — once (see "Running a plan") |
| `DispatchDueStudioActionsAction` | sends the actions that are due, and again those lost |

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

## Approval

Approving a plan is an administrator's trusted permission for its Persona to
publish it (`docs/architecture/content-studio-phase-0.md`, D3): the plan is
complete and current, and nothing about it changes any more. It is given on
the plan's Publication step, with Review and confirm, through
`ApproveStudioPlanAction` (see "The Publication step" below).

### The preflight

`StudioPlanApprovalPreflight::problems()` says whether a plan is ready to
publish and, when it is not, every reason why, each a stable
`StudioPlanApprovalProblem` code with the editor's field it is about, the
`StudioPlanApprovalRejection` it adds up to, and what to do. It only reads: no
row is written, no post made, no job sent, no rate limit spent, no paid service
called — the screen that offers an approval can ask it, and the approval asks it
again on the locked rows. A Draft's own rules (`StudioPlanValidationRules`) give
the bounds and whether what the plan names exists; on top of them:

| Field | A plan to approve has | Codes |
| --- | --- | --- |
| author | a Persona, not deleted, `Active`, able to create content; its replies' Engagement setting plays no part | `author_missing`, `author_not_persona`, `author_deleted`, `author_restricted` |
| title | 3 to 120 characters | `title_missing`, `title_too_short`, `title_too_long` |
| description, source | within a post's bounds when there are any | `description_too_long`, `source_url_invalid` |
| photo | a `post_image`, `ready`, not deleted, no post's — counting a deleted post that may be restored. Its owner is the uploading administrator and is never compared with the author, and it is not copied or normalized again | `photo_missing`, `photo_deleted`, `photo_wrong_kind`, `photo_not_ready`, `photo_in_use` |
| category | none, or an active one | `category_inactive` |
| tags | tags that exist, each once, ten at most | `tag_missing`, `tag_repeated`, `too_many_tags` |
| author's answers | answers among the active options of the active rating questions as `RatingConfigurationManager` has them now, one per question | `answer_missing`, `answer_inactive`, `answer_group_repeated` |
| date | for `at_date`, a date from 1970 to 2038 — past or future alike; `now` needs none | `publish_at_missing`, `publish_at_out_of_range` |
| discussion | none, or one the executor can publish as it is: the discussion's own rules (`StudioDiscussionValidationRules::validated()`) — at most 40 comments, two levels, every reply under a comment of this plan and no earlier than it, texts of a comment's length, moments from the post to 30 days after it — every comment within the dates a post may carry (`fitsPostDates()`), and every Persona of it a Persona, not deleted, `Active` and able to comment (`canComment()`). A discussion written for an earlier title is an editor's warning, never a refusal | `discussion_too_large`, `discussion_shape_invalid`, `discussion_text_invalid`, `discussion_timing_invalid`, `discussion_date_out_of_range`, `discussion_persona_deleted`, `discussion_persona_not_persona`, `discussion_persona_restricted` |
| votes | none, or votes the executor can cast as they are (`StudioVoteValidationRules::readinessProblems()`, the codes of `readinessErrors()`): at most 1,000; each of its kind's own shape — up or down on the post or a comment, an option and no way for a rating question — a vote named by its id this plan's; its Persona a Persona, not deleted, `Active` and able to vote (`canVote()`); never on the post as its author, nor on a comment it writes; once per target; a comment voted on one of this plan's discussion — whose action the approval writes — and no later than the vote; no vote before the post, more than 30 days after it, or past the dates a post may carry; an option an active one of an active rating question, its own | `votes_too_many`, `vote_shape_invalid`, `vote_persona_deleted`, `vote_persona_not_persona`, `vote_persona_restricted`, `vote_own_content`, `vote_repeated`, `vote_direction_invalid`, `vote_comment_missing`, `vote_timing_invalid`, `vote_date_out_of_range`, `vote_option_unavailable` |

### Approving

`ApproveStudioPlanAction::handle($admin, $plan, $expectedLockVersion)` asks
`manage-studio-plans`, reads the author the plan names and the Personas its
discussion and its prepared votes name, and in one transaction locks the
administrator's, the author's and each of those Personas' `users` rows, each
once, in ascending id order — the order a sanction of any of them, and a save of
the votes, takes — then the plan. A plan found naming another author, or a
discussion or votes naming other Personas, once it is locked was changed
meanwhile and is refused as stale: no user is ever locked after the plan. On the
locked rows it asks again that the administrator may still manage plans, the
plan is still there, still a Draft and still at the version the administrator
saw, and that the preflight finds nothing — every Persona of the discussion
still able to comment, and every voter able to vote, included. Then, in that same transaction, the
plan becomes `Approved` with `approved_by_user_id`, `approved_at` and a new
version, and every action that publishes it is written. Either all of it is
written or none of it is.

A refusal writes nothing and throws `CannotApproveStudioPlanException` with one
`StudioPlanApprovalRejection` — `access_denied`, `plan_gone`, `not_draft`,
`stale_version`, or, from the preflight, `author_unavailable`, `incomplete_plan`,
`invalid_media`, `invalid_taxonomy`, `invalid_publication_date`,
`invalid_discussion` or `invalid_votes` — and the
preflight's problems. An approved plan is no Draft, so a second approval, by
anyone, is `not_draft` and writes no second action. Two administrators
approving one Draft at once take turns on the Persona's row and then the
plan's; an approval racing a save, a new photo or a deletion of the Draft takes
turns on the plan's row, the later one refused; an approval racing a sanction
of the author, a Persona of the discussion or a voter takes turns on the
Persona's row — whichever is first, the approval never approves a Persona the
sanction has already reached; and an approval racing a save of the votes takes
turns on the plan's row, the later one refused, so it approves only the votes it
saw. A category
deactivated, an answer changed or a photo replaced before the approval is
found by it.

### The action it writes

`studio_plan_actions` (`StudioAction`) is Content Studio's business queue: one
row per scheduled domain write of an approved plan, kept in the main database
(`docs/architecture/content-studio-phase-0.md`, E2–E6). What approval writes is
worked out by one class, `StudioPlanSchedule`, from the plan as it is: the
approval writes exactly its set, and the Publication step's Action plan shows
exactly its set before anything is written — never a second reckoning that could
drift from the first. Approval writes the
plan's root action, `create_post` — its Persona as the actor, no parent, an
empty payload: the approved plan, which nothing writes any more, is what it
publishes — `pending`, untried (`attempts` 0); then the discussion's actions
under it: one `create_comment` for every comment of the prepared discussion, its parent the
`create_post`, and one `create_reply` for every reply, its parent the
`create_comment` of the comment it answers — the parent taken from the
discussion's own tree, never from a comment's text or order:

```text
create_post
├── create_comment (C1)
│   ├── create_reply (C2)
│   └── create_reply (C3)
└── create_comment (C4)
```

Each is its comment's Persona's, `pending`, untried, its payload what it
publishes as the plan held it when approved: `discussion_node_id` — the
prepared comment it publishes, which its votes' actions are found by — and the
trimmed `body`; nothing a screen sent.

Then one action for every prepared vote, by the vote's Persona, `pending` and
untried: `vote_post` and `vote_rating` under the `create_post`, and
`vote_comment` under the action of the comment or reply it is on — found by the
comment's id, never its text, its place or its time — so the tree grows:

```text
create_post
├── vote_post
├── vote_rating
├── create_comment (C1)
│   ├── vote_comment (C1)
│   └── create_reply (C2)
│       └── vote_comment (C2)
└── create_comment (C3)
    └── vote_comment (C3)
```

A vote's payload keeps only what was approved, never a post, a comment or a
user a run would act on — those the run takes from what the action it depends
on wrote, and from its actor:

| Action | Payload |
| --- | --- |
| `vote_post` | `vote_type` (`up` or `down`), `studio_plan_vote_id` |
| `vote_comment` | `vote_type`, `discussion_node_id`, `studio_plan_vote_id` |
| `vote_rating` | `rating_group_id`, `rating_option_id`, `studio_plan_vote_id` |

The votes' actions are written after the discussion's, by their moment, then
their place among votes of one moment (`sort_order`), then the prepared vote's
id; the dispatcher sends what is due by `run_after`, then id, and never an
action before the one it depends on is done. A prepared vote no action of the
plan could be written under — a comment the approval writes nothing for —
takes the whole approval back. The other states are the executor's (see
"Running a plan" below). The row is deleted with its plan, and only a Draft,
which has none, is deleted.

Two moments are kept, both in UTC to the second, read from one moment of
approval:

| Plan | `occurs_at` — the post's own date | `run_after` — when it may run |
| --- | --- | --- |
| `now` | the moment of approval | the moment of approval |
| `at_date`, a past date | that date | the moment of approval |
| `at_date`, a future date | that date | that date |

`occurs_at` is the date the post will carry as its own; running late never
changes it — a post meant for now that runs a minute late is still that
moment's, not a historical one. `run_after` keeps a future post back until its
date.

Each comment and reply of the discussion happens its `offset_seconds` after the
post, and may run from then, or from the approval when that is later:

```text
comment.occurs_at = post.occurs_at + offset_seconds
comment.run_after = max(approved_at, comment.occurs_at)
```

A post on 10 June 2025 approved in October 2026 has its comments dated 10 June
2025, minutes after it, all due at once; a future post's comments each wait for
their own moment. Whatever its own time, a comment is never sent before the
action it depends on is done (see "Running a plan"). Each vote likewise:

```text
vote.occurs_at = post.occurs_at + vote.offset_seconds
vote.run_after = max(approved_at, vote.occurs_at)
```

### When it runs

A `pending` row is a passive record until the executor is switched on: no queue,
worker or command acts on it, nothing is sent to Redis, no post is made, and
nothing notifies anybody. Once it is on, it is sent when its `run_after` comes
and what it depends on is done: at once when that is already so — the dispatcher
is woken the moment the approval commits — and otherwise by the run that finds
it due (see "Running a plan" below).

### Logs

`DomainLogger` events `studio_plan.approved` (the plan, the administrator, the
Persona), `studio.action.created` (also the action, its type, its state,
`occurs_at` and `run_after`), both written only once the outermost transaction
has committed, so a rolled-back approval is never logged as one, and
`studio_plan.approval_rejected` (the plan, the administrator, the
Persona, the rejection's code and the problems' codes). Never the plan's texts
or its photo.

## The discussion

A Draft may prepare the discussion its post is to get: comments on the post
and replies to them, each to be written later by a Persona at a moment of its
own. Saving it makes no comment, post, action or job, calls no paid service and
changes nothing on screen; approving the plan writes an action for each of its
comments, and the executor publishes them (see "Approval" and "Running a
plan").

### What a discussion holds

Each comment is a row of `studio_plan_discussion_nodes` (`StudioDiscussionNode`,
`StudioPlan::discussionNodes()`), never a JSON column of the plan and never a
`comments` row:

| Column | Contract |
| --- | --- |
| `studio_plan_id` | the plan; cascades — the discussion goes with its Draft |
| `parent_id` | the comment a reply answers, of the same plan; none for a comment on the post; cascades — a comment takes its replies with it |
| `persona_user_id` | the Persona who is to write it; restricted — accounts become tombstones, never deleted |
| `body` | its text, trimmed, 1 to 1,000 characters — a comment's own bound (`AddCommentAction::MAX_BODY_LENGTH`) |
| `sort_order` | its place among its siblings, 0 to 65,535; places may be equal, the id then keeping the order |
| `offset_seconds` | how long after the post it comes: 0 to 30 days |

Indexes serve a plan's discussion in order (`studio_plan_id`, `parent_id`,
`sort_order`, `id`), a comment's replies (`parent_id`) and a Persona's prepared
comments (`persona_user_id`). Nothing is mass assignable: rows are written only
by `SaveStudioDiscussionTreeAction`.

### Two levels

A discussion has exactly two levels, as the site has (`comment-lifecycle.md`;
`content-studio-phase-0.md`, D6): comments on the post, and replies to a
comment. A reply never answers another reply, nor itself, so no loop can form;
a conversation is several replies to one comment, each addressing whom it
answers in its text. At most 40 comments and replies in all; none is a
discussion too.

### Times

A comment's moment is kept relative to the post — `offset_seconds` after it —
never as a date of its own, so a new date for the post moves the whole
discussion with it, unchanged. No comment comes before the post, nor later than
30 days after it, and no reply before the comment it answers; equal moments
are allowed.

On a plan with a date, no comment may fall past the latest date a post may
carry (`StudioPlanValidationRules::LATEST_PUBLISH_AT`), whichever changes: a
save of the discussion is held to the plan's date, and a save of the plan
(`UpdateStudioPlanAction`) — a new date, or a switch from `now` to `at_date` —
to its saved discussion. On the locked plan it reads the discussion's latest
`offset_seconds` and refuses a date that would carry it past the bound, as a
`ValidationException` on `publishAt`, changing nothing: not the plan, not its
version, not the discussion. The offsets are never moved to make a date fit.
Both checks go through one predicate,
`StudioDiscussionValidationRules::fitsPostDates()`. A plan published at once, or
with no date chosen yet, always fits.

The offsets become each comment's own `occurs_at` and `run_after` only when
the plan is approved (see "The action it writes").

### Ids

Every comment keeps its id through every edit: a later step names one comment
by its id — to write it again, to delete it, to publish it as an action of its
own — never by its text. A save names a comment saved before by its `id`; a
new comment has none, and may have a `key` instead, a name that holds within
that one save, so a new reply can answer a new comment of the same save
(`parentKey`) as an older one answers by `parentId`. The result
(`SavedStudioDiscussion`) gives every comment with its id, and the id each key
was given. An id is never taken on trust: one that is not this plan's refuses
the whole save.

### Saving

`SaveStudioDiscussionTreeAction::handle($admin, $plan, StudioDiscussionData,
$expectedLockVersion)` saves the whole discussion at once: every comment and
reply it is to have, added, changed and deleted together, or not at all. A
comment saved before and left out is deleted, and its replies with it — logged
as `studio.discussion.deleted_branch`; a reply left out takes nothing else
with it. A reply that names a comment the same save leaves out refuses the save
rather than being left dangling. Nothing outside the discussion is touched: no
comment, post or action is deleted — only the votes prepared on the comments
the save deletes, which go with them (see "The votes").

The rules (`StudioDiscussionValidationRules`) are one set for whoever writes
the discussion — an administrator by hand now, the AI generator later — keyed
as the editor's fields (`nodes.3.body`):

- `validated()`, needing no lock: the count, each comment's Persona (one that
  is not deleted), text, place and moment, the two levels, and that a reply
  names a comment of this same discussion;
- `validateAgainst()`, on the rows as they are now: an id is this plan's; each
  Persona is still one and not deleted — a Limited, Shadowbanned or Banned one
  may stay in a Draft, as a plan's author may, since whether it can write is
  asked before anything is published; and a dated post's discussion stays
  inside the dates a post may carry.

The save checks the discussion first, then in one transaction locks the
administrator's and every named Persona's `users` rows in ascending id order,
then the plan (`LocksStudioPlanDraft`) — never a user after the plan — and asks
everything again on them: the administrator may still manage plans, the plan is
still a Draft at the version the editor was drawn from, and
`validateAgainst()` passes — and so does
`StudioVoteValidationRules::validateDiscussion()`: a comment kept keeps the
votes prepared on it — moved, it takes them with it, each at its time after
the comment, so long as they still end within thirty days of the post and the
dates it may carry (`nodes.*.offsetSeconds`) — and is not given to a Persona
voting on it (`nodes.*.personaUserId`); a change that would break either is
refused, never a vote taken away. A save drawn from a version somebody else replaced
is refused, `isStale()`, rather than written over theirs; a plan past its Draft
is `isNotDraft()`. The comments on the post are written first, then the
replies, then the deletions; a write a model event cancels fails the whole save
as an exception would. The save raises the plan's version, like any other
write to it.

A save and a sanction or a deletion of a Persona it names lock that Persona's
row in the same order, so they take turns rather than deadlocking: saved first,
the comment stays and the deletion follows; deleted first, the save is refused.
A save of the discussion and a new date for the post take turns on the plan's
row: the later one, drawn from the version the first replaced, is refused, so
neither can carry a comment past the dates a post may carry.

### The editor

The editor's Discussion step (`StudioPlanDiscussionEditor`, a Livewire
component on the plan's editor page) is where an administrator sees, edits and
generates a Draft's discussion, laid out and working as the prototype's. It is
drawn once with the page and hidden on the other steps, so its settings and a
generation it waits for survive every step; a new publication has none until
its Draft exists, and says so.

It holds no draft of its own: it draws the discussion as saved, each comment
on the post by its time with its replies under it, labelled C1, C2, … in that
order, and writes every change to the plan the moment it is made, through
`SaveStudioDiscussionTreeAction` and nothing else — as the Image step writes
the photo. A comment is written, answered (comments on the post only) or
edited in a dialog: its Persona, the comment it replies to, its text and its
moment after the post in minutes, hours or days; Save writes it, and what the
action refuses stays by its field. Delete asks first — a comment's replies go
with it — and the tree's Timing tab, drawn by the Comment votes editor,
changes a comment's moment in place (`studio-discussion-offset`); a comment
moved takes its votes with it, each at its time after the comment. Each
comment's place among its siblings follows its time. A deletion, and a comment written again,
can be undone from the toast that follows, and only that: the comments deleted
come back as new ones, under their comment when it is still there; the comment
written again gets its text back. Nothing else of the discussion changes, and
the next write here — or a Reload — leaves nothing to undo, so Undo never puts
anything back over a later change.

New choices are of active Personas only (`UserStatus::Active`, through
`PersonasQuery`): the ones offered to check under Manual and counted there, a
random team and its count, and the Personas a comment's dialog offers. A
Limited, Shadowbanned or Banned Persona checked earlier stays checked but takes
no part while restricted; a comment already written by one still shows it,
and its dialog keeps it as the author. What a Draft may store is unchanged:
`SaveStudioDiscussionTreeAction` still takes any living Persona. Select all
checks up to as many as take part in one discussion
(`content_studio.discussion_generation.max_personas`), and both the button and
the count say so.

The plan keeps one `lock_version` for every write, so each write on the page —
the post's Save draft, a photo, and every change here — tells the other editor
the version it was written from and the one it left
(`studio-plan-version-moved`). Since that comes through the browser, the other
takes the new version only when the write was made from the version it holds,
one step on, the plan is at that version now, and what it holds of the plan is
still what the plan has: the post editor compares its fingerprint of the
post's texts and settings, never re-reading the fields, which may be typed and
unsaved; the Discussion step compares a fingerprint of the discussion as it
last read or wrote it. Any other move is somebody else's save, and the next
write there is refused until Reload. A Reload of either editor is said to the
other (`studio-plan-reloaded`): the post editor's Reload, or Discard, draws the
discussion again too; the Discussion step's Reload hands the post editor the
version it drew, taken while the post is as that editor drew it — what is typed
staying as typed — and otherwise leaving the post editor asking for its own.
The two vote editors on the page take part the same way (see "One version,
four editors" and "The Comment votes editor" below).

A whole tree keeps the fingerprint of the post's final title and description
it was written for (`source_text_fingerprint` in its parameters,
`StudioDiscussionContext::textFingerprint()`: each text trimmed, an empty one
as none). While the discussion has comments and the whole tree taken in last
was written for other texts than the plan holds now, the step says so — the
discussion was generated for an earlier title or description — with
Regenerate, which asks before replacing the tree, and Keep as is, which records
the texts it is kept for on that generation (`kept_text_fingerprint`,
`KeepStudioDiscussionAction`) without changing the plan or its version, until
they change again. A tree asked for before generations kept their texts says
nothing.

Generation is asked for there, and only there, on an explicit request, never
while the post has unsaved changes — a generation is written from what is
saved — or another is on its way. Generate tree clicked with the post changed
saves it first: the plan's editor saves the draft and then tells the
discussion editor to generate (`StudioPlanDiscussionEditor::GENERATE_EVENT`);
a save refused generates nothing. On a new publication's page, whose save
opens the plan's editor, the editor opens on the same step and generates
there, told once through the session (`CreateStudioPlan::RESUME`), with the
Discussion settings as they were set: the counts, the Personas and the tone
are kept in the session for the plan (`StudioPlanDiscussionEditor::SETTINGS_SESSION`), so
the editor opened again — by that save, or by a reload — draws them as last
set, and only with none kept as the last whole tree was asked. Generate
tree writes a whole discussion,
Total comments and Top-level comments becoming the comments and replies asked
for, by the Personas checked or, under Random, as many picked at random as
asked for, picked only as the tree is generated; Regenerate writes one saved
comment or reply again. A whole tree that would replace comments asks first,
and only that question's answer starts it: `confirmGenerate()` does nothing
unless Generate asked. What the page waits for is always the generation the
request returned, read from that generation alone — its mode and the comment
it names — never from the button pressed: when another tab or administrator
has one on its way, or one finished and not taken in yet, that one is
returned, the page shows it for what it is — the whole tree in placeholders,
or that comment written again — and says it was asked for earlier, so nothing
new was asked for. While a generation this page asked for, or found on its way
when it opened, is queued or running, the component is read again every few
seconds, and the moment it is ready it is taken in through
`ApplyStudioDiscussionGenerationAction`, at the version this page holds — the
proposal's own checks still apply, so one written from a post or discussion
that changed since is refused and said, nothing saved being written over. A
proposal that finished while no page was waiting for it — the page closed or
reloaded — is taken in when the step opens or reloads, once, through the same
action, if the plan could still take it in
(`ApplyStudioDiscussionGenerationAction::fits()`); one it can no longer take
in stays as history. A whole tree replaces the discussion, with no Undo; a
comment written again changes its text alone, and Undo gives that text back.
None planned empties the discussion with no request at all. Nothing on the
step publishes anything.

### Approving it

A plan with a prepared discussion is approved like any other once the
preflight finds the discussion publishable as it is (see "The preflight"), and
the approval writes an action for every comment and reply with the post's. A
plan whose discussion is emptied is approved with its post alone.

### Logs

`DomainLogger` events, written only once the outermost transaction has
committed: `studio.discussion.saved` (the plan, the administrator, the number
of comments on the post and of replies, the new version) and
`studio.discussion.deleted_branch` (the comment deleted and how many replies
went with it); and `studio.discussion.rejected` (the plan, the administrator, a
code — `access_denied`, `plan_gone`, `not_draft`, `stale_version` or
`invalid_discussion` — and the fields that failed). Never a comment's text nor
anything of a Persona.

### Publishing it

The executor publishes the comments and replies themselves, each once what it
answers is done (see "Running a plan"); the Publication step shows each as an
action of the plan before approval and as it runs afterwards (see "The
Publication step"). The votes on them are prepared apart, on the same step
(see "The Comment votes editor").

## The votes

A Draft may prepare the votes its post is to get — on the post, on the comments
of its prepared discussion, and for its rating questions — each to be cast
later by a Persona at a moment of its own, through the very vote a member casts
(`VotePostAction`, `VoteCommentAction`, `VoteRatingOptionAction`), never a vote
of a kind of its own. A prepared vote is an intention only: saving it makes no
`post_votes`, `comment_votes` or `rating_votes` row, no action and no job,
calls no paid service and changes nothing on screen. Nothing casts one yet;
the votes on the post and its rating questions are prepared on the Post
step's Votes tab (see "The Post votes editor" below), and the votes on the
comments and replies on the Discussion step (see "The Comment votes editor"
below).

### What a vote holds

Each vote is a row of `studio_plan_votes` (`StudioPlanVote`,
`StudioPlan::votes()`): one Persona's intention to vote on one target one way.
It holds no `post_id` or `comment_id` — in a Draft neither exists:

| Column | Contract |
| --- | --- |
| `studio_plan_id` | the plan; cascades — the votes go with their Draft |
| `persona_user_id` | the Persona who is to vote; restricted — accounts become tombstones, never deleted, so a vote is never given to another Persona |
| `type` | `vote_post`, `vote_comment` or `vote_rating` — the three votes of `StudioActionType` |
| `vote_type` | `up` or `down` (`VoteType`) on the post or a comment; none for a rating question |
| `discussion_node_id` | the prepared comment or reply a `vote_comment` is on, of the same plan — `(discussion_node_id, studio_plan_id)` references the comment's `(id, studio_plan_id)`, so not even the database lets it reach another plan's comment; cascades — the vote goes with its comment |
| `rating_group_id`, `rating_option_id` | the rating question and the option a `vote_rating` chooses, as ids only — see "Deletions" below |
| `target_key` | what it is a vote on: `post`, `comment:{discussion_node_id}` or `rating:{rating_group_id}` |
| `offset_seconds` | how long after the post it is cast: 0 to 30 days |
| `sort_order` | its place among votes of one moment, 0 to 65,535; places may be equal, the id then keeping the order |

The database holds each kind to its own fields on every engine — a `CHECK`
with the table, an `enum` for each closed set: a vote on the post names no
comment and no option and is keyed `post`; a vote on a comment names its
comment and no option and is keyed `comment:…`; a rating vote names its
question and option, goes neither up nor down, and is keyed `rating:…` — and
the moment and the place to their bounds. SQLite takes a `CHECK` only with its
table, so there the migration writes the table whole. Indexes serve a plan's
votes in the order they are cast (`studio_plan_id`, `offset_seconds`,
`sort_order`, `id`), a comment's votes and a Persona's prepared votes. Nothing
is mass assignable: rows are written only by `SaveStudioVotesAction`.

### Three kinds

| Kind | Needs | Never |
| --- | --- | --- |
| `vote_post` | a Persona, `up` or `down`, a moment | the post's own author (`StudioPlan.author_user_id`) |
| `vote_comment` | a Persona, `up` or `down`, a comment or reply of this plan's discussion, a moment no earlier than the comment's | the comment's own Persona |
| `vote_rating` | a Persona, an option, a moment; the question is the option's own | the post's own author; an option of another question than the one named; a second option of one question |

A field of another kind is refused, never ignored. Which real comment a
`vote_comment` is on is the result of that comment's `create_comment` or
`create_reply` once the plan runs; nothing in a Draft names it.

### One vote per target

A Persona votes on each target once, whichever way: up and down on one target
are not two votes. `target_key` names the target alone — never the direction or
the option — and `(studio_plan_id, persona_user_id, target_key)` is unique, an
index whose columns are never null, so it holds alike on PostgreSQL, SQLite and
MariaDB. The key is never a caller's: `StudioPlanVote` computes it from the
target every time a row is saved (`targetKeyFor()`), whatever was set.

### Times

A vote's moment is kept relative to the post, as a comment's is, so a new date
for the post moves every vote with it, unchanged. No vote comes before the
post, nor later than 30 days after it, and no vote on a comment before the
comment. On a plan with a date, no vote may fall past the latest date a post
may carry, whichever changes: a save of the votes is held to the plan's date,
and a save of the plan (`UpdateStudioPlanAction`) to its saved votes
(`StudioVoteValidationRules::validatePlanDate()`, on the locked plan, through
`StudioDiscussionValidationRules::fitsPostDates()`), refused on `publishAt`
with nothing changed. A comment moved later than a vote on it is refused by
the discussion's save (see "Saving" under "The discussion"). Nothing is ever
moved to make it fit.

### Deletions

- **The Draft** — its votes go with it.
- **A comment** — a comment deleted from the discussion takes the votes on it
  with it, and a comment with replies takes the votes on its replies too; the
  votes on other comments, on the post and for the rating questions stay. That
  is the discussion's own save, an operation on the Draft itself.
- **A rating option or question** — the rating configuration stays its own to
  change: the ids carry no foreign key, so archiving, deactivating or deleting
  an option is never refused or changed by a prepared vote, and the vote is
  never moved to another option. It keeps naming the option it chose, to be
  found by the checks before it is cast.
- **A Persona** — accounts are never deleted, only made tombstones; a Limited,
  Shadowbanned, Banned or deleted Persona keeps its votes, never given to
  another Persona. Whether it can still vote is asked before its votes are
  cast.
- **The post's author** — a new author leaves the votes as they are; a vote it
  would now cast on its own post is found by the next save of the votes and
  by the checks before they are cast.

### Saving

`SaveStudioVotesAction::handle($admin, $plan, StudioVotesData,
$expectedLockVersion)` saves every vote the Draft prepares at once — added,
changed and deleted together, or not at all — and returns `SavedStudioVotes`:
every vote, in the order they are cast, the plan's new version, and the id each
new vote's `key` was given. A vote saved before is named by its `id` and keeps
it through every edit, of its way, moment, place, option, Persona or target; a
new one has none. A vote left out is deleted. An id that is not this plan's
refuses the whole save.

The rules (`StudioVoteValidationRules`) are one set for whoever writes the
votes, keyed as the editor's fields (`votes.2.voteType`):

- `validated()`, needing no row: at most 1,000 votes; each of the three kinds,
  with the fields of its kind and none of another's; whole numbers for the
  moment and the place, within their bounds — a fraction is refused, never cut
  off; each id and key once;
- `validatedAgainst()`, on the rows as they are now: an id is this plan's; each
  Persona is still one and not deleted; no vote on the Persona's own post or
  comment; a comment voted on is this plan's and comes no later than the vote;
  an option newly chosen is an active option of an active rating question
  (`RatingConfigurationManager`), of the question named if one is; one vote per
  target; and a dated post's votes inside the dates a post may carry. It gives
  each rating vote its option's own question.

What a Draft may hold is less than what may be cast. A Limited, Shadowbanned or
Banned Persona may stay in a Draft, as in the discussion; an option chosen
earlier and archived, deactivated or deleted since stays chosen through any
save that keeps the vote with it — a new choice must be open. Before the votes
are cast, `readinessErrors()` asks more of the votes as saved, keyed by their
place in the plan's order: whatever a Draft is held to, every Persona `Active`
and able to vote (`canVote()`, as `PostPolicy::vote()` asks), and every option
still open, however long ago it was chosen. It only reads.

The save checks the votes first, then in one transaction locks the
administrator's and every voting Persona's `users` rows in ascending id order —
all named by the save itself, so nothing read meanwhile can change which they
are — then the plan (`LocksStudioPlanDraft`), never a user after it, and asks
everything again on them. The post's author and the comments' Personas it
compares with are the locked plan's own rows, which nothing changes without
that lock, so they are not locked themselves. A save drawn from a version
somebody else replaced is refused, `isStale()`; a plan past its Draft is
`isNotDraft()`; a plan deleted meanwhile is `isGone()`.

The database holds a Persona to one vote per target at every moment, not only
at the end, so the save deletes the votes left out first, and every vote kept
but given another Persona or target, to write it again with its own id and its
first date: two votes trading targets would otherwise each meet the other
where it was. Then the votes kept in place are changed, and the moved and the
new ones written, in the order the save lists them. A write a model event
cancels fails the whole save as an exception would. The save raises the plan's
version, like any other write to it.

A save and a sanction or a deletion of a Persona it names lock that Persona's
row in the same order, so they take turns rather than deadlocking: saved first,
the vote stays; deleted first, the save is refused. A save of the votes, a save
of the discussion, a new date for the post and an approval take turns on the
plan's row: the later one, drawn from the version the first replaced, is
refused.

### Approving it

Approval writes an action for every prepared vote (see "The action it writes"
under Approval), once the preflight finds every vote one the executor can cast
as it is — the same checks as `readinessErrors()`, each problem as its own code
on the field `votes`, adding up to `invalid_votes` (see "The preflight"). A
vote that can no longer be cast refuses the whole plan, nothing of it approved
or written; nothing is ever dropped to make a plan fit. The executor casts
them (see "Running a plan", "Votes").

### Logs

`DomainLogger` events: `studio.votes.saved` (the plan, the administrator, how
many votes of each kind, how many were deleted, the new version), written only
once the outermost transaction has committed, and `studio.votes.rejected` (the
plan, the administrator, a code — `access_denied`, `plan_gone`, `not_draft`,
`stale_version` or `invalid_votes` — and the fields that failed).

### The Post votes editor

The editor's Post step has two tabs, as the prototype's (StudioEditor,
StudioPostVotes): **Titles & texts**, the post as before, and **Votes**, the
votes on the post and the answers to its rating questions. Continue goes from
the texts to the votes and on to Discussion; Back goes the other way, and
Discussion's Back comes back to the votes. The footer says where the editor is
(`Step 2 of 4 · Post · Votes`). The four steps stay as they are.

The Votes tab is `StudioPlanPostVotesEditor`, drawn once with the page and
hidden on the other steps and tab, like the Discussion step, so its settings
and its draw live as long as the page; the texts' cards stay drawn while
the votes are on screen. A new publication has no plan to keep votes on until
its Draft is made: the tab says "Save the draft first to prepare its votes.",
and the editor appears by itself once a photo or Save has made the Draft.

What is on it, as the prototype lays it out:

- **Post voters** — the post's author, who never votes on it ("Can’t vote on
  own post"); **Random**, a number of the active Personas who can vote, of how
  many there are, with Pick others; or **Choose**, Personas found on the
  server (`PersonasQuery`, never every account sent to the browser) and
  checked one by one, Select all or shown, and Clear — the author listed but
  never chosen ("Author of this post · can’t vote on it"). Too few voters for
  what is asked is said exactly ("…need 5 personas; 3 are chosen — 2 short"),
  and nothing is lowered to fit.
- **Post votes** — Up · down, Answers and Post voters at a glance; then three
  tabs. *Votes & answers*: up and down votes on the post, each a number of
  different Personas, drawn at random from the post voters — who votes which
  way is on the Voters tab, when on the Timing tab, as the block says; and
  every active rating question with its active options
  (`RatingConfigurationManager`) — on as it is under every post on the site,
  no switch to turn it on, answers set to none planning none — its number of
  Answers spread over its options in proportion to what each has, **Even
  split** — as even as whole answers go, the rest to the first options in the
  question's own order — and + and − moving one answer between options, the
  total kept. The author's own answers (`authorAnswerOptionIds`) are a field
  of the post, not these. *Voters*: every voter with its post vote and its
  answer to each question, All, Voting or Not used, and Reshuffle. *Timing*:
  when the voters come — First vote after and Spread over, 5 min to 1 day and
  1 h to 1 week, one pair for all of them: each voter comes once, votes on
  the post, then answers the questions a minute or two apart — then every
  vote: Persona, When, on what, which way or which answer, and what keeps it
  from being cast now (`readinessErrors()`). A vote's When is changed in
  place, in minutes, hours or days after the post.

Every change is saved at once, as the prototype's draft changes with each
setting: the votes the settings ask for are made through
`BuildStudioPostVotesData` (below) — a Persona keeping its saved vote, and
its moment, as far as the settings allow, so a change moves only what it
must — and saved, unless they are the votes saved already. A change that
can't be made — too few Personas, a moment past thirty days — is said where it
is and in the strip under the card ("Not saved · fix what is marked"), and
nothing is saved until it is fixed, or **Discard** draws the saved votes
again. **Pick others** and **Reshuffle** make a new draw, saved at once.
**Remove post votes**, asked first, takes the votes on the post and its
questions away. Saving and removing go through `SaveStudioVotesAction` with
the plan's whole set: the discussion's votes are read as the plan has them at
the version the save is checked against, and kept as they are — a comment the
discussion deleted meanwhile, with its votes, is not put back.

A vote's moment set by hand — never before the post, nor more than thirty
days after — is saved at once, every other vote keeping the moment it has:
from then on they keep them, a vote added comes in its voter's visit, and
First vote after or Spread over lays them all out again ("Set by hand" says
the votes keep moments of their own).

Opened again, the editor draws the votes as saved: how many vote the post up
and down, the Personas — as chosen by hand, the ones the votes have — the
answers to each option, and when, the first vote and the spread to the last,
and each vote's own moment when any came away from its place in that layout.
How they were drawn is not kept, and not pretended to be. The strip under the
card says what is saved, and how many votes can't be cast now. A Persona
Limited, Shadowbanned, Banned or deleted since stays in the saved votes, with
its state, until it is replaced; an option archived, deactivated or deleted
since stays as chosen, marked Not open, until its answers are moved or Even
split puts them on the open ones.

#### Making the votes

`BuildStudioPostVotesData::handle($plan, StudioPostVotesSettings)` makes the
votes on the post and its rating questions and only reads:

- **Who:** the Personas chosen by hand, or as many as asked for drawn from the
  active ones. Never the plan's saved author. A new vote only for an active
  Persona able to vote; one chosen earlier and restricted since keeps the vote
  it has, and a deleted one keeps none.
- **What:** each Persona votes on the post once, up or down, and answers each
  question once, with one option — so the votes on the post, and the answers
  to each question, each need that many Personas. A Persona may vote on the
  post and answer every question.
- **Which Persona casts which:** in an order a seed draws, so the same
  settings give the same votes. While the saved votes are preferred, a
  Persona keeps its saved vote on a target as far as the counts allow, and a
  vote that has to change goes first to a Persona already voting on that
  target, so its row is changed rather than replaced; a new draw prefers
  nothing.
- **When:** every voter comes once and casts all its votes then — on the
  post first, then the answers in the questions' order, each 1, 2 or 3
  minutes after the one before (`visitSteps()`, the same for the same
  Persona, so a layout saved is read back as one). The voters come one after
  another, the first after First vote after and the last after the spread:
  `visit(i) = first + floor(i × spread / (n − 1))`, `i = 0 … n − 1`; one
  voter comes at the first moment; while the saved votes are preferred, the
  voters who had votes come in the order they came. A vote whose Persona has a
  moment of its own on the target (`postMoments`, a question's `moments`)
  comes then; equal moments keep the order drawn (`sort_order`).
- **Ids:** a vote that is a saved one's Persona and target again keeps that
  vote's id, whatever changed of its way, option or moment.
- **Refused, whole:** too few Personas, an option that is not the question's or
  more answers kept on an option no longer open than it had, a last moment
  past thirty days or the dates a post may carry, or more than 1,000 votes
  with the discussion's — keyed by what to change, nothing cut to fit.

#### One version, four editors

The page's editors — the post's, the discussion's, the post votes' and the
comment votes' (see "The Comment votes editor" below) — share the plan's
`lock_version`. A save of the post votes raises it by one and says so with
`studio-plan-version-moved` from `post_votes`; a Reload of it says
`studio-plan-reloaded` from `post_votes`. The post votes editor takes a move
of the post's (`editor`), the discussion's (`discussion`) or the comment
votes' (`discussion_votes`) when it was written from the version it holds and
the plan's post and rating votes are still the ones it last read or wrote; the
other editors take its moves the same way. Anything else is somebody else's
save: the editor says the draft changed, writes nothing, and asks for Reload.
An author picked on the page and not saved yet is the post's author to be
(the page tells the editor, `$store.adminEditor.authorUserId`,
`followAuthor()`): the Post voters card shows it as "author, not saved yet",
and it is left out of the voters at once as the saved one is — its votes, if
it has any, go to others — so the votes are saved at once whatever the page
holds. A Save draft that gives
the post another author is read on the move it takes, and the votes are made
again right after, on a request of their own once every editor on the page
has taken that move (`remake()`): the new author never votes on it. Chosen
among the voters, its place goes to another active Persona; in a random draw
of every voter, one fewer is drawn. An author the editor finds among the
saved voters when it opens is left out the same way, so a vote it can't cast
never stands in the way of the comment votes' save. Nothing on the tab saves
the post's texts, photo, category or author.

A change that can't be saved is not left behind unasked: Back, Continue and
the steps, leaving the Post step, ask the editor first (`rgPostVotes` store,
`askLeave()`). It tries the change once more and, when it still can't be
made, says what keeps it and offers Keep editing or Discard change, which
goes on with the votes saved. Moving between the step's own tabs asks
nothing.

#### Departures from the prototype

- As in the prototype, the votes change as each setting moves; here each
  change is saved at once, a change that can't be made is said and saves
  nothing, and each vote's moment can be set by hand.
- The prototype times the votes on the post and each question's answers
  apart; here one pair times the voters' visits, so a Persona's vote and
  answers come a minute or two apart, as one visitor's would.
- The prototype lowers the votes to fit fewer voters; here the shortfall is
  said and nothing is lowered.
- The prototype's closed questions (not shown under a post) have no
  counterpart on the site: every active rating question is offered, and one no
  longer active shows only while saved answers name it.
- The list of every vote on the Timing tab, with each one's moment to change,
  is this editor's: the prototype's post timeline shows the votes in a few
  batches.

### The Comment votes editor

The Discussion step draws `StudioPlanDiscussionVotesEditor`, apart from the
discussion: the votes on the prepared comments and replies. Its parts stand
where the step lays them out, and the discussion editor keeps an empty place
for each (`wire:ignore`), which the votes editor draws in with `@teleport`, so
their state and actions stay the votes editor's:

- the **Comment voters** and **Comment votes** cards, in the step's settings
  row beside the Discussion settings, as the prototype's three cards
  (`#rg-admin-studio-comment-voters`, `#rg-admin-studio-comment-vote-settings`);
- each comment's **breakdown**, beside the discussion tree
  (`#rg-admin-studio-comment-breakdown`);
- the voters, on the tree's **Voters** tab
  (`#rg-admin-studio-comment-voters-table`);
- the tree's **Timing** tab (`#rg-admin-studio-timing`): every comment,
  reply and vote in one list.

Under the tree it says only what keeps the votes from being saved: a draft
deleted, a plan past its Draft, or somebody else's change to reload. The
discussion's own card, tabs and dialogs stay as they are; the votes on the
post and its questions stay on Post · Votes. Like the other editors it is
drawn once with the page, so its settings and the votes made of them live as
long as the page does.

What is on it:

- **Comment voters** — **Random**, a number of the active Personas who can
  vote, of how many there are, with Pick others; or **Choose**, Personas found
  on the server (`PersonasQuery`) and checked one by one, Select all or shown,
  and Clear. A Persona who writes a comment is listed with it ("Writes C3 ·
  can’t vote on it") and may still be chosen: it never votes on its own
  comment, and votes on the others all the same. A deleted or restricted
  Persona gets no new vote; a restricted one keeps the vote it has until it is
  replaced.
- **Comment votes** — with no comments yet, Total up, Total down and when the
  votes come are what the comments to come get: once the tree is generated or
  written, the totals are shared out over its comments by priority and their
  votes come as asked, as votes not saved yet — the same carry-over as a tree
  written anew. Until then they are kept for the plan in the session
  (`StudioPlanDiscussionVotesEditor::ASKED_SESSION`), so the editor opened
  again — by a new publication's save, say — starts from them. A comment added
  later takes the timing last asked of every comment. Then Total up and Total down, always what the comments'
  votes add up to. **Distribute automatically** shares both among the
  comments by their priorities' weights (Low 1, Normal 2, High 3): each gets
  the whole part of its share, and the votes left over go to the largest
  remainders, equal ones to the comment first in the tree — never drawn
  (`BuildStudioDiscussionVotesData::distribute()`). A total changed gives at
  once only the difference: each vote gained to the comment furthest below
  its share of the new total, equal ones first in the tree, and each lost
  from the one furthest above, equal ones last — every other comment keeps
  its votes as set. A comment's own votes changed in its breakdown move the
  totals. Then when every comment's votes come: First vote after (5 min to 1
  day) and Spread over (1 h to 1 week), counted from each comment's own
  moment. Both totals set to none and saved take every vote on the comments
  away.
- **On the tree** — each comment shows, beside its moment, its up and down
  votes as set on the step, "Not saved" while they differ from the saved ones,
  and a mark when something stands in the way of them; **See breakdown**
  under them opens the comment's breakdown. The Generate strip says the
  comment votes after the discussion's own numbers — how many, up and down —
  and "Not saved" while they are not. The Voters tab lists every voter with the comments it votes on
  up and down and the ones it writes — All, Voting, Also commenting or Not
  used — and Reshuffle. The discussion editor draws the saved counts and the
  votes editor puts what is set over them, through the `rgCommentVotes` store
  (`filament.pages.studio-plan.discussion`).
- **Timing** — the tree's tab lists every comment, reply and vote on them in
  one list, in the order they come after the post, a comment before the votes
  at its moment: #, Action (Comment C1, Reply C2, Vote up or down), Executor,
  Parent (the post, the comment answered, or the comment voted on), Offset
  from post, After its parent, Dependency ("After C1 at +2 min") and, for a
  comment, its votes and when they come. Every moment changes in place, in
  min, h or d. A comment's is the discussion editor's: told to it
  (`StudioPlanDiscussionEditor::OFFSET_EVENT`), it is saved at once, its
  votes moving with it — never while a generation is on its way, and never
  before the comment a reply answers. A vote's is set here, as its Persona's
  own moment after the comment, never before the comment nor past thirty
  days, marked "Not saved" and kept with Save votes, which the tab offers
  with Discard while anything is unsaved. A new First vote after or Spread
  over for a comment spreads its votes again, none keeping a moment of its
  own. Opened again, a vote saved away from its place in the spread keeps its
  moment.
- **The breakdown** — beside the tree, which moves aside for it where both
  fit and gives it its place where they do not, until it is closed: the
  comment, its up and down votes set by hand, its priority, its own First vote
  after and Spread over, and each vote as it stands — who casts it, which way,
  and when, after the post and after the comment — with what keeps a saved
  one from being cast. A comment asking for more votes than it has voters says
  how many it needs, how many can, and how many are short.

The settings are a recipe, never saved. As soon as they ask for other votes
than the saved ones, the votes are made of them (`BuildStudioDiscussionVotesData`)
and shown as they will be saved — on the tree, in the breakdown, on the Voters
and Timing tabs — and made again whenever the settings, the draw or the
comments change; while the settings are the saved votes' and no new draw is
asked, the saved votes are shown. **Save votes**, in the breakdown or on the
Timing tab, saves
every comment's votes exactly as shown; votes that are the saved ones again
save nothing, and votes not yet made — a comment short of voters — are not
saved until what is marked is fixed. **Pick others** and **Reshuffle** draw
anew with the same numbers; **Discard** puts the saved votes back. Saving goes
through `SaveStudioVotesAction` with the plan's whole
set: the votes on the post and its questions are read as the plan has them at
the version the save is checked against, and kept as they are. When one of
those no longer passes — its Persona deleted since, say — the save is refused,
saying what stands in the way and that it is fixed on Post · Votes; nothing of
them is changed here.

The step is not left with votes unsaved without asking: Back, Continue and the
editor's steps ask the votes editor first, which lists what is not saved —
each changed comment's votes as set and as saved, or what stands in the way of
them — and asks whether to **Save and continue**, **Discard changes** or
**Keep editing**, and lets the step go on once the votes are saved or
discarded; a save that cannot be made stays.
Leaving the page asks as the browser does.

A discussion written anew as a whole — Regenerate tree — takes the old
comments' votes with it, but not what was asked of them: the editor keeps its
voters, shares the same Total up and Total down out over the new comments by
priority, and gives them the timing every voted comment had, as votes not
saved yet. A comment deleted on its own takes its votes, and the totals drop.

Opened again, the editor draws the votes as saved: each comment's up and down
votes, their Personas as chosen by hand, and their timing after the comment.
Priorities are not part of a vote, so every comment starts Normal; nothing
else of how the votes were drawn is kept or pretended.

#### Making the votes

`BuildStudioDiscussionVotesData::handle($plan,
StudioDiscussionVotesSettings)` makes the votes on the comments and only
reads; it shares its draw with the post votes' builder
(`DrawsPersonaVotes`):

- **Who:** the Personas chosen by hand, or as many as asked for drawn from the
  active ones — on each comment, all of them but the comment's own Persona. A
  new vote only for an active Persona able to vote; one chosen earlier and
  restricted since keeps the vote it has, and a deleted one keeps none.
- **What:** each Persona votes on a comment once, up or down, so a comment's
  votes need as many of its voters; one Persona votes on as many comments as
  it is given.
- **Which Persona casts which:** in an order a seed draws, one of its own
  for each comment, so the votes are shared among the voters rather than cast
  by the same few on every comment. While the saved votes are preferred, a
  Persona keeps its saved vote on a comment as far as the counts allow, and
  its place among the comment's votes in time; a new draw prefers nothing.
- **When:** a comment's votes come one after another from its own moment:
  `offset(i) = comment + first + floor(i × spread / (n − 1))`, seconds after
  the post as every prepared vote is kept, so no vote comes before its
  comment.
- **Ids:** a vote of a Persona on a comment that it had saved keeps that
  vote's id.
- **Refused, whole:** too few voters for a comment, a comment the discussion
  no longer has, a last moment past thirty days or the dates a post may carry,
  or more than 1,000 votes with the post's — keyed by the comment to change
  (`targets.{node}`, `timing.{node}`), nothing cut to fit.

#### Its version

A save of the comment votes raises the plan's version by one and says so with
`studio-plan-version-moved` from `discussion_votes`; a Reload says
`studio-plan-reloaded` from `discussion_votes`. It takes a move of the post's
or the post votes' when it was written from the version it holds and the
plan's comment votes and comments are still the ones it last read or wrote. A
move of the discussion's own is taken the same way, its comments read again —
a new one offered with no votes, a deleted one gone — so long as no comment
vote was added or changed: deleting a comment takes its votes with it, and
that is all a save of the discussion may do to them. Votes made before the
comments changed are made again before they are saved. Anything else
is somebody else's save: the editor says the draft changed, writes nothing,
and asks for Reload.

#### Departures from the prototype

- Priorities are Low, Normal and High, weighted 1, 2 and 3, and the totals are
  Total up and Total down, instead of the prototype's Normal, High and Main
  with a total, a positive share and a choice of distributions.
- A comment's votes are set in its breakdown beside the tree, opened with See
  breakdown, rather than in a Comment votes tab of the tree; the tree keeps
  Comments, Voters and Timing.
- The Timing tab lists the votes among the comments, each moment changed in
  place; a comment moved takes its votes with it, and a vote may be given a
  moment of its own.
- One First vote after and one Spread over, counted from each comment's own
  moment, for every comment at once, or for one comment in its breakdown.
- The totals are what the comments' votes add up to, and a total changed
  gives or takes only the difference at once, as the prototype's sliders do,
  keeping each comment's own votes.
- The votes are made as soon as they are asked for, as the prototype's draft
  is, but saved on purpose with Save votes in a comment's breakdown, and
  leaving the step with them unsaved asks first, listing what changed.
- The comment votes are said in the Generate strip, and every vote is shown
  at its moment on the Timing tab, which the prototype does not show.

### Casting them

Approval writes their actions and the executor casts them (see "Running a
plan", "Votes"); the Publication step shows each before approval and as it
runs, and the Action queue retries, resumes or cancels one (see "The
Publication step", "The Action queue").

## Discussion generation

An administrator may have a discussion written by OpenAI — a whole one, or one
part of the plan's again. What comes back is a **proposal**, kept apart from the
plan's discussion: nothing reaches the discussion until an administrator takes
it in. Nothing here makes a comment, post or action, changes anything on
screen, or runs by itself.

### What it writes

| Mode | Writes | Keeps |
| --- | --- | --- |
| `full_discussion` | a whole new discussion by the Personas chosen: exactly the comments on the post and replies in all asked for, two levels, each comment with its Persona, text, place and time | — taken in, it replaces the plan's discussion whole |
| `regenerate_comment` | the text of one comment on the post | its id, author, place, time and replies; every other comment |
| `regenerate_reply` | the text of one reply | its id, author, comment, place and time; every other comment |
| `regenerate_branch` | the texts of one comment on the post and of each of its replies | every id, author, parent, place and time, how many replies there are; every other comment |

A part keeps its shape so that what will later point at its comments — votes on
them among other things — still finds them. Adding, deleting or moving a reply
is the editor's, by hand. A whole discussion is a new proposal beside the
plan's: asking for one deletes nothing.

### Who takes part

For a whole discussion, the Personas the administrator chose — one to eight
(`max_personas`), each once, never picked at random or from anyone else; for a
part, the authors of its comments. Each must still be a Persona that is not
deleted; a Limited, Shadowbanned or Banned one may take part in a draft, as in
a discussion saved by hand, and its engagement and daily reply limit — which
govern replies it would write on its own — play no part. The model is told of
each only what its `PersonaProfile` says of how it writes: display name, short
description, character, speech style, topics and jokes, what it never writes
about, and how often it uses emoji — never an email, username, id, password,
sign-in or security setting (`StudioDiscussionPersona`). How a project's
Personas are made and imported, and what the public sees of them, is
`docs/architecture/studio-personas.md`.

### What it is written from

`StudioDiscussionContext`: the post's final title — required; never one picked
from the suggestions on its behalf — and description, which may be missing as a
post's may; its category and tags; the finished analysis of the plan's photo
(`StudioPostTextContext`), never the photo; the Personas taking part; and the
plan's discussion as it is, every comment with its author and time, so a part
knows what it answers. Nothing of any other plan. Without a finished analysis
of the plan's photo, or without a title, nothing is asked for and nothing is
paid for (`analysis_missing`, `incomplete_post`).

Everything from the plan and the Personas is data, never instructions: it
travels as JSON with `<` and `>` escaped, so nothing inside it can close its
block. The instructions are fixed text of the application's own, the tone's
(`StudioPostTextTone`, chosen under Tone in Discussion settings — Cheeky at
first, then as last set for the plan, kept with the other settings, or else
the tone the last whole tree was asked in — and asked of every
generation, a whole tree or a part; each Persona's own way of speaking comes
first in what it writes) and the mode's, in which only the request's own
aliases and counts are filled in: a task says which comment to write and by
whom as `existing_3` and `persona_2`, never by a display name, a post's text
or a comment, which an administrator may have typed and which travel only as
data. The model gives no id; the server maps the aliases back. The rules match the post text
suggestions': nothing invented about the photo or the people in it, never
explicit, never sexualising anyone who is or may be a minor or whose age is
unclear, age never judged from looks, and no sexualised remark about a
specific person shown — that would need separate, reliable confirmation that
the person is an adult, and nothing a request carries is one: the analysis
never judges age, and a tone, Spicy included, is a mood, never a fact about
anyone's age; the innuendo, flirting and double meanings play on the
situation, the post, wordplay and the commenters' own banter — never
demeaning, threatening or discriminating, English, plain text.

What makes the conversation is said as fixed text too:

- **Voices.** Each Persona writes as its profile says — vocabulary, length,
  punctuation, grammar, kind of humour, topics, what it never writes about,
  emoji — and stays the same person throughout; quirks of spelling or grammar
  are only the Personas' whose speech style has them, never mistakes of the
  model's; innuendo only from Personas whose character has it.
- **Parts.** The Personas take different parts as their characters suggest —
  one starts a joke, another builds on it, someone disagrees, someone answers
  with sarcasm, someone changes the angle, someone reacts in two words, someone
  adds a double meaning, someone comments on how the others behave — in a shape
  chosen fresh for every discussion, never assigned and never the same pattern.
- **Replies.** A reply continues the exact comment it answers and the replies
  before it in its thread — picks up its words, joke or claim and does
  something with it; one that would fit under any comment is wrong (under “I
  came here for the article.”, “Same. Still looking for the first paragraph.”,
  never “Beautiful photo!”).
- **Variety.** Quick jabs, teasing, self-irony, wordplay, flirting, double
  meanings, absurd observations, sarcastic answers, ironic disagreement and
  reactions to each other's jokes; two-word reactions beside one-liners,
  two-line jokes and the odd longer observation; no phrase, joke or comparison
  twice; not every comment a compliment, and no stock praise or anything
  promotional. The title is never repeated, but may be riffed on; the photo is
  not described back unless that is the joke.

The limits are the ones they were: one to eight Personas a generation
(`max_personas`), up to 40 comments and replies, two levels, 1 to 1,000
characters a comment.

### The answer

OpenAI's Responses API (`OpenAiStudioDiscussionGenerator`): one stateless
request — `store` false, no conversation, tool or web search, no retry, no
redirect — answered as strict Structured Output against the mode's schema
(`StudioDiscussionProposal::schema()`). The answer is a proposal only when it
passes every check (`StudioDiscussionProposal::from()`): exactly the mode's
fields, of their types; a whole discussion of exactly the comments and replies
asked for, by the Personas taking part only, keys of its own, no later than
the plan's date leaves; a part of exactly its own comments, named as the
request named them, in order; no two texts the same; and the discussion it
makes — the new one, or the plan's with the new texts in it, built in memory —
held to `StudioDiscussionValidationRules`, the one set every discussion is.
Anything else fails the generation whole; nothing is trimmed into shape.

### History

`studio_plan_discussion_generations` (`StudioPlanDiscussionGeneration`,
`StudioPlan::discussionGenerations()`) keeps a row per attempt, gone with its
plan: who asked; the mode; the comment written again (`target_node_id`, cleared
if that comment is deleted from the draft, its id kept in `parameters`); the
Personas; everything asked for; what it was written from — the plan's version,
the photo's id, and three hashes that together hold everything the model is
sent: the analysis; the post's texts with the discussion and the names its
authors go by; and the profiles and names of the Personas taking part — a
deleted account's name a fixed one, so no hash changes with a locale; its
state, attempt id and error code;
the validated proposal (`result`); the model, request id, tokens and latency;
and when it was asked, started, finished and taken in (`applied_at`). Never a
prompt, a photo, a key or what the provider sent back beyond the proposal, and
nothing of it reaches any page or API.

### Life of a generation

`queued` → `running` → `completed` or `failed`. Completed says only that a
proposal was written; `applied_at` says it was taken in.

- `RequestStudioDiscussionGenerationAction` asks `manage-studio-plans`, that it
  is configured, and the counts (a whole discussion of 1 to 40 in all); then
  locks the administrator's and every Persona's `users` rows in ascending id
  order — the authors of a part read before any lock, asked again once locked —
  then the plan, still a Draft at the version the editor was drawn from, its
  photo analysis, then its generations on their way. It checks the Personas,
  the part and the context, writes the generation `queued` and sends
  `GenerateStudioDiscussionJob` once the transaction commits. The plan and its
  discussion are not touched, its version included. While a generation of the
  plan is on its way, asking again returns that one, whatever operation is
  asked for: no second paid request from a double click, a second tab or a
  second administrator. So does the plan's last generation once it has
  finished with a proposal not taken in yet that the plan could still take in
  (`ApplyStudioDiscussionGenerationAction::fits()`: the checks taking it in
  makes, read only — a Draft, its context unchanged, a discussion its rules
  take). The caller reads what it waits for from the generation returned. One
  the plan can no longer take in stays as history and stands in the way of
  nothing. One on its way longer than `stale_after_seconds` is ended as lost.
- `GenerateStudioDiscussionJob` carries the generation's id and attempt id only;
  `tries` 1, `timeout` 75, `failOnTimeout`, the request's own timeout 45 s. It
  claims its generation first (`StartStudioDiscussionGenerationAction`): only a
  queued one with that attempt id is claimed, so a job delivered twice sends
  nothing. It sends nothing unless the plan is still a Draft with the same
  context as the hashes say, and generation still configured; no transaction is
  open while it waits. A worker that dies after sending leaves the generation
  to be asked for again only by an administrator.
- `CompleteStudioDiscussionGenerationAction` keeps the proposal — on the plan,
  its analysis and the generation, locked — only when the plan is still a
  Draft with the same context and the answer is a valid proposal for it;
  otherwise the generation fails (`plan_closed`, `stale_context`,
  `invalid_persona`, `invalid_provider_response`), and nothing out of date is
  kept as a proposal. A new date for the plan is no change of context: the
  discussion is relative to it.
- `FailStudioDiscussionGenerationAction` ends a generation with a reason, only
  while it is on its way.

### Taking a proposal in

`ApplyStudioDiscussionGenerationAction::handle($admin, $plan, $generationId,
$expectedLockVersion)` — the trusted step the editor's Discussion step takes
the moment a generation it waits for is ready.
Before any lock it reads every Persona the check and the save depend on — those
the proposal was written for, even one none of whose comments made it in, whose
profile the hash holds; the authors of the discussion it was written from,
whose names the hash holds; and the authors of the discussion it makes — and
locks their `users` rows with the administrator's in ascending id order, then
the plan at the version the administrator saw, its analysis, then the
generation. None is locked after the plan: one found only once the plan is
locked refuses the proposal (`stale_context`). An edit of any of those Personas
therefore waits for the proposal to be taken in, or comes first and makes it
stale; it never slips in between the check and the commit. It takes in only a completed proposal of this plan, not taken in
before (`not_completed`, `already_applied`), written from the context the plan
still has — the same post, photo, analysis, discussion and Personas
(`stale_context`). A discussion edited by hand since, a comment deleted, an
author changed, a Persona's profile changed: the proposal can no longer be
taken in, so nothing saved is written over without being seen.

The discussion it makes is saved through `SaveStudioDiscussionTreeAction`, its
checks and all: a whole discussion replaces the plan's, every comment given a
new id; a part changes only its own texts, every id kept. The save and
`applied_at` commit together, or neither does; the plan's version rises once.
A hand save, a new date for the post, a deletion or another administrator
taking the same proposal in take turns on the plan's row: the later one is
refused as `stale_version`. Earlier generations stay as history.

### Errors and logs

`StudioDiscussionGenerationError`: `not_configured`, `analysis_missing`,
`incomplete_post`, `no_personas`, `invalid_persona`, `invalid_target`,
`stale_version`, `stale_context`, `plan_closed`, `not_completed`,
`already_applied`, `authentication_failed`, `rate_limited`, `provider_timeout`,
`provider_unavailable`, `provider_rejected`, `provider_refused`,
`invalid_provider_response`, `unexpected_failure` — each with a message of its
own, never anything the provider said.

`DomainLogger` events `studio.discussion_generation.requested`, `.started`,
`.completed`, `.failed`, `.discarded`, `.applied` and `.rejected`, with the
plan, the generation, the administrator, the mode, the comment written again,
how many comments, the state, the code, the model, the latency, the tokens and
the request id. Never a comment, a character, the instructions, the key or the
analysis.

### Configuration

The key, model, base URL and switch are photo analysis's
(`content_studio.image_analysis`): one Content Studio integration, never
Translation AI's key. `content_studio.discussion_generation` holds the rest:
`connect_timeout` 5, `timeout` 45, `max_output_tokens` 8000,
`stale_after_seconds` 180, `max_nodes` 40, `max_personas` 8. Switched off, a
discussion is still written and saved by hand.

## Running a plan

The executor publishes an approved plan's `create_post` as a real post of its
Persona, then each `create_comment` as a real comment on it and each
`create_reply` as a real reply to that comment, and casts each `vote_post`,
`vote_comment` and `vote_rating` as a real vote of its Persona on that post, on
that comment, or for that rating option — every one by its own Persona, dated
as the plan said — and applies each once however often its job is delivered.
Every kind approval writes runs (`ExecuteStudioAction::RUNNABLE`).

### Life of an action

`pending` → `dispatched` → `running` → `done`, and besides:

| State | Means | Moved there by |
| --- | --- | --- |
| `pending` | waits for its `run_after` and a parent done | approval; the executor, for a Persona at its posting, commenting or voting limit, or for one sent before its parent was done (`parent_not_done`) |
| `dispatched` | its job is on the queue; `attempts` counts this send | `studio:dispatch-due`, sending it or sending it again |
| `running` | claimed by a worker (`claimed_at`); its post not yet committed | the executor's claim |
| `done` | its post, comment or vote written — or, for a vote, the same vote found already cast; `result_type`/`result_id` and the real `finished_at` kept | the executor's run |
| `held` | stopped for an administrator to resume: the Persona Limited or Shadowbanned | the executor |
| `cancelled` | for good: the Persona banned, deleted or no Persona; the plan, photo, category or an answer no longer usable; the post a comment or vote is for hidden, deleted or unpublished (`post_unavailable`), the comment a reply answers hidden or deleted (`reply_target_unavailable`), the comment a vote is on hidden or deleted (`comment_unavailable`), the option a rating vote chooses, or its question, no longer active (`rating_option_unavailable`); or the action it depends on cancelled (`parent_cancelled`) | the executor |
| `failed` | for an administrator to decide: the approver may no longer approve, the Persona's vote already there going the other way or choosing another option (`vote_conflict`), it was sent `max_attempts` times, its posting, commenting or voting limit sent it back `max_deferrals` times, or something unexpected | the executor; `studio:dispatch-due` |

Each stop keeps a stable `failure_code` (`StudioActionFailure`) and, for an
unexpected error, only the exception's class in `failure_reason`.

**Dependencies.** An action runs only once its parent is done: a comment after
its post, a reply after its comment, a vote on the post or for a rating option
after the post, a vote on a comment or reply after that comment or reply. A parent pending, sent, running, held or
failed — waiting for its time, for recovery or for an administrator — leaves
its children pending; nothing of a branch is cancelled for a stop that is not
final. A parent cancelled for good takes its branch with it, in the
transaction that cancels it (`CancelsDependentStudioActions`): every action
below it, however deep, still pending, held or sent is cancelled,
`parent_cancelled`; what is done stays done, and nothing beside the branch —
another comment, another comment's replies — is touched. Asked again, it
cancels nothing more. The dispatcher sends only children whose parent is done,
and the executor asks again: at the claim, a child sent before its parent was
done goes back to `pending` (the send counted as a deferral, never as a lost
delivery), or, its parent cancelled, is cancelled with its branch; and right
before writing, the run asks the parent once more. Nothing is
deleted: a held, cancelled or failed action stays as the record of what
happened. Nothing resumes a held action by itself; resuming, retrying and
cancelling are an administrator's, in the Action queue (see "The Action queue"
below).

### The dispatcher

`studio:dispatch-due`, scheduled `everyMinute()->withoutOverlapping(5)` and run
by the existing `schedule:run` — no cron or Supervisor program of its own
(`DispatchDueStudioActionsAction`). Its overlap lock lapses after five minutes
rather than Laravel's default day, so a scheduler killed mid-run stops sending
for minutes, not a day; two runs at once are safe anyway. Each run, at most
`batch_size` of each:

1. sends again the actions lost on their way: `dispatched`, and not claimed
   `dispatched_stale_after_seconds` after they were sent — Redis lost the job,
   a worker of an older release did not know it, the queue was flushed;
2. sends again the actions left by a worker that died: `running`, and not
   finished `running_stale_after_seconds` after they were claimed;
3. sends what is due: `pending`, its `run_after` come, of a kind that runs,
   with no parent or a parent done — `run_after` first, then id. Approval
   writes a discussion's actions by time, so comments due at once go in the
   order they come.

Each one is locked and asked again before it is sent, in a short transaction
of its own: it becomes `dispatched`, `attempts` one more, `dispatched_at` now,
and `ExecuteStudioActionJob` is pushed once that transaction commits. An action
already sent `max_attempts` times — not counting the sends its Persona's
posting limit sent back (`deferrals`) — is failed instead,
`attempts_exhausted`. Two dispatchers never send one action twice. A push Redis refuses after the commit
leaves the action `dispatched`, so it is found lost and sent again: nothing is
left that could never be sent again. Every kind approval writes runs, so every
kind is sent, sent again, counted and failed alike.

### Waking the dispatcher

The minute is the schedule and the recovery, never a delay between one
generation of a plan and the next. The moment something may have become due,
the dispatcher is woken (`StudioDispatchWake`), once the write that made it so
has committed — never inside its transaction, and never for one rolled back:

- an approval, so a post due at once — published now, or on a past date — is
  sent straight away;
- a run done with actions of its own already due waiting for it — a past-dated
  post's comments and votes, a comment's replies and votes — so they are sent
  at once, and a whole historical plan runs post, comments, replies and votes
  without a minute between them; an action still to come waits for its own
  moment and the minute's run;
- an administrator's Retry or Resume in the Action queue;
- the executor switched on, so whatever became due while it was off runs now.

Waking sends one `DispatchDueStudioActionsJob`, which runs the very pass the
minute runs — the same selection, locks, attempts, recovery and switch, no
second queue and no second executor. It is unique until a worker takes it: one
waits on the queue at most, however often it is asked for, and one asked for
while it runs waits behind it, so nothing that became due during a run is left
for the minute. A run that sent a whole `batch_size` may have left more due, and
asks for the next one; a run that sent less found everything due there was, so
a plan larger than a batch is carried through to its end, a batch a run, and the
chain stops. Nothing is waked while the executor is off, a wake that fails is
only logged (`studio.dispatch.wake_failed`), and the minute's run, beside it or
after it, sends whatever is due: two runs at once never send one action twice.

### The job and the run

`ExecuteStudioActionJob` carries the action's id and nothing else; `tries` 1,
`timeout` 75 s under Redis's 90 s `retry_after`, `failOnTimeout`. It calls
`ExecuteStudioAction` and holds no rule of its own; `failed()` only logs.

The run has two transaction boundaries:

1. **The claim**, committed on its own: the action locked, still `dispatched`,
   of a kind that runs and due, becomes `running` with `claimed_at`, and its
   plan, if still Approved, Running. `attempts` is not touched. Anything else —
   a redelivered job, an action done, cancelled or sent again — claims nothing
   and does nothing.
2. **The run**, one transaction: the action locked again and found still
   `running` at the claimed attempt; the Persona's and the approving
   administrator's `users` rows, in ascending id order (`LocksUsersInOrder`);
   the plan; then, inside the site's own actions, the photo and the post. The
   locks are always taken in that order — the action, the users, the plan, the
   photo, the post — so a sanction, a moderation or a vote beside it takes turns
   with it rather than deadlocking. On the locked rows, the Persona must still
   be an Active Persona and the plan still the approved one. The post is written
   through `CreatePostAction` — the plan's photo by its id, as stored, checked
   and locked there; the plan's texts, category, tags and answers; `occurs_at` as
   its `created_at` and `updated_at` — then approved through
   `ApprovePostAction` as the administrator who approved the plan, published on
   `occurs_at`, and scored through `RecalculatePostScoreAction` under the post's
   lock. Both actions ask their own policies again: an administrator who may no
   longer approve cannot publish through an approval given earlier. The action
   becomes `done` with its post and the real `finished_at`, and the plan
   Completed once nothing of it is left. All of it commits together, or none of
   it does.

   A comment's or a reply's run takes its locks in the same order: the action;
   its parent asked again, done, and what that wrote read — the post, or the
   comment answered and its post; the commenting Persona's and the post's
   author's `users` rows in ascending id order — the post's author is who
   `PostCommentedNotification` goes to, and the notification locks that row
   again, so no user is first locked after the post; the plan, still Running;
   the post, still able to receive comments (`canReceiveComments()`); and, for
   a reply, the comment it answers — still there, visible, a comment on this
   post and no reply itself. The post's author may be the commenting Persona
   itself, answering under its own post: its row is then locked once. On
   PostgreSQL the post's author's row is locked for an update of no key (`FOR
   NO KEY UPDATE`), whoever comments: a member's comment or vote on the same
   post updates the post twice in its own transaction, and PostgreSQL checks
   that second update's foreign keys with `FOR KEY SHARE` on the author's row
   while holding the post, which `FOR UPDATE` would block, each side then
   waiting for the other; a sanction, an anonymization or a notification of
   the author still takes turns with the run, and `AddCommentAction`'s own lock
   of its commenter and the notification's only strengthen the lock the run
   holds. The Persona must still be a
   Persona able to comment (`canComment()`). The comment is written through
   `AddCommentAction` with the action's `occurs_at` as its `created_at` and
   `updated_at` — every lock it takes already held — under
   `Post::withoutTimestamps()` and `Comment::withoutTimestamps()`, so the
   post's comment count and score it updates leave the post's dates as they
   are. The action becomes `done` with its comment, and the plan Completed once
   nothing of it is left.

What stops a run is recorded only after the run is rolled back, by a short
transaction that writes to the attempt it claimed and to nothing else — and,
cancelling it for good, to the rest of its branch (see "Dependencies"). A
lost connection or a deadlock is no verdict: the action stays `running`, and
recovery sends it again.

| A comment's Persona now | The comment's action |
| --- | --- |
| an Active Persona able to comment | runs |
| Limited or Shadowbanned | held for an administrator; its replies wait |
| Banned | cancelled, `author_banned`, with its replies |
| deleted | cancelled, `author_deleted`, with its replies |
| no longer a Persona | cancelled, `author_not_persona`, with its replies |

Its replies-engagement setting plays no part: it governs a Persona's own
replies to members, a later stage. A sanction of the post's author cancels
nobody else's comment while the post stays published.

### Votes

A vote's run is the comment's, with what the site's toggling vote actions need
besides (`docs/architecture/content-studio-phase-0.md`, D4): `VotePostAction`
and `VoteCommentAction` take a vote back when it is asked for again, and
`VoteRatingOptionAction` puts another option in place of one chosen, so none of
them is ever called on a vote already there.

1. **What it is cast on** is read before any user is locked: its parent asked
   again, done, of the plan, its result the kind it must be — the post a
   `create_post` wrote, or the comment a `create_comment` or `create_reply`
   wrote, the one whose `discussion_node_id` the vote names, on the post the
   plan's own `create_post` wrote. A result of another kind, another plan's, or
   a comment of another post is never voted on: the action fails,
   `unexpected_failure`.
2. **The locks**, in the project's order: the action; the voting Persona's
   `users` row — and, for a vote on the post, the post's author's too, `FOR NO
   KEY UPDATE` on PostgreSQL, as a comment's run takes it, since the vote's
   second update of the post (its counters, then its score) checks that key; the
   plan, still Running; the post, still able to receive votes
   (`canReceiveVotes()`); and the comment, still visible and not deleted. No
   `post_votes` or `comment_votes` row is locked before them: every member's
   vote takes the voter's row and the post first, so the post held already
   keeps any other vote on it out.
3. **The vote as it stands**, read only then, all of those held:
   - none there — `VotePostAction`, `VoteCommentAction` or
     `VoteRatingOptionAction` casts it, as the Persona, every lock it takes
     already held, with the action's `occurs_at` as the vote's own date;
   - the same vote there already — up for up, or the option planned — the
     action is done with it, nothing cast and no limit spent, its dates its own;
   - a vote the other way, or another option — never taken back or turned to
     make room for the plan's: the action fails, `vote_conflict`, for an
     administrator to decide.
4. **The commit**: the vote — or the one found — becomes the action's
   `result_type`/`result_id`, and the vote and `done` commit together.

A rating vote's option is asked as the run reads it — still there, active, of
the question the plan approved, the question active — and again by
`VoteRatingOptionAction` on the rows it locks; an option no longer one to vote
for is never replaced by another, the action cancelled,
`rating_option_unavailable`.

| What a vote finds | Its action |
| --- | --- |
| its Persona Limited or Shadowbanned | held, `author_restricted` |
| its Persona Banned, deleted, or no Persona | cancelled, `author_banned`, `author_deleted`, `author_not_persona` |
| the post hidden, deleted or unpublished | cancelled, `post_unavailable` |
| the comment hidden or deleted | cancelled, `comment_unavailable` |
| the option, or its question, no longer active | cancelled, `rating_option_unavailable` |
| its parent not done yet | back to pending, `parent_not_done` |
| its parent cancelled for good | cancelled, `parent_cancelled` |
| the Persona's voting limit reached | back to pending, `rate_limited`, later |
| the Persona's own vote there the other way | failed, `vote_conflict` |
| anything unexpected | failed, `unexpected_failure` |
| a lost connection or a deadlock | stays `running`; recovery sends it again |

A vote cancelled takes nothing with it but its own row; the comments and the
other votes go on. Cancelling a post or a comment for good cancels the votes
on it with the rest of its branch, and nothing done is taken back.

### Applied once

- **A job delivered twice** finds the action done, or not `dispatched`, and
  claims nothing: no second post, notification, score or attempt.
- **Two workers at once** take turns on the action's row; the second finds it
  `running` or done.
- **A worker killed** after its claim, or with its post written but not
  committed, leaves the action `running` and nothing else — PostgreSQL rolls
  its transaction back. Recovery sends it again after
  `running_stale_after_seconds`, and the second attempt publishes it once:
  `attempts` is 2, not 3.
- **A late run**, whose action recovery has sent again since, finds another
  attempt on it and writes nothing. Recovery itself waits for a run that is
  still alive — the run holds the action's row — and then finds it done.
- **Every critical write is checked** — the post's score included: a save a
  model event cancels fails the whole run as an exception would.
- **A vote** is read after its rows are locked and cast only when it is not
  there (see "Votes"): a job delivered again, or a second worker, never takes a
  vote back or casts it twice, and a vote already there is never redated.

`attempts` grows by one on every send — the first, and every one after a loss
— and never on a claim or a finish.

### Dates

`occurs_at` is the post's: its `created_at`, `updated_at` and `published_at` all
take it, however late the run — a post meant for now that runs a minute late
is still that moment's. The approval, the first publication and the score run
under `Post::withoutTimestamps()`, so none of them stamps the post's
`updated_at` with the real time; a later real edit does, as ever. `finished_at`
is the real moment the action ran; the moderation log and the notifications
keep their real times. A future `run_after` is never sent, and a job sent early
by hand is never claimed.

A comment's `occurs_at` is its own `created_at` and `updated_at`, given to
`AddCommentAction` as its trusted date — the executor alone gives one
(`tests/Unit/Actions/StudioTrustedParametersTest.php`); a member's comment is
dated as it is written, and a later edit or moderation moves `updated_at` as
ever. The post's comment count and score it updates run under
`Post::withoutTimestamps()`, so a historical post keeps its `updated_at`
through its whole discussion. Several comments of one moment keep that moment,
whenever and in whatever order they run.

A vote's `occurs_at` is its own `created_at` and `updated_at` — the fourth
argument of `VotePostAction`, `VoteCommentAction` and `VoteRatingOptionAction`,
which only the executor gives (the same test); a member's vote is dated as it is
cast. `PostVote` takes both in its `create()`, `CommentVote` through
`forceFill()` on the new row only — its `$fillable` is not widened — and
`RatingVote` in its `upsert()`, whose update leaves an answer's `created_at` as
it was. The post's counters and score, and the comment's counters, run under
`Post::withoutTimestamps()` and `Comment::withoutTimestamps()`, so a vote never
moves a post's or a comment's `updated_at`. A vote found already cast keeps its
own dates.

### Notifications and side jobs

The Persona's `PostApprovedNotification` is written in the run's transaction
and commits with it. The followers' job (`NotifyFollowersAboutNewPostJob`) and
the photo's variants job are pushed only once the transaction commits —
`ApprovePostAction` now sends the followers' job after the commit for every
approval, at once when there is no transaction — so nobody hears of a post that
was rolled back. The variants job skips the variants the photo already has.

A comment's `PostCommentedNotification` to the post's author is
`AddCommentAction`'s own, written in the run's transaction through
`LifecycleSafeDatabaseNotifier` and committed with the comment: a comment
rolled back tells nobody, and a job delivered again after it is done writes
nothing, so tells nobody twice. It carries the real time it was written.

### The posting, commenting and voting limits

The Persona's posts count against the site's ordinary upload limit
(`rate_limits.upload`, 5 in 600 s), its comments against the commenting limit
(`rate_limits.comment`, 10 in 60 s), and its votes against the voting limit
(`rate_limits.vote`, 60 in 60 s, one counter for every kind) — none ever
bypassed. An action
that meets one is no failure: the run is rolled back and the action goes back
to `pending`, its `run_after` moved to when the limit allows — never earlier
than it was — `occurs_at` unchanged, `failure_code` `rate_limited`, and
`deferrals` one more. The commenting and voting limits say how soon
(`CannotCommentException`, `CannotVoteException`, `CannotVoteCommentException`
and `CannotVoteForRatingOptionException` keep the reason and the seconds,
`isRateLimited()`, `retryAfterSeconds()`); the executor never reads it from the
message, which a member sees as before. Comments or votes of one Persona due at
one moment — a historical plan's, all at once — run one after another as the
limit lets them, each dated as planned, none of them dropped.

That send still counts in `attempts`, which the run's fence compares, but the
dispatcher leaves the deferrals out of `max_attempts`: a backlog the limit
spreads out — a Persona's history approved in one go, published five at a time
— is not a run of lost deliveries, and none of it is given up for waiting its
turn. Deferrals are bounded on their own: an action the limit sends back once
more after `max_deferrals` is failed, `rate_limited`, for an administrator.

### The switch

The project's `content_studio_execution_enabled` flag (`ProjectSettingsManager`,
`StudioExecutionSwitch`) is **off** by default, on staging and production too,
and only an administrator switches it, from the Action queue, after
confirming (`SwitchStudioExecutionAction`) — one who may both manage plans and
the project's settings; the settings page neither shows it nor clears it when
saved, and every other setting and flag is kept as stored. While it is off,
`studio:dispatch-due` sends nothing,
takes nothing back and changes nothing, and a job already sent begins nothing.
It is read afresh every time, never from what a long-lived worker resolved
earlier, so switching it off stops the very next send and the very next claim;
a run a worker already claimed finishes, and nothing written is taken back.
Switched on, the dispatcher is woken at once.

A deploy therefore publishes nothing: the migrations run, the new code goes
live, the worker restarts as it always does, and the actions stay as they are
until an administrator switches the flag on deliberately. A job a worker of an
older release does not know
fails there and leaves its action `dispatched`, and recovery sends it again.

### Configuration

`config/content_studio.php`, section `actions`:

| Setting | Default | Means |
| --- | --- | --- |
| `batch_size` | 50 | actions sent, and sent again, per run |
| `max_attempts` | 3 | sends before an action is failed `attempts_exhausted`, those its posting limit sent back left out |
| `max_deferrals` | 48 | times the Persona's posting, commenting or voting limit may send an action back — eight hours of ten-minute windows — before it is failed `rate_limited` |
| `dispatched_stale_after_seconds` | 900 | a sent action unclaimed this long was lost |
| `running_stale_after_seconds` | 180 | a claimed action unfinished this long was left by a dead worker — past the job's 75 s timeout and Redis's 90 s `retry_after` |

### Logs

`DomainLogger` events `studio.action.sent`, `.resent`, `.dispatch_failed`,
`.skipped`, `.discarded` (a run that found its attempt gone), `.done`,
`.held`, `.cancelled`, `.failed`, `.postponed` and `.job_failed` — with the
action's id, the plan, the Persona, the type, the state, the attempt, the
failure code and the moments — and, for an administrator's operations,
`studio.action.retried`, `.resumed`, `.cancelled` (with the administrator),
`studio_plan.stopped` (how many it cancelled), `studio.execution.switched` and
`studio.dispatch.wake_failed`. Never the post's texts.

### Testing it

The executor is tested with the flag switched on in the test alone
(`switchStudioExecution()`), OpenAI and every other outside service faked, and
the jobs run in the test as a worker would (`runStudioActionJob()`); no real
post is published anywhere. A plan's discussion is run round by round
(`tests/Feature/Actions/ExecuteStudioDiscussionActionTest.php`), and its votes
(`tests/Feature/Actions/ExecuteStudioVoteActionTest.php`). The races run
as processes of their own against PostgreSQL
(`tests/Feature/Concurrency/StudioExecutorRaceTest.php`), a worker killed in the
middle included, and a comment's run beside a ban of its Persona or of the
post's author, a moderator hiding the post or the comment it answers, and a
member's own comment or vote on the same post — none of them deadlocking. So do
a vote's: two workers on one vote of each kind, a worker killed with its vote
cast, and a vote beside a member's own vote on the same post or comment, a
moderator hiding the post or the comment, a ban of its Persona, and an
administrator archiving its option, both orders. Approval races two
administrators on a plan with votes, a save of its votes and a ban of a voter
(`tests/Feature/Concurrency/StudioPlanRaceTest.php`); an administrator's Cancel
races a run of the same action, both orders, Retry races Cancel, and Stop
remaining actions races a comment's run.

A whole plan runs on the queue as tests run it — every job handled the moment
it is pushed — so nothing moves the clock between generations
(`tests/Feature/Actions/StudioPlanRunTest.php`): a historical plan published the
moment it is approved, generation after generation, its one future vote left
for its moment; a plan published now and one in the future; one larger than a
batch; the executor switched on after the approval; and a Persona's voting
limit. The wake on its own (`StudioDispatchWakeTest`) and the Action queue's
operations (`ManageStudioActionsTest`) are tested beside them.

## The Publication step

The editor's last step (`resources/views/filament/pages/studio-plan/publication.blade.php`)
is where a Draft is approved and where an approved plan is followed.

**A Draft.** Its publication mode and date, as on every step. What of the plan is
already overdue — its actions whose moment is past by now — said as a count, and
that each runs as soon as the plan is approved, in plan order and each once what
it depends on is done, within its Persona's limits, keeping its planned date
(decision H1: the post, its comments and votes are dated as planned; when each
really ran is kept in the queue). Nothing else is offered for them: running
them at once is the only rule, so the prototype's Spread from now and Skip
overdue are left out. Then whether the plan is ready to approve — every problem
the preflight finds on the plan as it is saved, each with the step that holds it
— and the final publication card: the photo, the title and description, the
author, the category, the date, how many actions the plan holds, the post's
votes and answers and the discussion's comments, replies and votes, each part
with Edit. Beside them the Action plan (`StudioPlanActionPlan`,
`StudioActionPlan`): the preview of exactly what approval writes now
(`StudioPlanSchedule`), from the saved plan — when each happens, its part (the
post's or the discussion's), what it does and after what (C1, C1's replies), its
Persona, when it runs (on approval, or at its own moment) and how it stands
(Ready to run, Scheduled, Overdue) — over a timeline of the two parts, narrowed
to a part and read 25 a page on the server. Nothing of it is written or sent; it
is drawn afresh when another editor on the page saves, and Recalculate draws it
again.

**Review and confirm**, in the footer, approves the plan — only as it is saved:
with anything unsaved on the page (the draft's own values, the post's votes or
the comment votes) it is held back and says so; at a version somebody else saved
since, it is refused with Reload; with anything the preflight finds, it is
disabled and says so. It asks first, with what approval writes: the post's
date, the comments and replies, the votes and answers, how many actions, how
many are overdue and how many wait for later, and whether the executor is on.
Confirmed, it calls `ApproveStudioPlanAction` with the editor's version — a
second press finds the plan approved and writes nothing — and opens the plan
again, closed.

**An approved plan** opens on this step, closed: its mode and card as approved,
and how the publication stands — who approved it and when, its post (published,
dated, when it ran, a link to it), whether the executor runs — over the plan's
own actions as they stand, each with when it ran or why it stopped and a link to
what it wrote, Open in Action queue, and Stop remaining actions. The Action plan
alone reads itself again every five seconds while the executor is on and any
action waits, is sent or runs, and stops once nothing is left to move; a plan
that moves on meanwhile — Running, Completed — tells the editor, whose header
says so.

## The Action queue

Content Studio's Action queue (`StudioActionQueue`, `/admin/studio-actions`) is
the business queue itself, `studio_plan_actions` — never Laravel's `jobs` or
`failed_jobs`. Every action of every approved plan: its publication (a link to
the plan), what it does, its Persona, when it is planned (`occurs_at`), when it
may run (`run_after`), when it finished (`finished_at`), its state by the
queue's own names — Pending, Dispatched, Running, Done, Held, Failed,
Cancelled — and why it stopped, with its details opened in place: its attempts
and deferrals, the action it depends on, and the post or comment it wrote.
Pending, Running, Held and Errors are counted in the header; State, Kind and
Publication narrow it and a search finds a publication's title, an action's id
or a Persona, on the server, 25 a page (`StudioActionsQuery`). It reads itself
again every five seconds while the executor is on and anything waits or runs.

An administrator's operations, each asked again on the locked rows, the
administrator read afresh — never locked first, as a run locks its action
before the approving administrator's row:

- **Retry** a failed action (`RetryStudioActionAction`): pending again, its
  attempts and deferrals counted afresh, its dates as they were; every check
  of the run asks again, so a vote still in conflict fails again rather than
  taking anything back. Never an action done or cancelled, nor one whose parent
  is not done.
- **Resume** a held action (`ResumeStudioActionAction`): pending again, the same
  way; a Persona still restricted holds it again.
- **Cancel** an action not yet run — pending, held, failed, or sent and not yet
  claimed — asked first (`CancelStudioActionAction`): cancelled for good,
  `cancelled_by_administrator`, its branch with it (`parent_cancelled`), the
  plan settled. Never an action done, so nothing published is taken back, nor
  one a worker runs; a job sent before claims nothing.
- **Stop remaining actions** of an approved or running plan, from its
  Publication step and asked first (`StopStudioPlanAction`): every action of it
  not yet run cancelled, `cancelled_by_administrator`, in ascending id order,
  then the plan settled — Completed when its post was published, Cancelled when
  it never will be. A published post, and the comments and votes already
  written, stay; a run already claimed finishes, and nothing follows it. Nothing
  goes back to a draft.
- **Switch the executor** on or off, asked first (`SwitchStudioExecutionAction`,
  see "The switch").

Retry and Resume wake the dispatcher, so the action runs now rather than at the
next minute. Each refusal — the action moved on meanwhile, gone, or its parent
not done, the administrator no longer allowed — writes nothing and says why.

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
also through a failed attempt — stored, though the editor shows none of it once
the re-run is asked for —; an attempt for another photo clears it. Only the
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

## Post text suggestions

An administrator can have OpenAI write title and description options for a
Draft, from its photo's stored analysis and in the voice of its author: ten
titles and ten descriptions a batch, in one of six tones (`StudioPostTextTone`:
Playful, Humour, Cheeky — the default — Spicy / Adult humour, Irony, Neutral).
They are options, never the post's texts:
choosing a title or a description puts it in the editor's final field,
unsaved, and the plan's `title` and `description` change only with Save draft.
Nothing is written by itself — not on opening the editor, not on Save draft,
not when a photo or its analysis arrives — and nothing written ever replaces
a final text. Every batch is a separate, paid call, asked for by Generate
suggestions, Generate 10 more or Try again.

A batch is written from the analysis, never the photo, which is not sent
again, and in the voice of the post's author (`StudioPostTextAuthor`): the
Persona the plan is saved with, told to the model as the discussion's Personas
are — display name, short description, character, speech style, topics and
jokes, what it never writes about, and how often it uses emoji; never an email,
username, id, password, sign-in or security setting, and not its engagement or
daily reply limit. A post with no author, or one whose author is no longer a
Persona or is deleted, is written as the site's own. It can be asked for only while the plan
is a Draft whose photo has a completed analysis — the row about that very photo,
its stored result passing `StudioImageDescription::from()` again — and text
suggestions are configured; until then the editor says “Text suggestions will
be available when photo analysis is complete.”

### What is kept

One row per batch in `studio_plan_text_generations` (`StudioPlanTextGeneration`),
every batch of a plan kept, deleted with the plan:

| Column | Contract |
| --- | --- |
| `studio_plan_id` | the plan; cascades on delete |
| `source_image_asset_id` | the photo the batch was written for; a plain id, no foreign key |
| `source_analysis_hash` | what it was written from, in one SHA-256 (`StudioPostTextContext::sourceHash()`): the analysis's own hash (`StudioImageDescription::hash()`) for a post with no author's voice — as every batch written before authors were told has — and otherwise one hash of the analysis's and everything the model was told of the author |
| `tone` | `StudioPostTextTone` |
| `status` | `StudioPostTextGenerationStatus`: `queued`, `running`, `completed`, `failed` |
| `attempt_id` | the batch's UUID; unique |
| `error_code` | `StudioPostTextGenerationError` of a failed batch |
| `titles`, `descriptions` | the validated texts of a completed batch, as JSON lists |
| `model`, `request_id`, `input_tokens`, `output_tokens`, `total_tokens`, `latency_ms` | the call that wrote it |
| `requested_at`, `started_at`, `finished_at` | its timings |

A batch belongs to the photo, the analysis and the author's voice it names:
the editor lets an administrator use only the completed batches whose image id
and hash match the plan's photo, that photo's analysis and its author's voice
now, and shows the newest of them — a
new batch takes the place of the one before in view, while every earlier one
stays stored. While a batch is on its way, or after it fails, none is shown:
the lists wait for it, or say why there is none. A replaced photo, or an
analysis re-run with a different result, another author saved, or an author
whose profile now says otherwise of how it writes, leaves the earlier batches
stored but no longer shown or usable; a re-run that gives the very same
description keeps them, and so does a change of what the model is not told —
the author's bio, avatar, engagement or daily reply limit. A batch written
before authors were told counts as one with no author's voice: on a post with
an author it is shown no more, and the next Generate writes in the author's
voice. `source_image_asset_id` holds nothing, like the analysis row's: the media
lifecycle releases and purges a replaced photo as before. Only the validated
texts and the call's metadata are stored — never the prompt, the analysis sent,
the API key or the provider's raw answer.

### The response contract

One request to the Responses API per batch: `store` false, no conversation,
tool or web search, the answer required as strict Structured Output named
`studio_post_texts` against `StudioPostTextSuggestions::schema()`:

```json
{
  "titles": ["string"],
  "descriptions": ["string"]
}
```

The instructions are fixed text — the rules, how the author's voice is used,
what makes a good option, then the tone's own instruction from
`StudioPostTextTone`, then how many of each and how long. The analysis travels
in the input as JSON inside a `<photo_analysis>` block and the author inside a
`<post_author>` block, `<` and `>` escaped so nothing in either can close its
block, and both are declared data, never instructions: text quoted from the
photo, or typed into a Persona's profile, cannot change the rules, the tone,
the count or the format. The rules keep to what the analysis says is visible,
English, no hashtags, numbering or emoji in titles; never explicit, never
sexualising anyone who is or may be a minor or whose age is unclear, and never
humiliating a person shown. Age is never judged from looks, and no sexualised
remark is made about a specific person shown: it would need separate, reliable
confirmation that the person is an adult, and nothing a request carries is one
— not the analysis, which never judges age, not the author or text quoted
from the photo, and not the tone, which is a mood, never a fact about anyone's
age. The innuendo and double meanings play on the situation, the setting and
wordplay instead.

With an author, the texts are written as the author writes — its vocabulary,
rhythm, punctuation and kind of humour: a sarcastic author ironic, a flirt
with light double meanings, an aesthete noticing light, composition and mood, a
meme lover reaching for absurd comparisons, a blunt one short and bold — never
about what it never writes about, with emoji in descriptions only as often as
it uses them, and never naming it or quoting its profile. The tone comes first:
where the author's habits clash with it, the texts keep the tone and only the
habits that fit. Stock praise (“Absolutely stunning”, “What a beautiful
picture”, “Wow, just wow”, “Amazing content”), a compliment that would fit any
photo, a description that only describes, and one joke reworded ten times are
all ruled out.

An answer is kept only when it passes `StudioPostTextSuggestions::from()` whole:
exactly those two fields, each a list of exactly `batch_size` strings, valid
UTF-8 with no control characters; titles 3 to 120 characters on one line,
descriptions 1 to 2,000 (a line feed allowed), the post's own bounds
(`PostContentRules`); and no two in a list the same or nearly so — the same
words in any order, case or punctuation, or nine in ten words shared. Nothing
is trimmed into shape, dropped or padded: an answer that fails one check
stores nothing.

### Requests, the job and the result

| Class | Does |
| --- | --- |
| `RequestStudioPostTextGenerationAction` | queues a batch for the photo and the author the editor showed, in the chosen tone |
| `GenerateStudioPostTextsJob` | the one request to OpenAI, from the queue |
| `StartStudioPostTextGenerationAction` | the job's claim: `queued` → `running`, for its attempt id only |
| `CompleteStudioPostTextGenerationAction` | keeps the texts while the photo, the analysis and the author's voice are still the batch's |
| `FailStudioPostTextGenerationAction` | ends an unfinished batch with its reason |
| `OpenAiStudioPostTextGenerator` (`StudioPostTextGenerator`) | the Responses API call and the reading of its answer |
| `StudioPostTextContext` | the plan's photo, its analysis, the analysis's hash and the author's voice, and what a batch is written from in one hash (`sourceHash()`), as a batch is checked against them |
| `StudioPostTextAuthor` | the voice of the post's author, as the model is told it and as a batch keeps a hash of it |

A request asks `manage-studio-plans`, checks the configuration, then locks the
administrator's row, the plan (`LocksStudioPlanDraft`), the analysis row and
the plan's batches, in that order, and checks on them that the plan is still a
Draft, its photo and its author are still the ones the editor showed and its
analysis is complete and valid. It writes nothing of the plan — not a field, not
`lock_version` — so a batch never makes a save of the texts stale. The job is
dispatched after the commit and carries only the batch id and the attempt id.

No paid call is made twice by accident: one batch per plan is on its way at a
time. While one is queued or running, asking again — a double click, another
tab, another administrator — starts nothing and is given that batch. A batch
still queued or running `stale_after_seconds` (180) after it was requested or
started is taken as lost and failed (`unexpected_failure`), and another may
then be asked for; its job, should it still arrive, finds nothing to claim.

The job claims its batch before anything else, so a redelivered job sends
nothing. It then checks the plan as it is now — still a Draft, still that
photo, an analysis and an author's voice still with that hash, still
configured — and sends the request with no transaction open. An author changed,
or one whose profile changed, before the job ran ends the batch
(`stale_analysis`) without a paid call. Its timings nest inside the queue's: request
45 s, job 75 s (`tries = 1`, `failOnTimeout`), Redis `retry_after` 90 s. It is
never retried: whether to try again is the administrator's choice.

`CompleteStudioPostTextGenerationAction` locks the plan, then the analysis row,
then the batch, and keeps the texts only when the plan is still there and a
Draft, its photo and its source hash — analysis and author's voice — are still
the batch's, and the batch is still running. Otherwise the batch ends
(`stale_analysis`, `plan_closed`) and its texts are dropped, so texts written
for one author never quietly land on a post that has another. The plan itself
is never written.

Generate is asked of the author the plan is saved with. Clicked while another
author is chosen in the editor and not saved, it has the editor save the draft
first (`save-studio-plan`, `then` `suggest`) and asks once the editor says it
is saved (`StudioPlanTextSuggestionsCard::GENERATE_EVENT`) — on a new
publication's page, in the editor the save opens (`CreateStudioPlan::RESUME`,
`suggest`). An author saved by somebody else meanwhile refuses the request
(`author_changed`) and nothing is paid for. A save of the plan anywhere on the
page draws the card again.

### Errors

`StudioPostTextGenerationError`, each with the sentence the editor shows:
`not_configured`, `analysis_missing`, `stale_analysis` (the photo, its
analysis or the author's voice changed), `author_changed` (the author is not
the one the editor showed; a request refused, never a batch), `plan_closed`,
`authentication_failed` (HTTP 401, 403), `rate_limited` (429),
`provider_timeout` (408, 504, a timed-out connection), `provider_unavailable`
(5xx, no connection), `provider_rejected` (another 4xx), `provider_refused` (a
refusal), `invalid_provider_response` (a response that is not `completed`, has
no single `output_text`, or is not a valid batch) and `unexpected_failure` (the
job itself failed or timed out, the queue could not take it, or the batch was
lost). A failure changes nothing of the plan, keeps every earlier batch stored,
and leaves the final texts as they are.

### Configuration

The same Content Studio integration as photo analysis — `enabled`, `api_key`,
`model` and `base_url` of `content_studio.image_analysis`, so the same four
variables in `shared/.env` switch both on — with the settings particular to
texts in `content_studio.post_text_generation`, config only:

| Setting | Default |
| --- | --- |
| `batch_size` | 10 |
| `connect_timeout`, `timeout` | 5 s, 45 s |
| `max_output_tokens` | 4000 |
| `stale_after_seconds` | 180 |

While analysis is off, or the key is blank, nothing is sent and the editor
says “Text suggestions are not configured.” — the Post step works as before.
No key is in the repository, and tests send nothing to OpenAI.

### Logs

`DomainLogger` events `studio.post_text_generation.requested`, `.started`,
`.completed`, `.failed` and `.discarded` (a job that claimed nothing, or texts
dropped because the plan changed), with the plan id, the attempt id, the tone,
the status, the error code, and the call's model, latency, HTTP status, tokens
and `provider_request_id`. Never the key, the analysis, the prompt, the texts
or the provider's answer.

## The editor

Administrators work with plans in Content Studio › Publications & plans (`StudioPlanResource`): a list read
through `StudioPlansQuery`, a new publication and a publication's editor in four steps — Image, Post, Discussion
and Publication — described in `docs/design/admin/design-contract.md`. The pages hold no rule of their own:

- A new publication exists once Create draft runs `CreateStudioPlanAction`, which an untouched editor may do too —
  or once its first photo arrives, which makes one Draft, empty but for its author, to keep the photo on. The page
  starts with an active Persona drawn at random as the author (`startAuthorId`), the page's starting value rather
  than a change, which Discard comes back to; Pick at random in the author picker draws another. The page
  remembers the plan it has (`planId`): later photos replace that plan's photo, and Save draft writes the texts to
  it through `UpdateStudioPlanAction` rather than making another, then opens the plan's editor on the step the page
  was on.
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
- AI text suggestions are a component of its own on the Post step (`StudioPlanTextSuggestionsCard`), about the
  saved photo the editor shows: Generate suggestions, Generate 10 more and Try again run
  `RequestStudioPostTextGenerationAction` in the tone chosen, apart from Save draft. It shows the newest batch
  alone; a new one takes its place, the earlier ones staying stored. It alone is read again every few seconds
  while a batch is queued or running, or the photo's analysis is on its way. Choosing a title or a description
  checks on the server that the batch is still the photo's and its analysis's and hands the text
  to the editor (`use-studio-suggestion`), which sets its final field as typing would: unsaved, counted among the
  unsaved changes, editable, and saved only by Save draft.
- The Publication step's Action plan is a component of its own (`StudioPlanActionPlan`), drawing a preview of what
  approval writes (`StudioPlanSchedule`) for a Draft and the plan's own actions once approved; Review and confirm
  runs `ApproveStudioPlanAction` with the version the page showed, only with nothing unsaved on the page, and Stop
  remaining actions runs `StopStudioPlanAction` (see "The Publication step").
- The Action queue is a page of its own (`StudioActionQueue`): Retry, Resume, Cancel and the executor's switch run
  `RetryStudioActionAction`, `ResumeStudioActionAction`, `CancelStudioActionAction` and
  `SwitchStudioExecutionAction` (see "The Action queue").
