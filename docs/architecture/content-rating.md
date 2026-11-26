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
- a video or GIF when its processing finishes (`ProcessVideoAssetJob`).

**What they see** (`ClassificationFrameSampler`): local JPEGs no larger than
1280 on either side, the format and size every provider takes.
- **A photo:** its master, scaled down.
- **A video or GIF:**
  - its poster frame, taken a tenth of the way in (at most 3 s), as the poster
    is: what a feed shows before it plays;
  - plus a frame from the middle of every two-second stretch, at most 30.

**The job:**
- It runs on the media queue: the frames are ffmpeg runs, and a cloud
  classifier may take a while over thirty of them.
- Its frames go into a directory of its own under the system temp directory,
  readable by the worker alone and removed when it ends.
- A classifier that already answered for the asset at its model version is
  not asked again, so a retry asks only the rest.
- What a classifier fails at is recorded, not retried. What fails the job is
  the host (the file, ffmpeg), retried once.

**Media stored before a classifier was configured:** `php artisan
media:classify --limit=N` queues it, newest first, for every configured
classifier that has not answered for it yet. Use it, for example, to try a
classifier in shadow mode on real uploads.

## Where it shows

- **The admin's post viewer** (Posts, the image opened large): the rating, as
  a badge, and each classifier's latest answer on one line. A shadow
  classifier is marked as such; the line gives its three strongest categories
  from 0.05 up, or that it failed.
- **Content Studio's photo details:** the rating beside the photo's size and
  type, with no query of its own.
