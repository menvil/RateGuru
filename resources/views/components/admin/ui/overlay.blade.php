{{--
    The layer under the confirmation dialog (OVL-01) and the drawer (OVL-02):
    what both do the same way, so neither is used on its own.

    It is open for as long as it is rendered. dismiss() — Escape, the scrim,
    the close button, a Cancel — hides it at once and dispatches `dismiss` for
    the screen to forget it; hide() hides it without a word, for an action that
    replaces it with something else. The focus trap on the panel inside is
    bound to `closing`, so it lets go the moment the layer hides.

    The trap returns focus to whatever opened the overlay. When that control
    has left the page by then — its row filtered away by the change it
    confirmed — focus goes to the page's heading rather than nowhere.
--}}
<div
    {{ $attributes->class(['rg-admin']) }}
    x-data="{
        closing: false,
        opener: document.activeElement,
        hide() {
            this.closing = true
        },
        dismiss() {
            if (this.closing) {
                return
            }

            this.hide()
            this.$dispatch('dismiss')
        },
        destroy() {
            const opener = this.opener

            // Once the trap has returned focus, or found nothing to return it to.
            setTimeout(() => {
                if (opener?.isConnected || (document.activeElement && document.activeElement !== document.body)) {
                    return
                }

                const heading = document.querySelector('main h1') ?? document.querySelector('h1')

                heading?.setAttribute('tabindex', '-1')
                heading?.focus()
            }, 50)
        },
    }"
    x-show="! closing"
    x-on:keydown.escape.prevent.stop="dismiss()"
>
    {{ $slot }}
</div>
