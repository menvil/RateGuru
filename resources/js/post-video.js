// Alpine.js component data for a post's video or animation (x-media.post-image
// with a video).
//
// - Nothing plays until asked — its play button, a tap or a key, or the
//   browser's own controls — so a feed never starts many videos, nor fetches
//   them, by itself. Unless Project settings say a feed's videos start by
//   themselves (autoplay_feed_videos): then one plays muted while most of it
//   is on screen — with reduced motion it still waits to be asked — and its
//   file is fetched as it comes near.
// - Like a lazily loaded picture, a video not yet near the screen loads
//   nothing: its poster is set once it comes close (an eager one — the first
//   in a feed, the post page's — has it from the start), its file only when it
//   is played. Where the browser's own controls start it, the file is set
//   with the poster, under preload="none", so they have something to play.
// - It pauses when it scrolls away or its viewer closes.
// - One plays at a time: one starting pauses the rest, so a card's video stops
//   under the drawer or viewer that opened over it.
// - Its sound comes on only when asked — by its button or the browser's own
//   controls — and then every other one's goes off.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rgPostVideo', ({ src, poster, controls = false, autoplay = false }) => ({
        muted: true,
        playing: false,
        loader: null,
        player: null,

        init() {
            const video = this.$refs.video;
            video.muted = true;

            video.addEventListener('play', () => {
                this.playing = true;

                // Two that start together each hear the other: the one already
                // paused by the first to speak stays quiet, or both would stop.
                if (video.paused) return;

                window.dispatchEvent(new CustomEvent('rg-video-playing', { detail: this.$root }));
            });

            video.addEventListener('pause', () => {
                this.playing = false;
            });

            video.addEventListener('volumechange', () => {
                this.muted = video.muted;

                if (!video.muted) {
                    window.dispatchEvent(new CustomEvent('rg-video-sound', { detail: this.$root }));
                }
            });

            if (!('IntersectionObserver' in window)) {
                this.prepare();

                return;
            }

            this.loader = new IntersectionObserver(([entry]) => {
                if (entry.isIntersecting) {
                    this.prepare();
                    this.loader.disconnect();
                }
            }, { rootMargin: '600px 0px' });
            this.loader.observe(video);

            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const starts = autoplay && !reduceMotion;

            this.player = new IntersectionObserver(([entry]) => {
                if (starts && entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    // A start of its own is a silent one: a video the visitor
                    // gave sound, then scrolled away from, comes back muted. One
                    // still playing with its sound keeps it.
                    if (video.paused) video.muted = true;
                    this.load();
                    video.play().catch(() => {});
                } else if (!entry.isIntersecting && !video.paused) {
                    // Never playing, nor sounding, out of sight.
                    video.pause();
                }
            }, { threshold: [0, 0.6] });
            this.player.observe(video);
        },

        destroy() {
            this.loader?.disconnect();
            this.player?.disconnect();
        },

        // Near the screen: its poster, and its file only where the browser's
        // own controls are to start it (preload="none" keeps that from
        // fetching anything yet).
        prepare() {
            const video = this.$refs.video;

            if (poster && !video.getAttribute('poster')) video.poster = poster;
            // Ready to play: the browser's controls start it, or it starts by
            // itself — and then it is buffered ahead, not merely named.
            if (autoplay) video.preload = 'auto';
            if (controls || autoplay) this.load();
        },

        // Sets the file, and the poster, the first time only: when it is played.
        load() {
            const video = this.$refs.video;

            if (poster && !video.getAttribute('poster')) video.poster = poster;
            if (video.getAttribute('src')) return;

            video.src = src;
        },

        // From its play button, which hides once it plays: a keyboard user's
        // focus moves on to the video, whose Enter and Space pause it.
        playFrom(button) {
            const focused = document.activeElement === button;

            this.toggle();

            if (focused) this.$refs.video.focus();
        },

        toggle() {
            const video = this.$refs.video;

            this.load();
            video.paused ? video.play().catch(() => {}) : video.pause();
        },

        toggleSound() {
            const video = this.$refs.video;

            this.load();
            video.muted = !video.muted;

            if (!video.muted && video.paused) video.play().catch(() => {});
        },

        quiet() {
            this.$refs.video.muted = true;
        },

        pause() {
            this.$refs.video.pause();
        },
    }));
});
