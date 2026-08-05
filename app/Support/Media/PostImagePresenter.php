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
 * itself never touches the imageAsset relation. responsive() reads the
 * already-loaded `variants` relation only — no query is issued here, so
 * callers must eager-load `imageAsset.variants`.
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

        $variants = $asset->variants->keyBy(fn (MediaVariant $variant): string => $variant->name->value);

        $chosen = $this->firstExisting($variants, $this->fallbackChain($context));
        $masterUrl = $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);

        if ($chosen === null) {
            return $masterUrl === null ? null : new ResponsiveImage($masterUrl, null, null, $asset->width, $asset->height);
        }

        $srcUrl = $this->resolver->publicUrl(new MediaLocation($chosen->disk, $chosen->path), $asset->visibility);

        $srcsetEntries = $this->srcsetEntries($variants, $this->srcsetMembership($context), $asset->visibility);

        if ($context === PostImageContext::Fullscreen) {
            $detail = $variants->get(MediaVariantName::PostDetail1920->value);

            if ($detail !== null && $masterUrl !== null && $asset->width <= $detail->width * self::FULLSCREEN_MASTER_INCLUDE_RATIO) {
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
