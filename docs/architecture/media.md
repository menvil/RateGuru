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

- **`MediaStorage`** — `storeNormalized()`, `putContents()` (for in-process
  generated content, e.g. demo seeders), `exists()`, `size()`, `readStream()`,
  `delete()`. Never resolves a URL, and never decodes or validates image
  content — that's `ImageIngestor`'s job, upstream of this class (see below).
  There is deliberately no method that accepts a raw, unprocessed upload:
  `storeNormalized()` only takes an already-normalized `NormalizedImage`, so
  there's no production path for a user-controlled image to reach disk
  without going through the ingest pipeline first. A filesystem exception (not
  just a `false` return) from the underlying disk's `put()` is normalized into
  `MediaStorageException`, with the original exception preserved as
  `$previous`, in both `storeNormalized()` and `putContents()`.
- **`MediaUrlResolver`** — `publicUrl(MediaLocation $location, MediaVisibility $visibility)`
  (throws `MediaIsNotPublicException` when visibility isn't public) and
  `publicUrlOrNull(?MediaLocation $location, ?MediaVisibility $visibility)`
  (returns `null` instead, for a null location *or* null visibility). This is
  a **pure data boundary**: it never touches the database, never lazy-loads a
  relation, and never decides what a `MediaAsset` or `MediaVariant`'s own
  visibility is. The caller — `PostImagePresenter`/`AvatarUrlResolver` today
  — already has the asset loaded and passes its disk/path/visibility in
  directly. A `MediaVariant` has no visibility column of its own; a future
  variant presenter passes its *parent* asset's visibility (via
  `$variant->asset?->visibility`, which is already `null` — not a crash — if
  the parent is missing or soft-deleted), never the variant.

Both are implemented by `Filesystem*` classes wrapping Laravel's
`FilesystemManager` — there is no AWS SDK integration, no S3 deployment, and
no CDN infrastructure here. Moving from local storage to S3, adding a CDN in
front of it, or switching to any other Laravel filesystem disk is a
`config/filesystems.php` disk change (driver/url), not a code change: neither
class ever hardcodes a disk name, both always use whichever disk the caller
specifies. `config/media.php` maps each media kind to a disk and base
directory (`MEDIA_PUBLIC_DISK`); there is deliberately no `MEDIA_DRIVER` — a
Laravel disk name *is* the configuration surface. There is no dedicated
private-disk flow yet (no `MEDIA_PRIVATE_DISK` — it had zero consumers and
was removed): `MediaVisibility::Private` and the not-public checks above
exist and are exercised, but nothing in this codebase currently uploads
anything as private, and there are no temporary/signed URLs.

`MediaPathGenerator` builds the collision-resistant object key a file is
stored at (a fresh UUID, never the client-supplied filename) from a single
`now()` instant (not two separate calls, which could straddle a
year/month rollover), nested by year/month for post images and by owner
user id for avatars — except for demo seeders, which pass an explicit
deterministic path so reruns land on the same key instead of accumulating
one new asset per run.

`MediaAssetCreator`, `PostImagePresenter`, and `AvatarUrlResolver`
(`app/Support/Media/`) are the only things that know a post's image comes
from `imageAsset` or a user's avatar comes from `avatarAsset`. The presenters
read the asset's own disk/path/visibility and hand `MediaUrlResolver` plain
data — the resolver itself never touches either relation. Rendering a
fallback when there's no avatar (initials, in this app) is a presentation
concern (`resources/views/components/ui/avatar.blade.php`), not something any
of these classes know about. Every query that renders avatars or post images
in a list (main feed, saved posts, comments, matched-user search results)
eager-loads `imageAsset`/`user.avatarAsset` — the presenters read an
already-loaded relation, they don't trigger the query themselves.

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

## ImageIngestor: one normalization pipeline in front of MediaStorage

Before PR-04, `MediaStorage` wrote a client's uploaded bytes to disk more or
less verbatim — it read dimensions afterward purely as metadata, but never
validated, decoded, or re-encoded anything. The app stored a user-supplied
image as a trusted original blob: client MIME/extension weren't fully
distrusted, EXIF orientation was never corrected, and EXIF/GPS/XMP metadata
was never stripped.

