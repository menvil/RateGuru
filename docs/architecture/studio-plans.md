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
it prepares (see "The discussion" below) and the actions approval writes (see
"Approval" below). The post a plan becomes is its
`create_post` action's result (`result_type`, `result_id`), not a column of the
plan.

## Statuses

`Draft` → `Approved` → `Running` → `Completed`, or `Cancelled`. A plan is made,
edited and deleted only as a Draft; approval moves it on to Approved, after
which no editor's write — a save, a photo, a deletion — touches it. Its first
action claimed, it is Running; once none of its actions is left to run or to
decide, it is Completed when its post was published and Cancelled when it never
will be (see "Running a plan" below). A held or failed action keeps it Running,
waiting for an administrator.

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
| `DeleteStudioPlanDraftAction` | deletes the Draft's row outright, its discussion with it |
| `SaveStudioDiscussionTreeAction` | saves the Draft's whole discussion (see "The discussion") |
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
complete and current, and nothing about it changes any more. Nothing on screen
approves a plan yet; `ApproveStudioPlanAction` is called by its tests alone.

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
| discussion | none, for now: nothing publishes a discussion yet (see "The discussion") | `discussion_not_publishable` |

### Approving

`ApproveStudioPlanAction::handle($admin, $plan, $expectedLockVersion)` asks
`manage-studio-plans`, reads the author the plan names, and in one transaction
locks the administrator's and the author's `users` rows in ascending id order —
the order a sanction of that Persona takes — then the plan. A plan found naming
another author once it is locked was changed meanwhile and is refused as stale:
no user is ever locked after the plan. On the locked rows it asks again that the
administrator may still manage plans, the plan is still there, still a Draft and
still at the version the administrator saw, and that the preflight finds
nothing. Then, in that same transaction, the plan becomes `Approved` with
`approved_by_user_id`, `approved_at` and a new version, and one action is
written. Either both are written or neither is.

A refusal writes nothing and throws `CannotApproveStudioPlanException` with one
`StudioPlanApprovalRejection` — `access_denied`, `plan_gone`, `not_draft`,
`stale_version`, or, from the preflight, `author_unavailable`, `incomplete_plan`,
`invalid_media`, `invalid_taxonomy`, `invalid_publication_date` or
`unsupported_discussion` — and the
preflight's problems. An approved plan is no Draft, so a second approval, by
anyone, is `not_draft` and writes no second action. Two administrators
approving one Draft at once take turns on the Persona's row and then the
plan's; an approval racing a save, a new photo or a deletion of the Draft takes
turns on the plan's row, the later one refused; an approval racing a sanction
of the author takes turns on the Persona's row — whichever is first, the
approval never approves a Persona the sanction has already reached. A category
deactivated, an answer changed or a photo replaced before the approval is
found by it.

### The action it writes

`studio_plan_actions` (`StudioAction`) is Content Studio's business queue: one
row per scheduled domain write of an approved plan, kept in the main database
(`docs/architecture/content-studio-phase-0.md`, E2–E6). Approval writes the
plan's root action and nothing else: `create_post`, its Persona as the actor,
no parent, an empty payload — the approved plan, which nothing writes any more,
is what it publishes — `pending`, untried (`attempts` 0). The other kinds
(`create_comment`, `create_reply`, `vote_post`, `vote_comment`, `vote_rating`)
are the queue's format; nothing writes them yet. The other states are the
executor's (see "Running a plan" below). The row is deleted with its plan, and
only a Draft, which has none, is deleted.

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

### When it runs

A `pending` row is a passive record until the executor is switched on: no queue,
worker or command acts on it, nothing is sent to Redis, no post is made, and
nothing notifies anybody. Once it is on, `studio:dispatch-due` sends it when its
`run_after` comes (see "Running a plan" below).

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
own. For now it is only prepared and kept — saving it makes no comment, post,
action or job, calls no paid service and changes nothing on screen.

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
are allowed. On a plan with a date, no comment may fall past the latest date a
post may carry (`StudioPlanValidationRules::LATEST_PUBLISH_AT`). The offsets
become each comment's own `occurs_at` and `run_after` only when the plan is
approved, by the step that publishes discussions.

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
comment, post or action is deleted.

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
`validateAgainst()` passes. A save drawn from a version somebody else replaced
is refused, `isStale()`, rather than written over theirs; a plan past its Draft
is `isNotDraft()`. The comments on the post are written first, then the
replies, then the deletions; a write a model event cancels fails the whole save
as an exception would. The save raises the plan's version, like any other
write to it.

