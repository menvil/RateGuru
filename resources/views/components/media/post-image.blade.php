@props([
    'post' => null,
    'src' => null,
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

    // No width/height metadata is stored for post images (out of scope for
    // this pass — see the media-rendering task notes), so the exact box size
    // can't be reserved before the image loads. A modest min-height keeps
    // lazy-loaded images from collapsing to ~0px and then jumping to full
    // size; it's a heuristic, not a guarantee of zero layout shift.
    $minHeightClass = match ($context) {
        'fullscreen' => '',
        'drawer' => 'min-h-[16vh]',
        default => 'min-h-[20vh]',
    };

    $containerClasses = $context === 'fullscreen'
        ? 'flex w-full items-center justify-center'
        : trim("flex w-full items-center justify-center overflow-hidden rounded-rgMedia bg-rg-card2 {$minHeightClass}");

    $imageClasses = "block h-auto w-auto max-w-full {$maxHeightClass} rounded-rgMedia object-contain mx-auto";
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
                    alt="{{ $altText }}"
                    decoding="async"
                    @if($loading) loading="{{ $loading }}" @endif
                    @if($imageTestid) data-testid="{{ $imageTestid }}" @endif
                    class="{{ $imageClasses }}"
                >
            </button>
        @else
            <img
                src="{{ $imageUrl }}"
                alt="{{ $altText }}"
                decoding="async"
                @if($loading) loading="{{ $loading }}" @endif
                @if($imageTestid) data-testid="{{ $imageTestid }}" @endif
                class="{{ $imageClasses }}"
            >
        @endif
    </div>
@endif
