// Alpine.js component data for a post's video or animation (x-media.post-image
// with a video). It plays muted while most of it is on screen and pauses when
// it scrolls away or its viewer closes; with reduced motion, or where the page
// cannot tell what is on screen, it waits for a tap. Turning one video's sound
// on turns every other one's off.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rgPostVideo', () => ({
        muted: true,
        observer: null,

        init() {
            const video = this.$refs.video;
            video.muted = true;

            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (reduceMotion || !('IntersectionObserver' in window)) {
                return;
            }

            this.observer = new IntersectionObserver(([entry]) => {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    video.play().catch(() => {});
                } else if (!video.paused) {
                    video.pause();
                }
            }, { threshold: [0, 0.6] });

            this.observer.observe(video);
        },

        destroy() {
            this.observer?.disconnect();
        },

        toggle() {
            const video = this.$refs.video;

            video.paused ? video.play().catch(() => {}) : video.pause();
        },

        toggleSound() {
            const video = this.$refs.video;

            video.muted = !video.muted;
            this.muted = video.muted;

            if (!video.muted) {
                window.dispatchEvent(new CustomEvent('rg-video-sound', { detail: this.$root }));

                if (video.paused) video.play().catch(() => {});
            }
        },

        quiet() {
            this.$refs.video.muted = true;
            this.muted = true;
        },

        pause() {
            this.$refs.video.pause();
        },
    }));
});