A save and a sanction or a deletion of a Persona it names lock that Persona's
row in the same order, so they take turns rather than deadlocking: saved first,
the comment stays and the deletion follows; deleted first, the save is refused.

### Not approved yet

The executor publishes a plan's post alone, and approval writes only its
`create_post`. Approving a plan with a prepared discussion would publish the
post and silently drop the discussion, so the preflight refuses it —
`discussion_not_publishable`, adding up to `unsupported_discussion` — until the
step that publishes comments replaces this with the discussion's own checks and
writes an action for every comment. A plan whose discussion is emptied is
approved as any other.

### Logs

`DomainLogger` events, written only once the outermost transaction has
committed: `studio.discussion.saved` (the plan, the administrator, the number
of comments on the post and of replies, the new version) and
`studio.discussion.deleted_branch` (the comment deleted and how many replies
went with it); and `studio.discussion.rejected` (the plan, the administrator, a
code — `access_denied`, `plan_gone`, `not_draft`, `stale_version` or
`invalid_discussion` — and the fields that failed). Never a comment's text nor
anything of a Persona.

### Still to come

Later steps of the discussion stage build on these rows, unchanged: writing a
discussion with AI, and writing one part of it again — a comment, a reply, a
branch — keeping the history of what was asked and suggested apart from what
an administrator saved; the Discussion step of the editor; and publishing it —
an action for every comment and reply, under the post's, with the full
preflight of the discussion in place of the refusal above.

## Running a plan

The executor publishes an approved plan's `create_post` as a real post of its
Persona, and applies it once however often its job is delivered. Comments,
replies and votes are not run yet: their kinds wait, Pending, for the stages
that run them.

### Life of an action

`pending` → `dispatched` → `running` → `done`, and besides:

| State | Means | Moved there by |
| --- | --- | --- |
| `pending` | waits for its `run_after` and a parent done | approval; the executor, for a Persona at its posting limit |
| `dispatched` | its job is on the queue; `attempts` counts this send | `studio:dispatch-due`, sending it or sending it again |
| `running` | claimed by a worker (`claimed_at`); its post not yet committed | the executor's claim |
| `done` | its post written; `result_type`/`result_id` and the real `finished_at` kept | the executor's run |
| `held` | stopped for an administrator to resume: the Persona Limited or Shadowbanned | the executor |
| `cancelled` | for good: the Persona banned, deleted or no Persona, or the plan, photo, category or an answer no longer usable | the executor |
| `failed` | for an administrator to decide: the approver may no longer approve, it was sent `max_attempts` times, its posting limit sent it back `max_deferrals` times, or something unexpected | the executor; `studio:dispatch-due` |

Each stop keeps a stable `failure_code` (`StudioActionFailure`) and, for an
unexpected error, only the exception's class in `failure_reason`. Nothing is
deleted: a held, cancelled or failed action stays as the record of what
happened. Nothing resumes a held action by itself; resuming, retrying and
cancelling are an administrator's, on the Action queue screen to come.

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
   with no parent or a parent done — `run_after` first, then id.

Each one is locked and asked again before it is sent, in a short transaction
of its own: it becomes `dispatched`, `attempts` one more, `dispatched_at` now,
and `ExecuteStudioActionJob` is pushed once that transaction commits. An action
already sent `max_attempts` times — not counting the sends its Persona's
posting limit sent back (`deferrals`) — is failed instead,
`attempts_exhausted`. Two dispatchers never send one action twice. A push Redis refuses after the commit
leaves the action `dispatched`, so it is found lost and sent again: nothing is
left that could never be sent again. Kinds nothing runs yet are never sent,
sent again, counted or failed for it, whatever state they are in.

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

What stops a run is recorded only after the run is rolled back, by a short
transaction that writes to the attempt it claimed and to nothing else. A
lost connection or a deadlock is no verdict: the action stays `running`, and
recovery sends it again.

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

### Notifications and side jobs

