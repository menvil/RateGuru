@props([
    'duration' => 5200,
])

{{--
    FBK-01. The one toast stack of a page: bottom centre of the main column,
    24 from the bottom, at most three on screen, each for 5.2 s. The shell
    draws it on every admin page; anything on the page raises a toast with a
    browser event:

        $this->dispatch('rg-admin-toast', message: 'German enabled', tone: 'success');
        $dispatch('rg-admin-toast', { message: 'German enabled', tone: 'success' })

    tone is success (the default), error or info; duration (ms) is optional.
    A toast waits while the pointer or keyboard focus is on the stack, so
    nobody loses one they are reading or about to dismiss.

    Screen readers hear each message once, from a status region (success and
    info) or an alert region (error) that is always present; the toasts
    themselves are not live regions, so nothing is announced twice. A toast is
    never the only record of a lasting problem: the screen shows that too.

    wire:ignore keeps a Livewire re-render of the component around it from
    clearing the toasts on screen.
--}}
<section
    {{ $attributes->class(['rg-admin', 'rg-admin-toast-stack']) }}
    aria-label="Notifications"
    wire:ignore
    x-data="{
        toasts: [],
        count: 0,
        hovered: false,
        focused: false,
        polite: '',
        assertive: '',
        push(detail) {
            const message = String(detail?.message ?? '').trim()

            if (message === '') {
                return
            }

            const tone = ['success', 'error', 'info'].includes(detail?.tone) ? detail.tone : 'success'
            const toast = { id: ++this.count, tone, message, left: Number(detail?.duration) > 0 ? Number(detail.duration) : {{ (int) $duration }}, since: 0, timer: null }

            this.toasts.slice(0, -2).forEach((old) => clearTimeout(old.timer))
            this.toasts = [...this.toasts.slice(-2), toast]
            this.announce(toast)

            if (! this.hovered && ! this.focused) {
                this.start(toast)
            }
        },
        announce(toast) {
            const region = toast.tone === 'error' ? 'assertive' : 'polite'

            // Cleared first, and written in a later task than the clearing, so
            // the same message twice in a row is heard twice.
            this[region] = ''
            setTimeout(() => this[region] = toast.message, 50)
        },
        remove(id) {
            clearTimeout(this.toasts.find((toast) => toast.id === id)?.timer)
            this.toasts = this.toasts.filter((toast) => toast.id !== id)
            this.$nextTick(() => this.hold(this.hovered, this.$root.contains(document.activeElement)))
        },
        hold(hovered, focused) {
            const held = this.hovered || this.focused

            this.hovered = hovered
            this.focused = focused

            if ((hovered || focused) !== held) {
                this.toasts.forEach((toast) => (hovered || focused) ? this.stop(toast) : this.start(toast))
            }
        },
        start(toast) {
            toast.since = Date.now()
            toast.timer = setTimeout(() => this.remove(toast.id), toast.left)
        },
        stop(toast) {
            clearTimeout(toast.timer)
            toast.left = Math.max(0, toast.left - (Date.now() - toast.since))
        },
    }"
    x-on:rg-admin-toast.window="push($event.detail)"
    x-on:mouseenter="hold(true, focused)"
    x-on:mouseleave="hold(false, focused)"
    x-on:focusin="hold(hovered, true)"
    x-on:focusout="hold(hovered, $root.contains($event.relatedTarget))"
>
    <p class="rg-admin-sr-only" role="status" x-text="polite"></p>
    <p class="rg-admin-sr-only" role="alert" x-text="assertive"></p>

    <ol class="rg-admin-toast-stack__list">
        <template x-for="toast in toasts" x-bind:key="toast.id">
            <li class="rg-admin-toast" x-bind:data-tone="toast.tone">
                <x-admin.ui.icon name="check" class="rg-admin-toast__icon--success" label="Success" x-show="toast.tone === 'success'" />
                <x-admin.ui.icon name="circle-alert" class="rg-admin-toast__icon--error" label="Error" x-show="toast.tone === 'error'" />
                <x-admin.ui.icon name="info" class="rg-admin-toast__icon--info" label="Info" x-show="toast.tone === 'info'" />
                <span class="rg-admin-toast__text" x-text="toast.message"></span>
                <button type="button" class="rg-admin-toast__close" aria-label="Dismiss" title="Dismiss" x-on:click="remove(toast.id)">
                    <x-admin.ui.icon name="x" :size="14" />
                </button>
            </li>
        </template>
    </ol>
</section>
