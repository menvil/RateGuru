@props([
    'post' => null,
    'src' => null,
    'srcset' => null,
    'sizes' => null,
    'width' => null,
    'height' => null,
    'alt' => null,
    'context' => 'feed',
    'openFullscreen' => null,
    'testid' => null,
    'imageTestid' => null,
    'loading' => null,
])

@php
    $imageUrl = $src ?? $post?->public_image_url;
    $altText = $alt ?? (filled($post?->title) ? $post->title : __('ui.post.image_alt_fallback'));

    $maxHeightClass = match ($context) {
        'fullscreen' => 'max-h-[80vh]',
        'drawer' => 'max-h-[70vh]',
        'standalone' => 'max-h-[75vh]',
        default => 'max-h-[75vh]',
    };

    $containerClasses = $context === 'fullscreen'
        ? 'flex w-full items-center justify-center'
        : 'flex w-full items-center justify-center overflow-hidden rounded-rgMedia bg-rg-card2';

    $imageClasses = "block h-auto w-auto max-w-full {$maxHeightClass} rounded-rgMedia object-contain mx-auto";

    // A null $loading (eager) always means this is the one prioritized image
    // for its view — the feed/saved-posts/profile loops' first card
    // (:eager-image="$loop->first") or the standalone hero (which never
    // passes $loading at all). Every other call site passes loading="lazy"
    // explicitly, including every fullscreen instance (hidden in a closed
    // modal until opened) — so this never fires for those.
    $fetchPriority = $loading === null ? 'high' : null;
@endphp

@if($imageUrl)
    <div {{ $attributes->class([$containerClasses]) }}>
        @if($openFullscreen)
            <button
                type="button"
                class="cursor-zoom-in rounded-rgMedia focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                x-on:click.stop="{{ $openFullscreen }}"
                @if($testid) data-testid="{{ $testid }}" @endif
                aria-label="{{ __('ui.a11y.open_image') }}"
            >
                <img
                    src="{{ $imageUrl }}"
                    @if($srcset) srcset="{{ $srcset }}" @endif
                    @if($sizes) sizes="{{ $sizes }}" @endif
                    @if($width) width="{{ $width }}" @endif
                    @if($height) height="{{ $height }}" @endif
                    alt="{{ $altText }}"
                    decoding="async"
                    @if($loading) loading="{{ $loading }}" @endif
                    @if($fetchPriority) fetchpriority="{{ $fetchPriority }}" @endif
                    @if($imageTestid) data-testid="{{ $imageTestid }}" @endif
                    class="{{ $imageClasses }}"
                >
            </button>
        @else
            <img
                src="{{ $imageUrl }}"
                @if($srcset) srcset="{{ $srcset }}" @endif
                @if($sizes) sizes="{{ $sizes }}" @endif
                @if($width) width="{{ $width }}" @endif
                @if($height) height="{{ $height }}" @endif
                alt="{{ $altText }}"
                decoding="async"
                @if($loading) loading="{{ $loading }}" @endif
                @if($fetchPriority) fetchpriority="{{ $fetchPriority }}" @endif
                @if($imageTestid) data-testid="{{ $imageTestid }}" @endif
                class="{{ $imageClasses }}"
            >
        @endif
    </div>
@endif