The Persona's `PostApprovedNotification` is written in the run's transaction
and commits with it. The followers' job (`NotifyFollowersAboutNewPostJob`) and
the photo's variants job are pushed only once the transaction commits —
`ApprovePostAction` now sends the followers' job after the commit for every
approval, at once when there is no transaction — so nobody hears of a post that
was rolled back. The variants job skips the variants the photo already has.

### The posting limit

The Persona's posts count against the site's ordinary upload limit
(`rate_limits.upload`, 5 in 600 s), never bypassed. An action that meets it is
no failure: the run is rolled back and the action goes back to `pending`, its
`run_after` moved to when the limit allows, `occurs_at` unchanged,
`failure_code` `rate_limited`, and `deferrals` one more.

That send still counts in `attempts`, which the run's fence compares, but the
dispatcher leaves the deferrals out of `max_attempts`: a backlog the limit
spreads out — a Persona's history approved in one go, published five at a time
— is not a run of lost deliveries, and none of it is given up for waiting its
turn. Deferrals are bounded on their own: an action the limit sends back once
more after `max_deferrals` is failed, `rate_limited`, for an administrator.

### The switch

The project's `content_studio_execution_enabled` flag (`ProjectSettingsManager`,
`StudioExecutionSwitch`) is **off** by default, on staging and production too,
and nothing on screen switches it: the settings page neither shows it nor
clears it when saved. While it is off, `studio:dispatch-due` sends nothing,
takes nothing back and changes nothing, and a job already sent begins nothing.
It is read afresh every time, never from what a long-lived worker resolved
earlier, so switching it off stops the very next job.

A deploy therefore publishes nothing: the migrations run, the new code goes
live, the worker restarts as it always does, and the actions stay as they are
until the flag is switched on deliberately — later, once the Publication screen
and the later stages exist. A job a worker of an older release does not know
fails there and leaves its action `dispatched`, and recovery sends it again.

### Configuration

`config/content_studio.php`, section `actions`:

| Setting | Default | Means |
| --- | --- | --- |
| `batch_size` | 50 | actions sent, and sent again, per run |
| `max_attempts` | 3 | sends before an action is failed `attempts_exhausted`, those its posting limit sent back left out |
| `max_deferrals` | 48 | times the Persona's posting limit may send an action back — eight hours of ten-minute windows — before it is failed `rate_limited` |
| `dispatched_stale_after_seconds` | 900 | a sent action unclaimed this long was lost |
| `running_stale_after_seconds` | 180 | a claimed action unfinished this long was left by a dead worker — past the job's 75 s timeout and Redis's 90 s `retry_after` |

### Logs

`DomainLogger` events `studio.action.sent`, `.resent`, `.dispatch_failed`,
`.skipped`, `.discarded` (a run that found its attempt gone), `.done`,
`.held`, `.cancelled`, `.failed`, `.postponed` and `.job_failed` — with the
action's id, the plan, the Persona, the type, the state, the attempt, the
failure code and the moments. Never the post's texts.

### Testing it

The executor is tested with the flag switched on in the test alone
(`switchStudioExecution()`), OpenAI and every other outside service faked, and
the jobs run in the test as a worker would (`runStudioActionJob()`); no real
post is published anywhere. The races run as processes of their own against
PostgreSQL (`tests/Feature/Concurrency/StudioExecutorRaceTest.php`), a worker
killed in the middle included.

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
Draft, from its photo's stored analysis: ten titles and ten descriptions a
batch, in one of five tones (`StudioPostTextTone`: Playful, Humour, Cheeky —
the default — Irony, Neutral). They are options, never the post's texts:
choosing a title or a description puts it in the editor's final field,
unsaved, and the plan's `title` and `description` change only with Save draft.
Nothing is written by itself — not on opening the editor, not on Save draft,
not when a photo or its analysis arrives — and nothing written ever replaces
a final text. Every batch is a separate, paid call, asked for by Generate
suggestions, Generate 10 more or Try again.

