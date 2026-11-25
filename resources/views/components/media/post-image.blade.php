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
    // A PostVideo: the post plays it in place of the picture, which becomes
    // its poster.
    'video' => null,
])

@php
    $imageUrl = $src ?? $post?->public_image_url;
    $altText = $alt ?? (filled($post?->title) ? $post->title : __('ui.post.image_alt_fallback'));

    $maxHeightClass = match ($context) {
        // The viewer's dialog measures the room between the app header and the
        // bottom of the screen (--rg-modal-height) and its own chrome — title
        // row, padding, border. Until it has, the old 80vh cap applies alone.
        'fullscreen' => 'max-h-[min(80vh,calc(var(--rg-modal-height,100dvh)-3rem-var(--rg-modal-chrome,5.75rem)))]',
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

@if($video)
    {{-- Muted until asked: a feed must never start making noise on its own. It plays while
         most of it is on screen and pauses when scrolled away (rgPostVideo); with reduced
         motion it waits for a tap. The post page and the viewer add the browser's controls. --}}
    <div
        {{ $attributes->class([$containerClasses, 'relative']) }}
        x-data="rgPostVideo()"
        x-on:rg-video-sound.window="if ($event.detail !== $root) quiet()"
        data-testid="post-video"
    >
        <video
            x-ref="video"
            src="{{ $video->src }}"
            @if($video->poster ?? $imageUrl) poster="{{ $video->poster ?? $imageUrl }}" @endif
            @if($video->width) width="{{ $video->width }}" @endif
            @if($video->height) height="{{ $video->height }}" @endif
            muted
            playsinline
            loop
            preload="{{ $loading === null ? 'auto' : 'metadata' }}"
            @if(in_array($context, ['standalone', 'fullscreen'], true)) controls @else x-on:click.stop="toggle()" @endif
            aria-label="{{ $altText }}"
            @if($imageTestid) data-testid="{{ $imageTestid }}" @endif
            class="{{ $imageClasses }}"
        ></video>

        @if($video->hasAudio && ! in_array($context, ['standalone', 'fullscreen'], true))
            <button
                type="button"
                x-on:click.stop="toggleSound()"
                x-bind:aria-pressed="(! muted).toString()"
                x-bind:aria-label="muted ? @js(__('ui.post.video_sound_on')) : @js(__('ui.post.video_sound_off'))"
                aria-label="{{ __('ui.post.video_sound_on') }}"
                data-testid="post-video-sound"
                class="absolute bottom-2 end-2 grid size-9 place-items-center rounded-rgPill bg-black/55 text-white backdrop-blur-sm transition hover:bg-black/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="volume-off" class="size-4" x-show="muted" />
                <x-ui.icon name="volume" class="size-4" x-show="! muted" x-cloak />
            </button>
        @endif

        @if($openFullscreen)
            <button
                type="button"
                x-on:click.stop="pause(); {{ $openFullscreen }}"
                @if($testid) data-testid="{{ $testid }}" @endif
                aria-label="{{ __('ui.post.video_open') }}"
                class="absolute top-2 end-2 grid size-9 place-items-center rounded-rgPill bg-black/55 text-white backdrop-blur-sm transition hover:bg-black/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="expand" class="size-4" />
            </button>
        @endif
    </div>
@elseif($imageUrl)
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
