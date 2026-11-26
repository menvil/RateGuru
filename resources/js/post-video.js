// Alpine.js component data for a post's video or animation (x-media.post-image
// with a video).
//
// - Like a lazily loaded picture, a video not yet near the screen loads
//   nothing: its file and poster are set once it comes close (an eager one —
//   the first in a feed, the post page's — has them from the start). The
//   fullscreen viewer's stays empty until the viewer opens.
// - It plays muted while most of it is on screen and pauses when it scrolls
//   away or its viewer closes; with reduced motion, or where the page cannot
//   tell what is on screen, it waits for a tap or a key to start — and with
//   reduced motion still pauses once scrolled away.
// - One plays at a time: one starting pauses the rest, so a card's video stops
//   under the drawer or viewer that opened over it.
// - Its sound comes on only when asked — by its button or the browser's own
//   controls — and then every other one's goes off.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rgPostVideo', ({ src, poster }) => ({
        muted: true,
        loader: null,
        player: null,

        init() {
            const video = this.$refs.video;
            video.muted = true;

            video.addEventListener('play', () => {
                // Two that start together each hear the other: the one already
                // paused by the first to speak stays quiet, or both would stop.
                if (video.paused) return;

                window.dispatchEvent(new CustomEvent('rg-video-playing', { detail: this.$root }));
            });

            video.addEventListener('volumechange', () => {
                this.muted = video.muted;

                if (!video.muted) {
                    window.dispatchEvent(new CustomEvent('rg-video-sound', { detail: this.$root }));
                }
            });

            if (!('IntersectionObserver' in window)) {
                this.load();

                return;
            }

            this.loader = new IntersectionObserver(([entry]) => {
                if (entry.isIntersecting) {
                    this.load();
                    this.loader.disconnect();
                }
            }, { rootMargin: '600px 0px' });
            this.loader.observe(video);

            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            this.player = new IntersectionObserver(([entry]) => {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    // Started only by a tap or a key with reduced motion.
                    if (reduceMotion) return;

                    this.load();
                    video.play().catch(() => {});
                } else if (!video.paused) {
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

        // Sets the file and the poster the first time only.
        load() {
            const video = this.$refs.video;

            if (video.getAttribute('src')) return;

            if (poster) video.poster = poster;
            video.src = src;
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
