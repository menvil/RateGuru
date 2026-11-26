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
    @php
        // Nothing plays until asked, and no video is fetched before: an eager
        // one (the first in a feed, the post page's) has its poster from the
        // start, any other gets it from rgPostVideo once it comes near the
        // screen. The file is set when it is played — or, where the browser's
        // own controls start it (the post page, the viewer), up front with
        // preload="none", so they have something to play and fetch nothing yet.
        $videoPoster = $video->poster ?? $imageUrl;
        $eagerVideo = $loading === null;
        $withControls = in_array($context, ['standalone', 'fullscreen'], true);
    @endphp
    {{-- Muted until asked: a feed must never start making noise on its own. Keyed by
         its post and file, so a drawer that shows another post gets a new player rather
         than morphing this one — whose source was set once, for the post before. --}}
    <div
        wire:key="post-video-{{ $context }}-{{ $post?->id }}-{{ substr(md5($video->src), 0, 12) }}"
        {{ $attributes->class([$containerClasses]) }}
        x-data="rgPostVideo({ src: @js($video->src), poster: @js($videoPoster), controls: @js($withControls) })"
        x-on:rg-video-sound.window="if ($event.detail !== $root) quiet()"
        x-on:rg-video-playing.window="if ($event.detail !== $root) pause()"
        data-testid="post-video"
    >
        {{-- As wide as the picture, so its buttons sit on it, not beside a tall clip. --}}
        <div class="relative inline-flex max-w-full">
        <video
            x-ref="video"
            @if($eagerVideo && $withControls) src="{{ $video->src }}" @endif
            @if($eagerVideo && $videoPoster) poster="{{ $videoPoster }}" @endif
            @if($video->width) width="{{ $video->width }}" @endif
            @if($video->height) height="{{ $video->height }}" @endif
            muted
            playsinline
            loop
            preload="none"
            @if($withControls)
                controls
            @else
                tabindex="0"
                x-on:click.stop="toggle()"
                x-on:keydown.enter.prevent.stop="toggle()"
                x-on:keydown.space.prevent.stop="toggle()"
            @endif
            aria-label="{{ $altText }}"
            @if($imageTestid) data-testid="{{ $imageTestid }}" @endif
            class="{{ $imageClasses }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
        ></video>

        @unless($withControls)
            {{-- Started only by this, or a tap on the video: never by itself. --}}
            <button
                type="button"
                x-show="! playing"
                x-on:click.stop="toggle()"
                aria-label="{{ __('ui.post.video_play') }}"
                data-testid="post-video-play"
                class="absolute inset-0 m-auto grid size-14 place-items-center rg-post-video__control rounded-rgPill backdrop-blur-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="play" class="size-6" />
            </button>
        @endunless

        @if($video->hasAudio && ! $withControls)
            <button
                type="button"
                x-on:click.stop="toggleSound()"
                x-bind:aria-label="muted ? @js(__('ui.post.video_sound_on')) : @js(__('ui.post.video_sound_off'))"
                aria-label="{{ __('ui.post.video_sound_on') }}"
                data-testid="post-video-sound"
                class="absolute bottom-2 end-2 grid size-10 place-items-center rg-post-video__control rounded-rgPill backdrop-blur-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
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
                class="absolute top-2 end-2 grid size-10 place-items-center rg-post-video__control rounded-rgPill backdrop-blur-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="expand" class="size-4" />
            </button>
        @endif
        </div>
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
