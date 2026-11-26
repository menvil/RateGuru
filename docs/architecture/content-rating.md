# Content rating

Whether a picture or a video is safe for work, so that a viewer's SFW mode
can later blur or leave out what is not. This document covers where the
rating is kept and how classifiers' answers are recorded. Which classifiers
decide, and how, come with them.

## The line

Decided by the project (2026-11-25):

- **NSFW**: exposed female breasts or nipples, genitals, sexual activity,
  sex toys.
- **SFW**: everything else: lingerie, swimwear, cleavage, suggestive poses,
  implied nudity (covered by a hand or an object), **bare buttocks**,
  **see-through clothing**, a man's bare chest.

A doubtful case is NSFW: in an SFW mode, showing too much is worse than
hiding too much. Prohibited content (porn, minors) is a separate, later
decision, kept apart from this line.

## Where it is kept

| Column | On | What |
|---|---|---|
| `content_rating` | `media_assets`, `posts` | `unknown` (default), `sfw`, `nsfw` (`App\Enums\ContentRating`). The post's carries its media's, for the SFW mode to filter on |
| `content_scores` | `media_assets` | the score per category the deciding classifiers gave |
| `content_rating_source` | `media_assets` | `auto` (classifiers) or `moderator`, whose word is final |
| `content_rated_at` | `media_assets` | when it was decided |

A post is published whether or not its media is rated yet: a classifier
failing never holds a post back, and an unknown rating is a signal to the
monitoring, not to the author.

The post's rating is its media's, kept in step:
- whatever sets an asset's rating sets it on the posts showing that asset, in
  the same transaction;
- a post made later takes its asset's rating when it is made;
- a post whose asset is gone keeps the rating it had.

The index the SFW mode's filter needs comes with that filter: built
concurrently, and shaped by its query.

## Every classifier's answer

`media_classifications` keeps one row per classifier run on an asset
(`App\Models\MediaClassification`):

- **Which:** `classifier`, `model_version`.
- **Why:** `mode`:
  - `shadow`: recorded, deciding nothing; a classifier on trial against real uploads;
  - `active`: deciding;
  - `benchmark`: run over a labelled set.
- **What it was shown:** `inputs`, a photo's variant or a video's frame times.
- **What it said:**
  - `scores`: per `App\Enums\ContentCategory`, 0..1;
  - `frame_scores`: per frame, for a video;
  - `raw`: the provider's own answer, kept only while it is small.
- **What it took:** `latency_ms`, `cost_microusd`.
- **How it ended:** `status`; on failure, `error` holds a reason that names no file and holds no key.

Every classifier, cloud API or a model run on the host, implements
`App\Services\Media\Classification\MediaContentClassifier`. It answers in
`ContentCategory`'s terms, so results compare and decide alike whatever the
provider calls things:

- the four NSFW categories (`breast_exposed`, `genitals_exposed`,
  `sexual_activity`, `sex_toy`);
- the near-misses general models confuse with them (`buttocks_exposed`,
  `underwear`, `swimwear`, `see_through`). These are kept for tuning the line
  on this site's own pictures, where almost everything is close to it.

`MediaClassificationRecorder` runs one classifier on one asset and records
its answer or its failure. It never changes the asset's rating.

- **Scores:** only the project's categories are kept, each a finite score in
  0..1. Read a score as a number: JSON gives 1.0 back as 1.
- **The provider's answer:** a classifier saying it has no answer
  (`MediaClassificationFailedException`) is expected. Any other exception is
  a bug in the classifier, and is reported as one.

An asset's classifications go with it when it is purged: what was shown and
said about deleted media leaves no trace. Cost reports come from the
providers' billing, not from this table.

## Showing media to the classifiers

The classifiers in `media.classification.classifiers` see every post's media
once it is ready. Each entry is a container binding of a classifier and its
mode, `shadow` or `active`. There are none by default, and with none nothing
is queued at all.

**When:** `QueueMediaClassification` queues `ClassifyMediaAssetJob` after the
commit that made the media ready:
- a photo as it is stored (`MediaAssetCreator`), from wherever it was
  uploaded: posts, Content Studio, game rounds;
