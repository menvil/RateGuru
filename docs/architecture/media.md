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
rendition, a resized detail size, a cropped avatar thumbnail, and (as of
PR-06) a cropped Open Graph image. Each variant belongs to one `MediaAsset`,
has a `name` unique per asset, and its own `disk`/`path`/dimensions/mime/byte
size. As of PR-06, six variant names are actually generated for JPEG/PNG/WebP
post-image/avatar assets — see "Responsive media variants" and "Open Graph
media variant" below for what, how, and when.

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

Deleting a `Post` or `User` does not cascade-delete their asset via this FK —
the FK's `nullOnDelete()` only ever fires on a real SQL `DELETE`. Soft-
deleting a `Post` (the only kind of post deletion that exists in this app)
never touches `image_asset_id` at all — see "Media lifecycle" below for how
PR-07 handles asset release and physical cleanup.

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
orphaned. It soft-deletes the previous avatar asset inline (unchanged by
PR-07) — physical cleanup happens later, on its own schedule, once the grace
period expires; see "Media lifecycle" below.

## Open Graph is a variant, not a post column

The legacy `posts.og_image_path` column and the generator/job that populated
it are gone. Architecturally, an Open Graph crop is a `MediaVariant` named
`open_graph` on a post's image asset — see "Open Graph media variant" below
for the actual pipeline (PR-06).

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
eager-loads `imageAsset.variants`/`user.avatarAsset.variants` — the presenters
read an already-loaded relation, they don't trigger the query themselves.

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

