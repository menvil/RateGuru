// The game page's own behaviour (resources/views/livewire/games/steps).
//
// A game round on the page (game.blade.php).
// A pick is marked the moment it is made and sent to the server with the round
// on screen, which judges it; once revealed, the Next button fills like a timer
// and moves on by itself when the round is set to, or waits to be pressed.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rgGameRound', ({ round, revealed, auto, seconds }) => ({
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

            // A pick that brings no reveal back — refused, held by the rate
            // limit, or failed — leaves the round open to another tap. A reveal
            // draws the round anew under another key, so this one is gone.
            const wire = this.$wire;
            const reopen = () => {
                if (wire.reveal === null) {
                    this.picked = null;
                }
            };

            wire.choose(position, round).then(reopen, reopen);
        },

        next() {
            if (this.frame !== null) {
                cancelAnimationFrame(this.frame);
                this.frame = null;
            }

            this.$wire.next();
        },
    }));

    // A headline kept to one line, as large as the title where it fits — the
    // Result Preview teaser (result-preview.blade.php). Measured at the
    // title's size and scaled down to the column's width, never below 18px; a
    // line too long even then wraps. The size CSS guesses from its length
    // stands until then.
    window.Alpine.data('rgGameLine', () => ({
        init() {
            this.fit();
            document.fonts?.ready.then(() => this.fit());
        },

        fit() {
            const el = this.$el;

            el.style.whiteSpace = 'nowrap';
            el.style.fontSize = 'var(--rg-game-line-max)';

            const size = parseFloat(getComputedStyle(el).fontSize);
            const ratio = el.clientWidth / el.scrollWidth;

            if (ratio >= 1) {
                return;
            }

            const fitted = Math.floor(size * ratio * 0.98);

            el.style.fontSize = `${Math.max(18, fitted)}px`;

            if (fitted < 18) {
                el.style.whiteSpace = '';
            }
        },
    }));
});