- a video or GIF when its processing finishes (`ProcessVideoAssetJob`),
  before the author is told, so a notice that fails to be written never
  leaves ready media unclassified.

The upload never pays for it. A queue that will not take the job is
reported, not thrown back into an upload that has already committed. The
configuration is read by the job, never by the upload.

**What they see** (`ClassificationFrameSampler`): local JPEGs no larger than
1280 on either side, the format and size every provider takes.
- **A photo:** its master, scaled down.
- **A video or GIF:**
  - its poster frame, taken a tenth of the way in (at most 3 s), as the poster
    is: what a feed shows before it plays;
  - plus a frame from the middle of every two-second stretch, at most 30.
  - Each frame is a short ffmpeg run of its own
    (`media.video.frame_timeout_seconds`, 30 s). A frame ffmpeg cannot take
    is left out.
  - All of them together get five minutes. Past that no further frame is
    asked for, and the frames taken are shown, so seeks stalling one after
    another never use up the job's time before a classifier is asked.

**The job:**
- It runs on the media queue.
  - **Queued:** one at a time per asset (`ShouldBeUniqueUntilProcessing`),
    through a delay it was queued with, then as long as the media connection
    could still deliver it.
  - **Running:** one run per asset at a time, by a lock of its own. A job
    meeting another run waits for it
    (`media.classification.run_wait_seconds`, 120 s), then asks only who is
    still to answer. If that run outlasts the wait, the job leaves the asset
    to it: it never runs alongside, and never queues itself again.
  - Before a run ends it looks again for who is still to answer, and asks
    them from the same frames, up to three passes. A failure `--retry-failed`
    cleared while it ran, for a retry that found the asset held, is asked by
    that run. Once it has let the asset go it looks once more, and queues a
    job of its own for anyone still to answer. A failure cleared after that
    look finds the asset free for the job `--retry-failed` queued with it, so
    no retry falls between.
  - A job that runs after another finds every answer there and asks no one,
    so a queue that kept one waiting past its lock costs nothing.
- A classifier that already answered for the asset at its model version is
  not asked again, so an upload, a redelivered video job and
  `media:classify` meeting on one asset ask each classifier once, and a paid
  one is never paid twice.
- **Where its frames go:** a directory of its own, inside this worker
  user's own (`WorkerTemporaryDirectory`: one per user, so two targets on
  one host never share one). It is removed when the run ends; if a timeout
  killed the run first, a later run removes it.
- **Failures that are recorded, not retried:** a classifier failing, and
  media nothing can be taken from (unreadable, undecodable, no frame). The
  latter is recorded for each classifier that was to see it. A recorded
  failure counts as the classifier's answer, on the job's own retry too.
- **Failures that are retried once:** the host's (ffmpeg missing, the
  database, a read of the media file that failed part way while the file is
  there). Only a file that is gone is recorded as media nothing can be taken
  from.

**A classifier's identity:** its answers are found by its name and model
version as their columns hold them, at most 64 characters each. A classifier
whose name or version is longer is refused when the configuration is read,
so it is never asked at all, rather than asked on every run because its own
answer never matches.

**Media stored before a classifier was configured:** `php artisan
media:classify --limit=N` queues it, newest first, for every configured
classifier that has not answered for it yet. Use it, for example, to try a
classifier in shadow mode on real uploads.
- An asset a classifier failed on counts as answered unless
  `--retry-failed` is given. Then the command clears those failures (the
  configured classifiers' own, at their model version, on the assets it
  queues), so whichever job runs next for each asset asks them: its own,
  one already waiting, or the run holding the asset, before it ends.
- The jobs become available ten seconds apart, so the videos waiting on the
  same media queue are never stuck behind a whole batch.

## Where it shows

- **The admin's post viewer** (Posts, the image opened large): the rating, as
  a badge, and each classifier's latest answer on one line. A shadow
  classifier is marked as such; the line gives its three strongest categories
  from 0.05 up, or that it failed.
- **Content Studio's photo details:** the rating beside the photo's size and
  type, with no query of its own.
