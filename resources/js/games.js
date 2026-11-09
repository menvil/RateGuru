// A game round on the page (resources/views/livewire/games/steps/game.blade.php).
// A pick is marked the moment it is made and sent to the server, which judges
// it; once revealed, the Next button fills like a timer and moves on by itself
// when the round is set to, or waits to be pressed.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rgGameRound', ({ revealed, auto, seconds }) => ({
        picked: null,
        progress: 0,
        frame: null,

        init() {
            if (! revealed || ! auto) {
                return;
            }

            const started = performance.now();
            const duration = Math.max(0.5, seconds) * 1000;
            const tick = (now) => {
                this.progress = Math.min(100, ((now - started) / duration) * 100);

                if (this.progress >= 100) {
                    this.next();

                    return;
                }

                this.frame = requestAnimationFrame(tick);
            };

            this.frame = requestAnimationFrame(tick);
        },

        destroy() {
            if (this.frame !== null) {
                cancelAnimationFrame(this.frame);
            }
        },

        choose(position) {
            if (revealed || this.picked !== null) {
                return;
            }

            this.picked = position;
            this.$wire.choose(position);
        },

        next() {
            if (this.frame !== null) {
                cancelAnimationFrame(this.frame);
                this.frame = null;
            }

            this.$wire.next();
        },
    }));
});