```text
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

**EXIF orientation**: read via `exif_read_data()` for JPEG (the only format
that function understands). All 8 standard values are handled — not just the
axis-aligned 3/6/8, but the mirrored 2/4/5/7 too — via `imageflip()`/
`imagerotate()`. No EXIF data, or no `Orientation` tag, is the common, valid
case for an image with no EXIF at all and is treated as orientation 1
(normal); a tag that's *present* but not one of the 8 recognized values is an
explicit rejection (`ImageOrientationException`), never a silent default.
Width/height are re-derived after the transform, since orientations 5–8 swap
them. PNG (`eXIf` chunk) and WebP (`EXIF` RIFF chunk) can legally carry the
same Orientation tag, but `exif_read_data()` can't parse either container —
rather than silently treating such a file as already correctly oriented, a
lightweight chunk-type scan (not a full parse) detects the chunk's mere
presence and rejects the image outright.

**Known limitation**: that scan only detects whether an `eXIf`/`EXIF` chunk
exists, not what it contains — a PNG/WebP whose chunk has no `Orientation`
tag at all, or carries `Orientation: 1` (already normal, a no-op), is
rejected exactly the same as one that would actually need correcting. A real
parser for either container would resolve this, but isn't worth building for
what's a rare case in practice (EXIF-in-PNG/WebP is uncommon outside tools
that deliberately copy it over from a source JPEG); rejecting a technically
harmless file is judged safer than the alternative of building a second,
less-battle-tested metadata parser.

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

## Responsive media variants

PR-05 generates five fixed variants from the master image, stores them as
`media_variants` rows, and switches post-image/avatar rendering to
`srcset`/`sizes`/real `width`/`height` instead of always serving the master.

**Variants** (`MediaVariantSpecificationRegistry`): three post-image variants
(`post_feed_640`, `post_feed_1280`, `post_detail_1920`, all `Contain` mode —
scaled down to fit within bounds preserving aspect ratio, capped at the
source's own size, never upscaled), a fourth post-image-only variant
(`open_graph`, see below), and two avatar variants (`avatar_128`,
`avatar_256`, both `CoverSquare` mode — the largest centered square cropped
out of the source, then resized to an exact size). A `CoverSquare`/`Cover`
spec is skipped entirely when the source is smaller than the target size in
either dimension (`MediaVariantSpecification::wouldUpscale()` — never
upscaled, never generated undersized-but-mislabeled); a `Contain` spec always
generates, since capping at the source's own size *is* its no-upscale
behavior. `MediaKind::Avatar` never gets an `open_graph` variant — the
registry's `for(MediaKind::Avatar)` array simply doesn't declare one, so
there is no runtime branch to get wrong.

**Generation** (`GdImageVariantProcessor`, mirroring `GdImageIngestor`'s own
scoped-error-handling and alpha-preservation idioms rather than sharing code
that was never exported): decode the master, resample per spec in a single
`imagecopyresampled()` call, re-encode, then re-derive and cross-check
mime/dimensions from the output bytes before returning — the same
belt-and-suspenders validation `GdImageIngestor` applies to its own output.
Every spec's crop/resize is one of two plans: `planContain()` (no crop, just
scale-to-fit) or `planCover(srcW, srcH, targetW, targetH)` (scale-to-cover
plus a centered crop to the target's own aspect ratio) — `CoverSquare`'s
"largest centered square" is just `planCover()` called with an equal
width/height target, not a separately-implemented case.

**Storage and idempotency** (`MediaVariantPathGenerator`, `MediaVariantWriter`):
a variant's path is derived deterministically from its master's own,
already-immutable path (never a new UUID), so retrying a failed generation or
regenerating an existing variant always lands on the same file. The DB row is
upserted on `(media_asset_id, name)`. There's no atomic file-move primitive on
`MediaStorage`, so ordering is best-effort rather than transactional: generate/
encode/validate fully in memory first, write the file, then upsert the row. A
DB failure after a *first-time* write deletes the now-orphaned file
(best-effort, reported but never replacing the original exception, the same
shape as `StoredMediaCleaner`); a DB failure after *overwriting* an existing
variant does not delete the file — the previously-working file has already
been replaced, so deleting would leave nothing, and the row's metadata may be
transiently stale until a successful retry.

**Dispatch** (`MediaVariantGenerator`, `GenerateMediaVariantsJob`): generation
is a plain service
(`MediaVariantGenerator::generateAll(MediaAsset $asset, ?MediaVariantName $only = null, bool $force = false)`),
not job logic itself, so both the queued job and `DemoFillSeeder` can call the
identical code path — the seeder synchronously (this app's
`QUEUE_CONNECTION=sync` locally makes a real dispatch pure indirection there),
real uploads via `GenerateMediaVariantsJob::dispatch($mediaAssetId)->afterCommit()`
from both `CreatePostAction` and `UpdateUserProfileAction`. The optional
`$only` filter exists so the CLI's `--variant=` option and a future targeted
retry can regenerate a single named variant without touching the others.

By default (`$force = false`), `generateAll()` skips a spec whose row *and*
physical file both already exist (`MediaStorage::exists()`) — it never
re-encodes or rewrites something that's already correct. For a brand-new
asset (the job/seeder's own call sites) nothing exists yet, so "missing" is
simply "everything", making this identical to full generation there, at the
cost of one cheap extra query per call to check what (if anything) already
exists. `$force = true` (the CLI's `--force`) bypasses that check entirely
and rewrites every applicable spec regardless — used deliberately, not the
default, since rewriting an already-correct variant is wasted work the vast
majority of the time this command runs.

A failure on any one spec inside `generateAll()` propagates immediately
rather than being caught per-spec: `updateOrCreate()` makes redoing
already-succeeded specs on a retry a safe, cheap no-op (and, since PR-06,
the skip-if-already-generated check above means a clean retry doesn't even
attempt to redo them) — failing the whole call is a simple, deliberate
tradeoff over partial-success bookkeeping. The registry lists `open_graph`
last for `MediaKind::PostImage`, so the three feed/detail specs always
generate first (and are never touched again on a retry) even on a run where
`open_graph` specifically fails.

**Reliability** (PR-06): `GenerateMediaVariantsJob` declares `tries = 3`,
`backoff = [10, 60, 300]`, `timeout = 120` — real retry semantics for a real
queue driver. Today's `QUEUE_CONNECTION=sync`, however, has no worker process
and no retry loop at all: `Illuminate\Queue\SyncQueue` runs the job inline and
rethrows synchronously on failure, so `$tries`/`$backoff` are currently
inert, declared for whenever this app moves to a real queue connection. The
`JobFailed` event that would normally populate `failed_jobs` is only ever
recorded by `Illuminate\Queue\Console\WorkCommand`, which nothing in this
app runs — so `failed_jobs` is schema/config-present (the standard
mechanism is used, nothing custom replaces it) but not actually populated
today. The real, present-day safety net is the dispatch call site's own
`catch (Throwable)` in `CreatePostAction`/`UpdateUserProfileAction`
(`report()` + `Log::error('Failed to dispatch or run media variant
generation.', ...)`), plus two more logging layers added in PR-06:
`GenerateMediaVariantsJob::handle()` logs `media_asset_id`, the job's own
UUID (`$this->job?->uuid()` — reliable even under `sync`, unlike
`getJobId()`, which `SyncJob` hardcodes to `''`), attempt number, and the
exception class before rethrowing; `MediaVariantGenerator` logs
`media_asset_id`/`variant`/exception class for a per-spec failure, and
`media_asset_id`/exception class if the master file itself can't be read.
None of these ever log image bytes, URLs, or EXIF data, and there is no log
line on the success path (only the CLI's own end-of-run summary). No outbox,
no custom failed-job table, no `ShouldBeUnique` — the existing
`MediaVariantWriter` lock (see above) already fully serializes concurrent
writers for the same asset+variant, and under `sync` there is no scenario
where two dispatches for the same asset genuinely overlap in time.

**Presentation** (`PostImagePresenter::responsive()`, `AvatarUrlResolver::responsive()`,
`ResponsiveImage`): each reads the already-loaded `variants` relation only
(no query, no storage I/O — a stale row whose file is gone still renders an
`<img>` pointing at it) and returns a `src`/`srcset`/`sizes`/`width`/`height`
DTO. `PostImagePresenter::openGraph()` (see "Open Graph media variant" below)
is the one exception to "no storage I/O": every OG candidate is checked with
`MediaStorage::exists()` before use, since a crawler that 404s on a stale
`og:image` is a worse, longer-lived problem than the extra check costs. Post
images pick a different variant set per context (feed/drawer prefer
`post_feed_640`/`post_feed_1280`; standalone prefers `post_feed_1280`/
`post_detail_1920`; fullscreen prefers `post_detail_1920`, adding the master
to its `srcset` only when the master isn't drastically larger). Avatars
always prefer `avatar_128`/`avatar_256`, with `sizes` always `null` (both
candidate widths are small enough that a default `100vw` selection never
needs a hint). Whenever an expected variant hasn't been generated yet (e.g. an
older or in-flight asset), presentation falls back to the master image
gracefully — never a broken `<img>`. `width`/`height` on the rendered `<img>`
always match whichever source was actually chosen, not always the master's.
The first image in a feed/list loop gets `fetchpriority="high"` — derived
from the same `loading === null` (eager) signal the `:eager-image="$loop->first"`
mechanism already set, not a separate prop.

**CLI**: `php artisan media:generate-variants {--asset=} {--kind=} {--variant=} {--missing-only} {--force} {--chunk=200}`
backfills variants for existing assets — `--missing-only` (default, and also
accepted explicitly; passing both `--force` and `--missing-only` together is
rejected as a contradiction) skips assets that already have every applicable
variant and, per asset, skips only the specs that are already valid (see
`generateAll()`'s `$force` parameter above); `--force` regenerates everything
matching the filters regardless of what already exists; `--variant=` (PR-06)
restricts processing to one named variant (e.g. `--variant=open_graph`, to
backfill just the Open Graph crop for assets created before PR-06 without
touching their already-generated feed/detail variants). There is deliberately
no separate `media:generate-og` command — one command with a filter, not a
second command, per the same reasoning that keeps generation itself as one
shared service rather than a variant-specific job. Runs synchronously,
chunked, logging and continuing past a single asset's failure rather than
aborting the run.

**Missing-file recovery** (PR-06): "missing" (both the command's own
per-asset skip check and `generateAll()`'s own per-spec skip check) means a
variant whose `media_variants` row doesn't exist *or* whose row exists but
the physical file at `(disk, path)` is gone (`MediaStorage::exists()`) —
recovering from a variant whose row survived but whose file was lost (e.g. a
wiped staging disk) as well as from one that was simply never generated.
`PostImagePresenter::openGraph()` (see "Open Graph media variant" below) also
checks existence, but only for its own three OG-image candidates — never
`responsive()`, and never on every page render for anything but that specific
DTO. There is still no corruption/integrity scanner (a `media_variants` row
whose file exists but is truncated or corrupted is not detected) — that
remains deferred (PR-07 adds orphan detection and safe physical deletion,
not integrity checking).

**Operational recovery step**: if variants for existing assets are ever found
missing or lost on staging/production, the fix is running
`php artisan media:generate-variants --missing-only` (optionally scoped with
`--asset=`/`--kind=`/`--variant=`) by hand. This is a manual, human-triggered
operational step, documented here rather than wired into any deploy or
release automation — there is no migration/backfill script that runs it
automatically.

## Open Graph media variant

PR-06 adds a fourth post-image variant, `open_graph`, and wires it into the
`og:image`/`twitter:image` meta tags rendered on the post-show page.

**Specification** (`MediaVariantSpecificationRegistry`, last entry under
`MediaKind::PostImage`): exact `1200x630`, `Cover` mode (crop-to-fill, not
letterboxed), always encoded as `image/jpeg` regardless of the master's own
format (`MediaVariantSpecification::$outputMimeType`, a spec-level override —
every other variant leaves this `null`, meaning "same as source"). The
1200x630/`image/jpeg` values match `config/share.php`'s pre-existing
`open_graph.*` block (already read by the SEO placeholder fallback before
PR-06); a registry test cross-checks the two so they can't silently drift
apart. Quality reuses the same fixed quality constant every other post-image/
avatar variant uses — there is no separate, more complex quality-negotiation
engine.

**Crop**: `GdImageVariantProcessor::planCover(srcW, srcH, targetW, targetH)`
generalizes the existing avatar `CoverSquare` math to an arbitrary (non-
square) target aspect ratio — scale so the source fully covers the target
rectangle, then crop the centered excess on whichever axis overflows.
`planCoverSquare()` (still used by avatars) is now a thin call into
`planCover()` with an equal width/height target; the two are verified
(by test) to produce identical output for the same source, so avatar
behavior is unchanged.

**Format normalization**: when a spec declares `outputMimeType`, encoding
uses that instead of the source's own mime type. For a JPEG-source master
this is a no-op (opaque source, no alpha to lose). For a PNG/WebP master with
transparency, the canvas is explicitly filled white
(`imagecolorallocate(255,255,255)` + `imagefill()`) before the resample —
without this, GD's default canvas fill (black, with alpha blending on) would
let a transparent region bleed through as black once flattened to JPEG
(which has no alpha channel at all). This flatten-to-white behavior is
covered by a dedicated test asserting the previously-transparent corner
pixel is white, not black, in the encoded JPEG output.

**Upscale guard**: like avatars, `open_graph` is skipped (not generated,
never generated undersized) when the source is smaller than 1200x630 in
either dimension (`MediaVariantSpecification::wouldUpscale()`) — e.g. the
demo seeder's own 800x600 generated placeholder images never get an
`open_graph` variant, only the three `Contain` feed/detail variants, exactly
as expected.

**Path**: deterministic, exactly like every other variant —
`MediaVariantPathGenerator` derives `.../variants/open_graph.jpg` from the
master's own path, nested under it.

**SEO presentation** (`PostImagePresenter::openGraph()`, `PostOpenGraph::image()`):
fallback chain is `open_graph -> post_detail_1920 -> master -> the static
placeholder image` — `post_feed_640`/`post_feed_1280` are never considered
(too small for a social-share preview). This mirrors `responsive()`'s own
private-safety shape exactly: `MediaUrlResolver::publicUrlOrNull()` is
checked *before* any variant lookup, so a private post's image never leaks a
URL as `og:image` regardless of whether `variants` happens to be
eager-loaded, and `variants` is never lazy-loaded (an asset loaded without
`imageAsset.variants` falls back to the master, not an N+1 query). Unlike
`responsive()`, every candidate here — `open_graph`, `post_detail_1920`, and
the master — is additionally checked with `MediaStorage::exists()` before
being used, moving on to the next candidate (and ultimately to `null`, i.e.
the static placeholder) when a row's file has been lost; a social crawler
caching a 404'd preview is a worse, longer-lived outcome than the extra
existence check costs on a post-show render. All URLs still go through
`MediaUrlResolver` exclusively — nothing manually builds a `/storage/...`
path. `og:image:width`/`height`/`type` — already conditionally rendered in
`post-show.blade.php` since PR-05 — now receive real values (`1200`/`630`/
`image/jpeg` for the dedicated variant, the source's own dimensions/mime for
a master/detail fallback) instead of always `null`; `twitter:image` reuses
the exact same resolved image and `og:image:alt`/`twitter:image:alt`, since
this app doesn't maintain a separately-cropped Twitter asset. A post with no
image, or a private image, never reaches any of the above —
`PostImagePresenter::openGraph()` returns `null` immediately, and
`PostOpenGraph::image()`'s existing (unchanged) `null` branch supplies the
static placeholder — the same one used before PR-06, not a new
auto-generated placeholder.

**Regression fix bundled with this work**: `PublishedPostDetailsQuery` (backs
both the standalone post page and the drawer) never eager-loaded
`imageAsset.variants`/`user.avatarAsset.variants` at all — unlike every list
query (`FeedQuery`, `SavedPostsQuery`, etc.), which already did this in
PR-05. This meant the standalone/drawer pages' own `responsive()` srcset (and
now `openGraph()`) silently fell back to the master image only, since PR-05
shipped. Fixed by adding the same eager-load PR-05's list queries already
use.

## Media lifecycle

PR-07 adds the other half of the lifecycle PR-05/06 never touched: actually
deleting media, safely. `MediaAsset` has used `SoftDeletes` since the schema
was introduced, but nothing physically removed a file until now — avatar
replacement soft-deleted the previous asset and left its file on disk
forever, and `MediaVariant` (no `SoftDeletes` at all) never had application-
level cleanup of its own file when its parent was force-deleted (only the
row auto-cascaded via the DB FK).

**Lifecycle states**: active (not soft-deleted) → soft-deleted, within grace
→ soft-deleted, grace-expired and unreferenced ("purgeable") → physically
purged (row and files both gone). Soft-delete is never physical delete — a
grace period (`MEDIA_PURGE_GRACE_DAYS`, default 7 days,
`config('media.lifecycle.purge_grace_days')`) always sits between them. This
is a recovery window against mistakes and races, not a user-facing undo
feature.

**`MediaReferenceChecker`** (`app/Services/Media/MediaReferenceChecker.php`)
is the single source of truth for "is this asset still owned by anything" —
checked against the only two real FK usages that exist anywhere in this app:
`posts.image_asset_id` and `users.avatar_asset_id`. No reflection/schema
scanning, no invented future relations. Critically, the post check uses
`Post::withTrashed()`: a soft-deleted-but-restorable post must still count
as referencing its image, or every soft-deleted post's image would look
orphaned the instant the post itself is soft-deleted, even though both may
still be restored.

**`MediaLifecycleService`** (`app/Services/Media/MediaLifecycleService.php`)
is the single owner of every purge decision and action — nothing else force-
deletes a `MediaAsset`/`MediaVariant` row or purges its physical files.
`StoredMediaCleaner` and `MediaVariantWriter::deleteQuietly()` remain
separate, pre-existing, narrowly-scoped create-flow compensation (deleting a
just-written file after a DB failure moments later), not part of this
lifecycle — delete logic isn't smeared across actions/jobs/commands, but
compensation and lifecycle purge are deliberately still two different
things with two different triggers.

- `isPurgeable(MediaAsset $asset, ?int $graceDays = null): bool` — a pure
  decision (no lock, no mutation): trashed, `deleted_at` past the grace
  cutoff, and unreferenced. Safe to call repeatedly for reporting
  (`media:audit`, `media:purge --dry-run`).
- `purge(MediaAsset $asset, ?int $graceDays = null): MediaPurgeResult` — the
  real thing. See "Purge algorithm" below.
- `releaseUnreferenced(Collection $assetIds): void` — the hook
  `DeleteUserAccountAction` calls once, with every asset id that may have
  just become unreferenced by a hard user delete. One reference-check pass
  (`MediaReferenceChecker::referencedAssetIds()`) plus a single bulk
  soft-delete for whichever ids are still unreferenced, rather than one
  release call per asset id. Soft-deletes only (starts the grace period;
  physical purge still waits for `media:purge`). Best-effort and never
  throws — a cleanup hiccup here must never turn an already-successful
  account deletion into a reported failure.
- `purgeOrphanFile(MediaLocation $location): void` — deletes a physical
  orphan found by `MediaOrphanScanner`. No DB row is involved by definition.

**Purge algorithm**: acquire a per-asset lock → reload the asset (including
trashed) fresh from the DB, since the caller's own copy may be stale by the
time a chunked sweep reaches it → re-verify grace + references under the
lock (the outer query in `media:purge` is just an efficient candidate
filter, never the source of truth) → delete each variant's physical file →
delete the master's physical file → one short `DB::transaction()` that
force-deletes only the asset row (`media_variants.media_asset_id` has
`->cascadeOnDelete()` at the DB level, so the variant rows disappear as part
of that same statement — no separate variant-row delete needed) → release
the lock. All physical file I/O happens *before* the one-statement DB
transaction — no remote I/O inside a long-held transaction.

**Locking**: the same `Cache::store('database')`/`LockProvider` pattern
`MediaVariantWriter` already uses for variant writes (see "Responsive media
variants" above), keyed `media-purge:{$assetId}`
(`config('media.lifecycle.purge_lock.ttl_seconds')`, default 60s). Unlike
the variant writer, purge takes a single **non-blocking** attempt
(`Lock::get()`, not `Lock::block()`): purge runs as a chunked batch sweep
over many assets, and blocking on one contended asset would stall the whole
run. A failed attempt just skips that asset for this pass — purge reruns
routinely (see "Scheduling" below), and eligibility remains valid on the
next attempt as long as the asset's trashed/grace-expired/unreferenced
state hasn't changed in the meantime (e.g. it wasn't restored, or a new
reference wasn't created since the last check). No additional coordination
with `MediaVariantWriter`'s own
per-variant lock is needed: `MediaVariantGenerator::generateAll()` already
refuses to touch a trashed asset (a PR-05 guard), and purge eligibility
requires several days soft-deleted by construction — no code path in this
app dispatches generation anywhere near that late.

**Idempotency and partial-failure recovery**: every physical delete goes
through `MediaStorage::deleteIfExists()` (new in PR-07) — a missing file is
a no-op success, never an exception, so re-running purge on a
partially-completed asset only does the remaining work. A *real* storage
failure (not just "already gone") still throws and aborts that asset's
purge immediately: no DB row is force-deleted, and the exception class plus
operation context (`media_asset_id`/`media_variant_id`/`disk`/`path`/
`operation`/`exception_class`, never bytes, never signed URLs) is logged.
The exception itself is not written to the log — it's returned to the
caller via `MediaPurgeResult`, and its message reaches an operator only
when `MediaPurgeCommand` prints it to the console for that failed asset.
The next `media:purge` run retries from scratch — already-deleted files
simply no-op on the retry.

The one state this can't fully protect against — files successfully deleted
but the DB transaction itself then fails — is explicitly a recoverable,
expected state: the row stays trashed, and the
next purge run finishes the DB step alone (every file delete it attempts is
already a no-op by then). This is a deliberate choice, not an oversight:
building an actual distributed transaction between Postgres and the
filesystem is out of scope, and "file gone, row pending" is always safely
recoverable by a retry, unlike the reverse.

**`media:audit`** (`app/Console/Commands/MediaAuditCommand.php`) — always
read-only, always exits successfully (finding problems is its job, not a
failure). Chunks every `MediaAsset` (including trashed) and reports counts
for: healthy/referenced, active-but-unreferenced (a gap-state alarm — should
never happen given the design, worth surfacing if it ever does),
soft-deleted-within-grace, soft-deleted-purgeable, assets with a missing
master file, variants with a missing physical file, and (via
`MediaOrphanScanner`) physical-orphan candidates.

**`media:purge`** (`app/Console/Commands/MediaPurgeCommand.php`) —
`{--asset=} {--older-than=} {--dry-run} {--orphans} {--force} {--chunk=200}`.
Two independent modes:
- Default: soft-deleted, grace-expired, unreferenced assets — real and
  destructive by default, since eligibility itself (re-verified under lock)
  is the safety gate; no extra confirmation flag is needed on top of it.
  `--older-than=` overrides the configured grace period (in days) for one
  run; `--asset=` scopes to a single id; `--dry-run` reports via
  `isPurgeable()` without calling `purge()`.
- `--orphans`: switches to physical-orphan mode (see below) instead of the
  DB-driven query. Deletion only ever happens with `--orphans --force`;
  `--dry-run` always wins if both are somehow passed together.

**Physical orphan detection** (`app/Services/Media/MediaOrphanScanner.php`)
is deliberately separate from purge, and from "a DB row whose file is
missing" (that's `media:generate-variants --missing-only` recovery
territory, and `media:audit`'s missing-master/missing-variant counts) — a
physical orphan is a file with **no matching row at all**. `MediaOrphanScanner`
scans `config('media.disks.public')` (the only disk this app's config
declares) across each `config('media.directories')` entry, builds a known-
locations set from every `MediaAsset` (including trashed — a grace-period
asset still legitimately owns its file) and every `MediaVariant`, and
reports files absent from that set that are also older than
`MEDIA_ORPHAN_GRACE_HOURS` (default 24h,
`config('media.lifecycle.orphan_grace_hours')`) — an in-flight upload from
moments ago is never flagged. Read-only by itself; `media:audit` only
reports its findings, `media:purge --orphans --force` is the only thing that
ever deletes what it finds, and that's always an explicit, human-triggered
operational decision — there is no automatic physical-orphan deletion
anywhere in this PR.

**`DeleteUserAccountAction`** (`app/Actions/Profile/DeleteUserAccountAction.php`)
is the one existing deletion flow this PR changes. `User` has no
`SoftDeletes` — account deletion is a real hard `$user->delete()`, and
`posts.user_id`/`comments.user_id` both `cascadeOnDelete()` at the DB level,
bypassing `Post`'s own `SoftDeletes` entirely: every post a deleted user
owned is hard-deleted outright, not soft-deleted, immediately making that
post's image (and the user's own avatar) truly unreferenced with no prior
cleanup hook anywhere. The action now captures the avatar/post-image asset
ids *before* deleting the user (via `$user->posts()->withTrashed()`, since a
post the user had already soft-deleted themselves is about to be
hard-cascade-deleted too, and would never be captured otherwise), then calls
`MediaLifecycleService::releaseUnreferenced()` once, with the whole batch,
*after* the delete succeeds — soft-deleting each now-orphaned asset
(starting its grace period), never throwing, never touching anything still
referenced by something else. `DeletePostAction`/`DeletePostInAdminAction`
are untouched: both are soft-delete-only (a post force-delete path doesn't
exist anywhere
in this app), and a restorable soft-deleted post correctly keeps "owning"
its image per `MediaReferenceChecker`'s own `withTrashed()` check — there is
nothing to hook there. Avatar replacement (`UpdateUserProfileAction`) is
also untouched — its existing inline soft-delete-the-previous-asset
behavior already does the right thing.

**Known gap**: `releaseUnreferenced()` is deliberately best-effort (it
reports and swallows its own exceptions rather than ever failing the account
deletion around it — see above). If it fails, or if the process crashes in
the narrow window between `$user->delete()` succeeding and the release call
running, the affected assets are left **active** (not soft-deleted) but
genuinely unreferenced — and `media:purge`'s normal sweep only ever
considers `MediaAsset::onlyTrashed()` rows, so a purely active-but-orphaned
asset is invisible to it and never starts its grace period on its own.
`media:audit`'s "Active, but unreferenced (unexpected — investigate)" count
is the detection mechanism for exactly this state. There is no dedicated
recovery command for it today — the operational fix is soft-deleting the
affected asset by hand (e.g. via `tinker`) once `media:audit` flags it,
after which the normal grace-period/purge flow picks it up like any other
release. This is judged an acceptable, rare residual risk (a release failing
specifically in that narrow window) rather than a reason to build durable
retry machinery for it, but it's a real gap, not a silent one.

**Scheduling**: `routes/console.php` registers
`Schedule::command('media:purge')->daily()->withoutOverlapping()` — the
first Laravel-scheduler entry in this repo (previously all periodic tasks
were external bash scripts under `infrastructure/`), made possible because
the staging/production cron already runs `php artisan schedule:run` every
minute, so no infra/deploy change was needed for it to take effect. The
scheduled default mode performs *real, destructive* deletion — rows and
files are actually removed — it is safe to run unattended because its own
eligibility gates (grace-expired, re-verified reference check, all under a
lock) are what make it safe, not any absence of destruction. Only that
default mode is scheduled — never `--orphans`, never `--force`. Physical-
orphan deletion stays a deliberate, human-triggered `--orphans --force`
operation, on purpose.

## What this schema/storage work does not do

Focal points/AI cropping, a crop UI, AVIF or other format negotiation,
`<picture>` markup, an actual S3/CDN deployment, imgproxy, temporary signed
URLs, legacy data backfill beyond the CLI command above, URL-import hardening
(PR-08), video/GIF variants, a general third-party media library/schema
redesign, an outbox/durable event bus/workflow engine or any queue-
infrastructure migration (Horizon, a custom failed-job UI, automatic repair
of a missing master, a corruption/integrity scanner, storage tiering,
backup integration, retention UI, polymorphic attachments), or
observability/admin diagnostics for the media pipeline beyond structured
failure logging (PR-09). New `MediaAsset` rows are still created synchronously
as `ready` the moment a file is stored — there is no processing pipeline for anything to
be `processing` or `failed` in normal operation (those statuses exist for
later use); variant *generation* is what's now asynchronous (queued) or
synchronous-by-design (seeders, the CLI command), not asset creation itself.

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
- **PR-05** (done) — Responsive variants: generating the five fixed
  `MediaVariant` rows described above and switching post-image/avatar
  rendering to `srcset`/`sizes`/real `width`/`height`. No Open Graph, no
  format negotiation, no crop UI.
- **PR-06** (done) — Open Graph variant: the `open_graph` `MediaVariant`
  (exact `1200x630`, cover crop, JPEG-normalized), SEO fallback chain
  (`open_graph -> post_detail_1920 -> master -> static placeholder`), and
  background-generation reliability hardening (structured failure logging at
  both the job and generator layers, `--variant=`/missing-file recovery on
  the CLI). No outbox, no queue-infrastructure migration, no admin
  diagnostics — see "Open Graph media variant" and "Reliability" above.
- **PR-07** (done) — Asset lifecycle: `MediaLifecycleService` owns every
  purge decision/action, a grace period before any physical delete
  (`MEDIA_PURGE_GRACE_DAYS`, default 7 days), reference checking against the
  two real FK usages that exist (`posts.image_asset_id`,
  `users.avatar_asset_id`), idempotent/retry-safe purge with a per-asset
  lock, `media:audit` (read-only report) and `media:purge` (safe by default,
  explicit `--orphans --force` for physical-orphan deletion). See "Media
  lifecycle" above. No integrity/checksum scanner, no automatic repair of a
  missing master, no admin dashboard — see PR-09.
- **PR-08** — URL import security hardening: DNS pinning/rebinding
  protection, redirect-security redesign, streaming-downloader rewrite. Not
  started — PR-04 only changed what happens to bytes after they're
  downloaded, not the download/fetch layer itself.
- **PR-09** — Media pipeline observability/admin diagnostics: surfacing
  variant-generation failures (today only in application logs) somewhere an
  operator can actually see them without grepping logs — e.g. an admin view
  of assets missing an expected variant, or a corruption/integrity scanner.
  Not started; explicitly out of scope for PR-06.