```
any supported input (upload, URL import)
  -> ImageInput (App\Services\Media\ImageInput)
  -> ImageIngestor::ingest()
  -> NormalizedImage (App\Services\Media\NormalizedImage)
  -> MediaStorage::storeNormalized()
  -> MediaAssetCreator -> MediaAsset
```

`ImageIngestor` (`app/Services/Media/ImageIngestor.php`, sole implementation
`GdImageIngestor`) is the single boundary every user-controlled image passes
through, for post uploads, avatar uploads, and URL-imported images alike —
there is no `PostImageProcessor`/`AvatarImageProcessor`/`ImportedImageProcessor`
split. `CreatePostAction` and `UpdateUserProfileAction` both build an
`ImageInput` from the `UploadedFile` they already have (`ImageInput::fromUploadedFile()`),
call `ImageIngestor::ingest()` with a policy built from `config('uploads.images')`
(`ImageIngestPolicy::fromConfig()`), and pass the resulting `NormalizedImage`
to `MediaStorage::storeNormalized()`. `ImageIngestor` never touches the
filesystem itself — it's a pure transform from bytes to bytes, decoupled from
`UploadedFile` entirely (it only knows about `ImageInput`), which is what lets
`FilesystemMediaStorage::storeNormalized()` stay a plain "write these known,
trusted bytes" operation with no decode step, and no post-write cleanup for a
decode failure — there isn't one anymore, since decoding happens before a
byte ever reaches storage.

**Supported formats**: JPEG, PNG, and WebP only — in and out. A JPEG input
produces a JPEG output, PNG stays PNG (alpha preserved), WebP stays WebP
(alpha preserved); the ingestor never converts between formats. No AVIF, GIF,
SVG, BMP, TIFF, or HEIC. Format is determined purely from the actual bytes
(`getimagesizefromstring()` cross-checked against `finfo`) — a client's
declared `Content-Type`, `getClientMimeType()`, and original filename/extension
are never consulted for this. The saved extension is always the canonical one
for the detected MIME (`image/jpeg`→`jpg`, `image/png`→`png`,
`image/webp`→`webp`); `original_filename` is stored as metadata only, it never
determines path, MIME, or output extension.

