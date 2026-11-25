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
| `content_rating` | `media_assets`, `posts` | `unknown` (default), `sfw`, `nsfw` (`App\Enums\ContentRating`). The post's carries its media's, indexed for the SFW mode to filter on |
| `content_scores` | `media_assets` | the score per category the deciding classifiers gave |
| `content_rating_source` | `media_assets` | `auto` (classifiers) or `moderator`, whose word is final |
| `content_rated_at` | `media_assets` | when it was decided |

A post is published whether or not its media is rated yet: a classifier
failing never holds a post back, and an unknown rating is a signal to the
monitoring, not to the author.

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
