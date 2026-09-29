// Alpine.js component data for the global authentication modal
// (resources/views/components/auth/modal.blade.php).
window.rgAuthModal = function ({ open = false, mode = 'login' } = {}) {
    const normalize = (value) => (value === 'register' ? 'register' : 'login');

    return {
        open: Boolean(open),
        mode: normalize(mode),

        init() {
            // Opened by the server: a failed submission or a provider round
            // trip brought the person back to this page.
            if (this.open) {
                this.syncReturnPath();
                this.focusFirstField();
            }
        },

        // Opens the dialog, or switches the state of the one already open.
        show(detail) {
            this.mode = normalize(detail?.mode);
            this.syncReturnPath();
            this.open = true;
            this.focusFirstField();
        },

        // The page the person is on right now. Livewire may have changed the
        // address since the server rendered the dialog, so every form and
        // provider link is pointed at the current one. The server still
        // vets the value before following it.
        syncReturnPath() {
            const path = window.location.pathname + window.location.search;

            this.$root.querySelectorAll('[data-auth-return-input]').forEach((input) => {
                input.value = path;
            });

            this.$root.querySelectorAll('a[data-auth-return-link]').forEach((link) => {
                const url = new URL(link.getAttribute('href'), window.location.origin);

                url.searchParams.set('_auth_return_to', path);
                link.setAttribute('href', url.toString());
            });
        },

        // After the focus trap has claimed the dialog, so the first field of
        // the visible form — not the close button — is where typing starts.
        // How long the trap and the panel take depends on the machine, so the
        // field is asked again until it really has the focus, for up to a
        // second, instead of once after a guessed delay.
        //
        // It only ever moves the focus INTO the form, never around inside it:
        // once the person is in any field of the visible form — they clicked
        // one, or a password manager filled one — the focus is theirs. Taking
        // it back would send what they type next into the first field.
        focusFirstField() {
            const mode = this.mode;

            const focus = (attemptsLeft) => {
                // The person moved on: closed the dialog or switched its state.
                if (! this.open || this.mode !== mode) {
                    return;
                }

                const panel = this.$root.querySelector(`[data-auth-panel="${mode}"]`);

                if (! panel || panel.contains(document.activeElement)) {
                    return;
                }

                const field = panel.querySelector('[data-auth-initial-focus]');

                field?.focus();

                if (field && document.activeElement !== field && attemptsLeft > 0) {
                    window.setTimeout(() => focus(attemptsLeft - 1), 50);
                }
            };

            this.$nextTick(() => window.setTimeout(() => focus(20), 50));
        },
    };
};

// Opens the authentication dialog from anywhere: a header link, the guest
// upload button, any later guest action. Callers only name the mode.
//
// A modified click (new tab, new window) is left to the browser, and a page
// without a dialog falls back to the standalone page — the link's own href,
// or the URL a button passes in.
window.rgOpenAuthModal = function (event, mode, fallbackUrl = null) {
    if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
        return;
    }

    if (! document.querySelector('[data-auth-modal]')) {
        if (fallbackUrl) {
            window.location.assign(fallbackUrl);
        }

        return;
    }

    event?.preventDefault();
    window.dispatchEvent(new CustomEvent('open-auth-modal', { detail: { mode } }));
};
