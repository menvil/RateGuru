<?php

namespace App\Support\Media;

use App\Enums\MediaVariantName;
use App\Models\MediaVariant;
use App\Models\User;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaUrlResolver;

/**
 * The only thing that knows a user's avatar comes from avatarAsset — callers
 * never touch disk/path/Storage themselves. Reads the asset's own
 * disk/path/visibility and hands the resolver plain data — the resolver
 * itself never touches the avatarAsset relation. Returns null when there is
 * no avatar; rendering a fallback (initials, generated avatar, etc.) is a
 * presentation-layer concern this class deliberately knows nothing about —
 * see resources/views/components/ui/avatar.blade.php. responsive() never
 * lazy-loads `variants`: when a caller hasn't eager-loaded
 * `avatarAsset.variants`, it falls back to the master-image DTO instead of
 * issuing a query.
 */
final class AvatarUrlResolver
{
    public function __construct(
        private readonly MediaUrlResolver $resolver,
    ) {}

    public function url(User $user): ?string
    {
        $asset = $user->avatarAsset;

        if ($asset === null) {
            return null;
        }

        return $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);
    }

    /**
     * Avatars aren't laid out responsively the way post images are — a
     * single `sizes` string covering every call site would be a guess, and
     * both candidate widths (128/256) are small enough that a default
     * `100vw` selection picks at most the 256w candidate anyway. `sizes` is
     * therefore always null here, unlike PostImagePresenter::responsive().
     */
    public function responsive(User $user): ?ResponsiveImage
    {
        $asset = $user->avatarAsset;

        if ($asset === null) {
            return null;
        }

        $masterUrl = $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);

        // Never lazy-load: a caller that forgot to eager-load
        // avatarAsset.variants gets the master-fallback DTO instead of an
        // N+1 query per user.
        if (! $asset->relationLoaded('variants')) {
            return $masterUrl === null ? null : new ResponsiveImage($masterUrl, null, null, $asset->width, $asset->height);
        }

        $variants = $asset->variants->keyBy(fn (MediaVariant $variant): string => $variant->name->value);

        $small = $variants->get(MediaVariantName::Avatar128->value);
        $large = $variants->get(MediaVariantName::Avatar256->value);

        $chosen = $small ?? $large;

        if ($chosen === null) {
            return $masterUrl === null ? null : new ResponsiveImage($masterUrl, null, null, $asset->width, $asset->height);
        }

        $srcUrl = $this->resolver->publicUrl(new MediaLocation($chosen->disk, $chosen->path), $asset->visibility);

        $srcsetEntries = [];

        foreach ([$small, $large] as $variant) {
            if ($variant !== null) {
                $url = $this->resolver->publicUrl(new MediaLocation($variant->disk, $variant->path), $asset->visibility);
                $srcsetEntries[] = "{$url} {$variant->width}w";
            }
        }

        return new ResponsiveImage(
            src: $srcUrl,
            srcset: $srcsetEntries === [] ? null : implode(', ', $srcsetEntries),
            sizes: null,
            width: $chosen->width,
            height: $chosen->height,
        );
    }
}