**Limits** (`config/uploads.php`'s `images` array): a hard byte cap before any
decode is attempted, `max_width`/`max_height`, and a `max_pixels` cap
(default 16,000,000 — not the 36,000,000 a naive 6000×6000 might suggest).
GD's truecolor bitmap costs 4 bytes/pixel, and an EXIF orientation correction
needs a second buffer of the same size simultaneously (source + rotated/
flipped destination), so peak ingest memory is roughly 8 bytes/pixel: 16MP
peaks around 128MiB, comfortably under the 256M `memory_limit` configured for
php-fpm in production/staging (`infrastructure/config/php-fpm/rateguru-{production,staging}.conf`),
where 36MP's ~288MiB would already exceed it before accounting for any
framework overhead. The pixel check runs on the header-parsed dimensions
*before* the full decode — this doubles as the memory-safety guard; there's
no separate dynamic budgeting mechanism. The normalized *output* is also
capped against the same byte limit after re-encoding (no quality-reduction
retry loop if it doesn't fit).

**EXIF orientation**: read via `exif_read_data()` for JPEG only (PNG/WebP have
no EXIF orientation concept). All 8 standard values are handled — not just
the axis-aligned 3/6/8, but the mirrored 2/4/5/7 too — via `imageflip()`/
`imagerotate()`. No EXIF data, or no `Orientation` tag, is the common, valid
case for an image with no EXIF at all and is treated as orientation 1
(normal); a tag that's *present* but not one of the 8 recognized values is an
explicit rejection (`ImageOrientationException`), never a silent default.
Width/height are re-derived after the transform, since orientations 5–8 swap
them.

**Metadata stripping** happens as an unavoidable consequence of the pipeline
shape, not extra logic: `imagecreatefromstring()` decodes into a plain GD
bitmap that carries no EXIF/GPS/XMP/comment/thumbnail data at all, and
`imagejpeg()`/`imagepng()`/`imagewebp()` only ever write image data back out —
there is no code path by which the re-encoded output could carry any of the
input's original metadata forward. No color-profile (ICC) management is
attempted; that stays out of scope.

**Re-encode settings** come from the same `config('uploads.images')`:
`jpeg_quality` (default 90, written progressive via `imageinterlace()`),
`png_compression` (default 6), `webp_quality` (default 90).

**Error handling**: a small `ImageIngestException` hierarchy
(`UnsupportedImageFormatException`, `ImageTooLargeException`,
`ImageDimensionsExceededException`, `ImagePixelLimitExceededException`,
`ImageDecodeException`, `ImageEncodeException`, `ImageOrientationException`,
all under `app/Services/Media/Exceptions/`) — each preserves the original
exception as `$previous`, and messages never embed a filesystem path or raw
GD warning text. GD warnings are converted to catchable exceptions with a
*scoped* error handler (`set_error_handler()`/`restore_error_handler()`
around just the relevant call), never a global one, so a bad image can never
be silently trusted just because a caller forgot to check a return value.
`UploadPostForm`/`EditProfileForm` catch `ImageIngestException` specifically
and show a dedicated, safe validation message
(`ui.upload.error_invalid_image`) rather than the fully generic upload-error
message.

**Trusted, server-generated content still bypasses the ingestor** —
`DemoFillSeeder`'s raw-GD-drawn placeholder JPEGs and
`DemoPostMediaGenerator`'s hand-built SVGs both call `MediaStorage::putContents()`
directly, exactly as before. This is intentional: they're not user input,
they're not photographs with EXIF/orientation concerns, and running them
through the same decode/re-encode pipeline would add cost with no benefit.
`putContents()` remains a real, un-gated method on `MediaStorage` for this
reason — what changed is that the two *user-facing* entry points
(`CreatePostAction`, `UpdateUserProfileAction`) can no longer reach it or any
raw filesystem write directly, which
`tests/Unit/Actions/UserFacingImageFlowsUseIngestorTest.php` enforces the same
grep-based way `tests/Unit/Models/MediaModelsDoNotUseFilesystemTest.php`
already enforced for the model layer.

**URL-imported images converge on the same pipeline for free.**
`StoreImportedImageAction::download()` still fetches bytes via the
SSRF-protected `SafeImportHttpClient`/`UrlImportValidator` exactly as before —
none of that changed — and still wraps the result as a real `UploadedFile` so
it can flow through `UploadPostForm`'s existing `WithFileUploads` validation.
By the time that `UploadedFile` reaches `CreatePostAction`, it's
indistinguishable from a directly-uploaded file, so the same
`ImageInput::fromUploadedFile()` → `ImageIngestor::ingest()` call already
covers it. **PR-08's URL-import hardening (DNS pinning/rebinding protection,
redirect-security redesign, streaming-downloader rewrite) has not happened
yet** — this PR only changed what happens to the bytes once they're already
downloaded, not how they're fetched.

## What this schema/storage work does not do

Responsive variant generation, `srcset`, focal points, AI cropping, an actual
S3/CDN deployment, imgproxy, temporary signed URLs, legacy data backfill, or
an orphan/cleanup command. New `MediaAsset` rows are created
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

- **PR-04** (done) — Image ingest/normalization: the `ImageIngestor` pipeline
  described above. Format detection from actual bytes, byte/dimension/pixel
  limits, full decode validation, EXIF orientation correction, re-encode,
  metadata stripping — for post uploads, avatar uploads, and URL-imported
  images alike. No variants, no resizing of already-valid images, no format
  conversion.
- **PR-05** — Responsive variants: actually generating the `MediaVariant`
  rows this schema and `MediaUrlResolver` already support.
- **PR-06** — Open Graph variant generation: build the `open_graph`
  `MediaVariant` pipeline this schema already has a home for.
- **PR-07** — Asset lifecycle: orphan detection/cleanup, and actually
  deleting a replaced avatar's physical file (today it's soft-deleted in the
  database but deliberately left on disk).
- **PR-08** — URL import security hardening: DNS pinning/rebinding
  protection, redirect-security redesign, streaming-downloader rewrite. Not
  started — PR-04 only changed what happens to bytes after they're
  downloaded, not the download/fetch layer itself.