A batch is written from the analysis alone, never the photo, which is not sent
again. The Persona is not part of it. It can be asked for only while the plan
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
| `source_analysis_hash` | SHA-256 of the analysis it was written from (`StudioImageDescription::hash()`) |
| `tone` | `StudioPostTextTone` |
| `status` | `StudioPostTextGenerationStatus`: `queued`, `running`, `completed`, `failed` |
| `attempt_id` | the batch's UUID; unique |
| `error_code` | `StudioPostTextGenerationError` of a failed batch |
| `titles`, `descriptions` | the validated texts of a completed batch, as JSON lists |
| `model`, `request_id`, `input_tokens`, `output_tokens`, `total_tokens`, `latency_ms` | the call that wrote it |
| `requested_at`, `started_at`, `finished_at` | its timings |

A batch belongs to the photo and the analysis it names: the editor lets an
administrator use only the completed batches whose image id and hash match the
plan's photo and that photo's analysis now, and shows the newest of them — a
new batch takes the place of the one before in view, while every earlier one
stays stored. While a batch is on its way, or after it fails, none is shown:
the lists wait for it, or say why there is none. A replaced photo, or an
analysis re-run with a different result, leaves the earlier batches stored but
no longer shown or usable; a re-run that gives the very same description keeps
them. `source_image_asset_id` holds nothing, like the analysis row's: the media
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

The instructions are fixed text — the rules, then the tone's own instruction
from `StudioPostTextTone`, then how many of each and how long. The analysis
travels in the input as JSON inside a `<photo_analysis>` block, `<` and `>`
escaped so nothing in it can close the block, and is declared data, never
instructions: text quoted from the photo cannot change the rules, the tone,
the count or the format. The rules keep to what the analysis says is visible,
English, no hashtags, emoji or numbering; suggestive humour is allowed for
adults where the photo invites it, never explicit, never about anyone who is
or may be a minor, and never humiliating a person shown.

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
| `RequestStudioPostTextGenerationAction` | queues a batch for the photo the editor showed, in the chosen tone |
| `GenerateStudioPostTextsJob` | the one request to OpenAI, from the queue |
| `StartStudioPostTextGenerationAction` | the job's claim: `queued` → `running`, for its attempt id only |
| `CompleteStudioPostTextGenerationAction` | keeps the texts while the photo and analysis are still the batch's |
| `FailStudioPostTextGenerationAction` | ends an unfinished batch with its reason |
| `OpenAiStudioPostTextGenerator` (`StudioPostTextGenerator`) | the Responses API call and the reading of its answer |
| `StudioPostTextContext` | the plan's photo, its analysis and the analysis's hash, as a batch is checked against them |

A request asks `manage-studio-plans`, checks the configuration, then locks the
administrator's row, the plan (`LocksStudioPlanDraft`), the analysis row and
the plan's batches, in that order, and checks on them that the plan is still a
Draft, its photo is still the one the editor showed and its analysis is
complete and valid. It writes nothing of the plan — not a field, not
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
photo, an analysis still with that hash, still configured — and sends the
request with no transaction open. Its timings nest inside the queue's: request
45 s, job 75 s (`tries = 1`, `failOnTimeout`), Redis `retry_after` 90 s. It is
never retried: whether to try again is the administrator's choice.

`CompleteStudioPostTextGenerationAction` locks the plan, then the analysis row,
then the batch, and keeps the texts only when the plan is still there and a
Draft, its photo and analysis hash are still the batch's, and the batch is still
running. Otherwise the batch ends (`stale_analysis`, `plan_closed`) and its texts
are dropped. The plan itself is never written.

### Errors

`StudioPostTextGenerationError`, each with the sentence the editor shows:
`not_configured`, `analysis_missing`, `stale_analysis`, `plan_closed`,
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
- AI text suggestions are a component of its own on the Post step (`StudioPlanTextSuggestionsCard`), about the
  saved photo the editor shows: Generate suggestions, Generate 10 more and Try again run
  `RequestStudioPostTextGenerationAction` in the tone chosen, apart from Save draft. It shows the newest batch
  alone; a new one takes its place, the earlier ones staying stored. It alone is read again every few seconds
  while a batch is queued or running, or the photo's analysis is on its way. Choosing a title or a description
  checks on the server that the batch is still the photo's and its analysis's and hands the text
  to the editor (`use-studio-suggestion`), which sets its final field as typing would: unsaved, counted among the
  unsaved changes, editable, and saved only by Save draft.
