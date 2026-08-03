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
future variant generation (PR-05/PR-06) has somewhere to land, but **no
variants are generated yet** — see "What this schema/storage work does not
do" below.

## Canonical identity is disk + path, not a URL

Neither `media_assets` nor `media_variants` stores `url`, `public_url`,
`cdn_url`, or any absolute URL. The disk name (a Laravel filesystem disk, e.g.
`public`, later `s3`) plus the path inside that disk is the only identity a
file has. This is what makes it possible to move from local storage to S3/CDN
later without rewriting stored data: existing rows already carry everything
needed to resolve a URL under whatever disk they name. `(disk, path)` is
unique across `media_assets`, matching the fact that it *is* the identity.

Resolving a URL is `MediaUrlResolver`'s job (see below) — models, Blade, and
API resources never call `Storage::` themselves.

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
through `StoredMediaCleaner` (wrapping `MediaStorage::delete()`) — best-effort,
and a failure during that cleanup is reported but never replaces or
suppresses the original exception that triggered it. The cleanup also skips
deleting when another `MediaAsset` (active or soft-deleted) already
legitimately owns that exact `(disk, path)`, so a rare path collision can't
destroy a file a different, already-committed upload depends on.

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

## MediaStorage and MediaUrlResolver

`app/Services/Media/` splits physical file I/O from URL resolution into two
separate, narrow contracts — never one combined "media manager":

- **`MediaStorage`** — `store()`, `putContents()` (for in-process generated
  content, e.g. demo seeders), `exists()`, `size()`, `readStream()`,
  `delete()`. Never resolves a URL.
- **`MediaUrlResolver`** — `publicUrl()` (throws `MediaIsNotPublicException`
  for private media) and `publicUrlOrNull()` (returns `null` instead). Never
  stores, reads content, creates variants, or touches the database beyond the
  given model's own `disk`/`path`/`visibility`. A `MediaVariant` has no
  visibility of its own — it inherits its parent asset's, since a variant is
  never more permissive than the file it was derived from.

Both are implemented by `Filesystem*` classes wrapping Laravel's
`FilesystemManager` — there is no AWS SDK integration, no S3 deployment, and
no CDN infrastructure here. Moving from local storage to S3, adding a CDN in
front of it, or switching to any other Laravel filesystem disk is a
`config/filesystems.php` disk change (driver/url), not a code change: neither
class ever hardcodes a disk name, both always use whichever disk the caller
specifies. `config/media.php` maps each media kind to a disk and base
directory (`MEDIA_PUBLIC_DISK`, `MEDIA_PRIVATE_DISK`); there is deliberately
no `MEDIA_DRIVER` — a Laravel disk name *is* the configuration surface.

`MediaPathGenerator` builds the collision-resistant object key a file is
stored at (a fresh UUID, never the client-supplied filename), nested by
year/month for post images and by owner user id for avatars — except for
demo seeders, which pass an explicit deterministic path so reruns land on the
same key instead of accumulating one new asset per run.

`MediaAssetCreator`, `PostImagePresenter`, and `AvatarUrlResolver`
(`app/Support/Media/`) are the only things that know a post's image comes
from `imageAsset` or a user's avatar comes from `avatarAsset` — they delegate
straight to `MediaUrlResolver`. Rendering a fallback when there's no avatar
(initials, in this app) is a presentation concern
(`resources/views/components/ui/avatar.blade.php`), not something either
class or the resolver itself knows about.

`Post::public_image_url` and `User::resolved_avatar_url` remain as thin
compatibility accessors — kept because Blade views and API resources already
call them in a dozen-plus places — but they carry no filesystem knowledge of
their own; they just delegate to `PostImagePresenter`/`AvatarUrlResolver`.
New code should inject the presenter/resolver directly rather than adding new
callers of the accessors. `PostOpenGraph` already does, calling
`PostImagePresenter` rather than the model accessor.

The previous `ImageStorage`/`LocalImageStorage` abstraction (including its
`cloudinary` driver branch, which resolved to a class whose only method
always threw at call time) has been removed outright — there is exactly one
storage/URL abstraction in this codebase now.

## What this schema/storage work does not do

No EXIF handling, autorotate, recompression, resizing, responsive variant
generation, `srcset`, focal points, AI cropping, Imagick/libvips integration,
an actual S3/CDN deployment, imgproxy, temporary signed URLs, legacy data
backfill, or an orphan/cleanup command. New `MediaAsset` rows are created
synchronously as `ready` the moment a file is stored and its metadata is read
from the upload itself — there is no processing pipeline yet for anything to
be `processing` or `failed` in normal operation (those statuses exist for
later use). `MediaVariant` rows are never generated yet, though
`MediaUrlResolver` already resolves them once something does start creating
them.

The media domain schema change (PR-02) was destructive with no production
data to protect (staging only); existing staging posts/avatars and their
uploaded files were not migrated forward, and staging was reset after that
PR landed.

## Roadmap

- **PR-04** — Normalization: whatever schema/data cleanup falls out of real
  S3/CDN usage once it exists.
- **PR-05** — Responsive variants: actually generating the `MediaVariant`
  rows this schema and `MediaUrlResolver` already support.
- **PR-06** — Open Graph variant generation: build the `open_graph`
  `MediaVariant` pipeline this schema already has a home for.
- **PR-07** — Asset lifecycle: orphan detection/cleanup, and actually
  deleting a replaced avatar's physical file (today it's soft-deleted in the
  database but deliberately left on disk).
- **PR-08** — URL import security hardening.
