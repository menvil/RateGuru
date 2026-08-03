# Media domain model

## MediaAsset is the master identity

Every uploaded file — a post image or a user avatar — is represented by one
`media_assets` row. There is a single table for both kinds (`app/Enums/MediaKind.php`:
`post_image` or `avatar`), not separate `post_images`/`avatar_images` models. A
`MediaAsset` records `disk`, `path`, `original_filename`, `mime_type`,
`extension`, `byte_size`, `width`, `height`, `aspect_ratio`, `orientation`,
`status`, `visibility`, and an optional `owner_user_id` (the uploader — not a
substitute for the `posts.image_asset_id` / `users.avatar_asset_id`
relationship, which is what actually attaches an asset to something). It is
soft-deletable.

## MediaVariant is a derived file

`media_variants` holds files produced *from* a master asset — a resized feed
crop, a detail size, an avatar thumbnail, an Open Graph crop. Each variant
belongs to one `MediaAsset`, has a `name` unique per asset, and its own
`disk`/`path`/dimensions/mime/byte size. The table and model exist now so
future variant generation (PR-06) has somewhere to land, but **no variants are
generated in this PR** — see "What this PR does not do" below.

## Canonical identity is disk + path, not a URL

Neither `media_assets` nor `media_variants` stores `url`, `public_url`,
`cdn_url`, or any absolute URL. The disk name (a Laravel filesystem disk, e.g.
`public`, later `s3`) plus the path inside that disk is the only identity a
file has. This is what makes it possible to move from local storage to S3/CDN
later without rewriting stored data: existing rows already carry everything
needed to resolve a URL under whatever disk they name. `(disk, path)` is
unique across `media_assets`, matching the fact that it *is* the identity.

Where a URL is genuinely needed right now (Blade views and the public API
built before this PR), `Post::public_image_url` and `User::resolved_avatar_url`
compute one at read time via `Storage::disk($asset->disk)->url($asset->path)`.
These two accessors are explicitly temporary: they read only through the
asset relation, never a legacy column, and never hardcode `/storage/`. A
proper `MediaUrlResolver` (with visibility rules, variant selection, CDN
awareness) is PR-03's job, not this one's.

## Post and User reference assets, not files

`posts.image_asset_id` and `users.avatar_asset_id` are nullable foreign keys
to `media_assets`, each `nullOnDelete()`. This PR intentionally does not use a
polymorphic/many-to-many media library: only one primary post image and one
primary avatar exist right now, so a direct FK is simpler than modeling a
general attachment relationship nothing yet needs.

Deleting a `Post` or `User` does not cascade-delete their asset — the FK just
goes null, leaving the (soft-deleted where applicable) asset row and its
physical file in place. Full lifecycle management — deciding when an
orphaned asset's file should actually be removed — is PR-07's job.

## Filesystem writes are not transactional with the database

Storing a file and writing the database rows that reference it are two
separate operations, and only the second one can be rolled back. Upload
actions (`CreatePostAction`, `UpdateUserProfileAction`) always write the file
*first*, outside any `DB::transaction()`, then perform the `MediaAsset` insert
and the owning row's update inside a transaction. If that transaction fails
for any reason, the action compensates by deleting the just-written file
through `ImageStorage::delete()` — best-effort, and a failure during that
cleanup is reported but never replaces or suppresses the original exception
that triggered it.

Avatar replacement additionally locks the user row (`lockForUpdate()`) before
reading and replacing the current `avatar_asset_id`, so two concurrent
replacements can't both read the same "previous" asset and leave one of them
orphaned. This is still not full lifecycle management — see PR-07 below.

## Open Graph is a variant, not a post column

The legacy `posts.og_image_path` column and the generator/job that populated
it are gone. Architecturally, an Open Graph crop is a `MediaVariant` named
`open_graph` on a post's image asset — but generating one is out of scope
here. `PostOpenGraph::image()` now falls back straight to the post's master
image (`public_image_url`), the same fallback used when there's no image at
all, and from there to the static placeholder asset. There is no visible
functional change for users; the only difference is that a post no longer
carries a second, separately-generated crop. Building the actual `open_graph`
variant pipeline (crop, size limits, queue reliability) is PR-06's job.

## Cloudinary is not a supported driver

The pre-existing `cloudinary` branch of the `ImageStorage` binding resolved to
a class whose only method always threw at call time — a driver that looked
configured but never worked. It has been removed outright. Selecting any
driver other than `local` now fails fast with a clear `InvalidArgumentException`
at resolution time, matching the project's existing rule (`AGENTS.md`) not to
switch to Cloudinary until a real implementation exists.

## What this PR does not do

No EXIF handling, autorotate, recompression, resizing, responsive variant
generation, `srcset`, focal points, AI cropping, Imagick/libvips integration,
CDN wiring, S3 deployment, imgproxy, a working Cloudinary driver, a final
`MediaStorage`/`MediaUrlResolver`, legacy data backfill, or an orphan/cleanup
command. New `MediaAsset` rows are created synchronously as `ready` the
moment a file is stored and its metadata is read from the upload itself —
there is no processing pipeline yet for anything to be `processing` or
`failed` in normal operation (those statuses exist for later use).

This is a destructive schema change with no production data to protect
(staging only); existing staging posts/avatars and their uploaded files are
not migrated forward. Staging must be reset after this lands — see the PR
description for exact commands.

## Roadmap

- **PR-03** — `MediaStorage` and `MediaUrlResolver`: a real storage
  abstraction and the URL resolution logic that today lives as temporary
  accessors on `Post` and `User`.
- **PR-06** — Open Graph variant generation: build the `open_graph`
  `MediaVariant` pipeline this schema already has a home for.
- **PR-07** — Asset lifecycle: orphan detection/cleanup, and actually
  deleting a replaced avatar's physical file (today it's soft-deleted in the
  database but deliberately left on disk).
