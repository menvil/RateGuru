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

    // A game's headline (x-games.headline) sized to its column: up to 80
    // characters on one line, more on two — as large as fills the column's
    // width, from 18px up to the size's own ceiling (--rg-game-line-cap). The
    // text is measured on one line at 100px and scaled to the width it has;
    // two lines get twice the width, and come down a step at a time while
    // they still break onto a third. Below 18px it wraps as it will. The size
    // CSS guesses from its length stands until then.
    window.Alpine.data('rgGameLine', () => ({
        init() {
            this.fit();
            document.fonts?.ready.then(() => this.fit());
        },

        fit() {
            const el = this.$el;
            const lines = Number(el.dataset.lines ?? 1) > 1 ? 2 : 1;
            const cap = parseFloat(getComputedStyle(el).getPropertyValue('--rg-game-line-cap')) || 64;

            el.style.whiteSpace = 'nowrap';
            el.style.fontSize = '100px';

            const width = el.clientWidth;
            const natural = el.scrollWidth;

            if (width <= 0 || natural <= 0) {
                return;
            }

            // Two lines never break evenly: leave room for the longer one.
            let size = Math.min(cap, Math.floor((100 * width * lines * (lines > 1 ? 0.9 : 0.98)) / natural));

            el.style.whiteSpace = lines > 1 ? 'normal' : 'nowrap';
            el.style.fontSize = `${Math.max(18, size)}px`;

            if (lines > 1) {
                const lineHeight = () => parseFloat(getComputedStyle(el).lineHeight) || size * 1.1;

                while (size > 18 && el.clientHeight > lineHeight() * 2.5) {
                    size -= 1;
                    el.style.fontSize = `${size}px`;
                }
            }

            if (size < 18) {
                el.style.whiteSpace = 'normal';
            }
        },
    }));
});
