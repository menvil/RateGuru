<?php

namespace App\Support\Media;

use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Enums\PostImageContext;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Support\Collection;

/**
 * The only thing that knows a post's image comes from imageAsset — callers
 * never touch disk/path/Storage themselves. Reads the asset's own
 * disk/path/visibility and hands the resolver plain data — the resolver
 * itself never touches the imageAsset relation. responsive() never lazy-loads
 * `variants`: when a caller hasn't eager-loaded `imageAsset.variants`, it
 * falls back to the master-image DTO instead of issuing a query.
 */
final class PostImagePresenter
{
    /**
     * Master is only added to the fullscreen srcset when it isn't
     * drastically larger than post_detail_1920 — otherwise a very high-res
     * upload would make a high-DPR fullscreen viewer fetch a needlessly
     * huge file just because it technically exists. 1.5x is a considered,
     * adjustable threshold, not a value given by spec.
     */
    private const float FULLSCREEN_MASTER_INCLUDE_RATIO = 1.5;

    /**
     * (max-width: 639px) is Tailwind's `sm` breakpoint, below which post
     * cards/panels bleed edge-to-edge in this app. At/above it, each
     * context's own measured container width applies: feed and standalone
     * both use `mx-auto max-w-[820px]` (feed-page.blade.php,
     * post-show.blade.php); the drawer's desktop width is
     * `min(70vw, 1008px)` (.rg-desktop-post-detail-overlay in
     * resources/css/app.css) less its own ~48px horizontal padding;
     * fullscreen's modal is `sm:max-w-[min(96vw,1200px)]`
     * (components/ui/modal.blade.php). These are measured, not copied
     * verbatim from any illustrative example.
     */
    private const array SIZES = [
        'feed' => '(max-width: 639px) 100vw, 820px',
        'drawer' => '(max-width: 1023px) 100vw, min(70vw, 960px)',
        'standalone' => '(max-width: 639px) 100vw, 820px',
        'fullscreen' => '(max-width: 639px) 100vw, min(96vw, 1200px)',
    ];

    public function __construct(
        private readonly MediaUrlResolver $resolver,
    ) {}

