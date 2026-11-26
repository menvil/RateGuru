// Alpine.js component data for a post's video or animation (x-media.post-image
// with a video).
//
// - Nothing plays until asked — its play button, a tap or a key, or the
//   browser's own controls — so a feed never starts many videos, nor fetches
//   them, by itself.
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
    window.Alpine.data('rgPostVideo', ({ src, poster, controls = false }) => ({
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

            // Never playing, nor sounding, out of sight.
            this.player = new IntersectionObserver(([entry]) => {
                if (!entry.isIntersecting && !video.paused) video.pause();
            }, { threshold: [0] });
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
            if (controls) this.load();
        },

        // Sets the file, and the poster, the first time only: when it is played.
        load() {
            const video = this.$refs.video;

            if (poster && !video.getAttribute('poster')) video.poster = poster;
            if (video.getAttribute('src')) return;

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