    public function url(Post $post): ?string
    {
        $asset = $post->imageAsset;

        if ($asset === null) {
            return null;
        }

        return $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);
    }

    public function responsive(Post $post, PostImageContext $context): ?ResponsiveImage
    {
        $asset = $post->imageAsset;

        if ($asset === null) {
            return null;
        }

        $masterUrl = $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);

        // A non-public asset (e.g. MediaVisibility::Private) has no
        // resolvable URL at all — bail out before any variant selection, so
        // behavior doesn't depend on whether `variants` happens to be
        // eager-loaded. Without this check, a private asset with variants
        // loaded would fall through to publicUrl() below (the strict,
        // throwing variant), while the exact same asset with variants NOT
        // loaded would silently return null from the branch below instead —
        // two different behaviors for the same underlying asset.
        if ($masterUrl === null) {
            return null;
        }

        // Never lazy-load: a caller that forgot to eager-load
        // imageAsset.variants gets the master-fallback DTO instead of an
        // N+1 query per post.
        if (! $asset->relationLoaded('variants')) {
            return new ResponsiveImage($masterUrl, null, null, $asset->width, $asset->height);
        }

        $variants = $asset->variants->keyBy(fn (MediaVariant $variant): string => $variant->name->value);

        $chosen = $this->firstExisting($variants, $this->fallbackChain($context));

        if ($chosen === null) {
            return new ResponsiveImage($masterUrl, null, null, $asset->width, $asset->height);
        }

        $srcUrl = $this->resolver->publicUrl(new MediaLocation($chosen->disk, $chosen->path), $asset->visibility);

        $srcsetEntries = $this->srcsetEntries($variants, $this->srcsetMembership($context), $asset->visibility);

        if ($context === PostImageContext::Fullscreen) {
            $detail = $variants->get(MediaVariantName::PostDetail1920->value);

            if ($detail !== null && $asset->width <= $detail->width * self::FULLSCREEN_MASTER_INCLUDE_RATIO) {
                $srcsetEntries[] = "{$masterUrl} {$asset->width}w";
            }
        }

        return new ResponsiveImage(
            src: $srcUrl,
            srcset: $srcsetEntries === [] ? null : implode(', ', $srcsetEntries),
            sizes: $srcsetEntries === [] ? null : self::SIZES[$context->value],
            width: $chosen->width,
            height: $chosen->height,
        );
    }

    /**
     * The single, fixed image used for Open Graph / Twitter card meta tags.
     * Fallback chain is open_graph -> post_detail_1920 -> master (never
     * feed_640, which is far too small for a social-share preview). Mirrors
     * responsive()'s private-safety shape exactly: publicUrlOrNull() is
     * checked before any variant lookup, so a private post never exposes an
     * og:image regardless of whether `variants` happens to be eager-loaded,
     * and `variants` is never lazy-loaded.
     */
    public function openGraph(Post $post): ?OpenGraphImage
    {
        $asset = $post->imageAsset;

        if ($asset === null) {
            return null;
        }

        $masterUrl = $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);

        if ($masterUrl === null) {
            return null;
        }

        if (! $asset->relationLoaded('variants')) {
            return new OpenGraphImage($masterUrl, $asset->mime_type, $asset->width, $asset->height);
        }

        $variants = $asset->variants->keyBy(fn (MediaVariant $variant): string => $variant->name->value);

        $chosen = $this->firstExisting($variants, [
            MediaVariantName::OpenGraph,
            MediaVariantName::PostDetail1920,
        ]);

        if ($chosen === null) {
            return new OpenGraphImage($masterUrl, $asset->mime_type, $asset->width, $asset->height);
        }

        $url = $this->resolver->publicUrl(new MediaLocation($chosen->disk, $chosen->path), $asset->visibility);

        return new OpenGraphImage($url, $chosen->mime_type, $chosen->width, $chosen->height);
    }

    /**
     * @param  Collection<string, MediaVariant>  $variants
     * @param  list<MediaVariantName>  $names
     */
    private function firstExisting(Collection $variants, array $names): ?MediaVariant
    {
        foreach ($names as $name) {
            $variant = $variants->get($name->value);

            if ($variant !== null) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param  Collection<string, MediaVariant>  $variants
     * @param  list<MediaVariantName>  $names
     * @return list<string>
     */
    private function srcsetEntries(Collection $variants, array $names, MediaVisibility $visibility): array
    {
        $entries = [];

        foreach ($names as $name) {
            $variant = $variants->get($name->value);

            if ($variant === null) {
                continue;
            }

            $url = $this->resolver->publicUrl(new MediaLocation($variant->disk, $variant->path), $visibility);
            $entries[] = "{$url} {$variant->width}w";
        }

        return $entries;
    }

    /**
     * The chain considered for `src` (the non-srcset fallback) — deliberately
     * longer than srcsetMembership() for feed/drawer, so an existing
     * detail-sized variant is preferred over falling all the way back to
     * the master even when the feed-sized variants are missing.
     *
     * @return list<MediaVariantName>
     */
    private function fallbackChain(PostImageContext $context): array
    {
        return match ($context) {
            PostImageContext::Feed, PostImageContext::Drawer => [
                MediaVariantName::PostFeed640,
                MediaVariantName::PostFeed1280,
                MediaVariantName::PostDetail1920,
            ],
            PostImageContext::Standalone => [
                MediaVariantName::PostFeed1280,
                MediaVariantName::PostDetail1920,
            ],
            PostImageContext::Fullscreen => [
                MediaVariantName::PostDetail1920,
            ],
        };
    }

    /**
     * @return list<MediaVariantName>
     */
    private function srcsetMembership(PostImageContext $context): array
    {
        return match ($context) {
            PostImageContext::Feed, PostImageContext::Drawer => [
                MediaVariantName::PostFeed640,
                MediaVariantName::PostFeed1280,
            ],
            PostImageContext::Standalone => [
                MediaVariantName::PostFeed1280,
                MediaVariantName::PostDetail1920,
            ],
            PostImageContext::Fullscreen => [
                MediaVariantName::PostDetail1920,
            ],
        };
    }
}
