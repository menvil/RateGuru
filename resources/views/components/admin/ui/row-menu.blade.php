@props([
    'id',
    'label' => 'More actions',
])

{{--
    TBL-04. A row's ellipsis menu: everything the row offers beyond its one or
    two inline actions. The trigger is a ghost sm icon button named by
    `label` (“More actions for Dogs”); the menu is 264 wide, right-aligned
    under it, and flips above it when the window has no room below.

    The default slot holds the items, x-admin.ui.menu-item, with a
    `.rg-admin-menu__separator` (role="separator") before destructive ones.
    An unavailable item stays in the menu with its reason as the hint.

    A menu button: Enter, Space or Down opens it on the first item, Up on the
    last; Up, Down, Home and End move; Escape closes it back to the trigger.
    Choosing an item, Tab, a click outside, a scroll that moves its button or
    resizing the window closes it. The menu is fixed to the window rather than
    the row, so a table card that scrolls sideways never clips it.
--}}
<div
    {{ $attributes->class(['rg-admin-row-menu']) }}
    x-data="{
        expanded: false,
        items() {
            return [...this.$refs.menu.querySelectorAll('[role=menuitem]')]
        },
        show(last = false) {
            this.expanded = true
            // A scroll closes it only once its button has moved: the scroll that brought the
            // button into view is reported after it was pressed, and moves nothing.
            this.onScroll = () => this.position() !== this.anchor && this.close(false)
            this.onResize = () => this.close(false)
            window.addEventListener('scroll', this.onScroll, true)
            window.addEventListener('resize', this.onResize)

            // Shown, placed and focused at once, before anything is painted. Not x-show, which
            // shows on the next frame: the menu has to be on screen to be measured and focused.
            // Focus never scrolls: the menu is fixed, but the card that scrolls sideways is its
            // ancestor.
            this.$refs.menu.hidden = false
            this.place()
            const items = this.items()
            ;(last ? items.at(-1) : items[0])?.focus({ preventScroll: true })
        },
        position() {
            const box = this.$refs.trigger.getBoundingClientRect()

            return `${Math.round(box.top)} ${Math.round(box.right)}`
        },
        place() {
            const box = this.$refs.trigger.getBoundingClientRect()
            const menu = this.$refs.menu
            const height = menu.offsetHeight
            const below = box.bottom + 6 + height <= window.innerHeight - 8 || box.top - 6 - height < 8

            menu.style.top = below ? `${Math.round(box.bottom + 6)}px` : 'auto'
            menu.style.bottom = below ? 'auto' : `${Math.round(window.innerHeight - box.top + 6)}px`
            menu.style.right = `${Math.max(8, Math.round(window.innerWidth - box.right))}px`
            this.anchor = this.position()
        },
        close(returnFocus) {
            if (! this.expanded) {
                return
            }

            this.expanded = false
            this.$refs.menu.hidden = true
            window.removeEventListener('scroll', this.onScroll, true)
            window.removeEventListener('resize', this.onResize)

            if (returnFocus) {
                this.$refs.trigger.focus()
            }
        },
        step(by) {
            const items = this.items()
            const at = items.indexOf(document.activeElement)

            items[(at + by + items.length) % items.length]?.focus({ preventScroll: true })
        },
        chose(event) {
            const item = event.target.closest('[role=menuitem]')

            if (item && item.getAttribute('aria-disabled') !== 'true') {
                this.close(true)
            }
        },
        destroy() {
            this.close(false)
        },
    }"
    x-on:click.outside="close(false)"
>
    <button
        type="button"
        id="{{ $id }}"
        class="rg-admin-icon-button rg-admin-icon-button--ghost rg-admin-icon-button--sm"
        aria-label="{{ $label }}"
        title="{{ $label }}"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="{{ $id }}-menu"
        x-ref="trigger"
        x-bind:aria-expanded="expanded ? 'true' : 'false'"
        x-on:click="expanded ? close(true) : show()"
        x-on:keydown.arrow-down.prevent="show()"
        x-on:keydown.arrow-up.prevent="show(true)"
    >
        <x-admin.ui.icon name="ellipsis" :size="16" />
    </button>

    <div
        id="{{ $id }}-menu"
        role="menu"
        aria-labelledby="{{ $id }}"
        class="rg-admin-menu rg-admin-row-menu__menu"
        x-ref="menu"
        hidden
        x-on:click="chose($event)"
        x-on:keydown.arrow-down.prevent="step(1)"
        x-on:keydown.arrow-up.prevent="step(-1)"
        x-on:keydown.home.prevent="items()[0]?.focus({ preventScroll: true })"
        x-on:keydown.end.prevent="items().at(-1)?.focus({ preventScroll: true })"
        x-on:keydown.escape.prevent.stop="close(true)"
        x-on:keydown.tab="close(false)"
    >
        {{ $slot }}
    </div>
</div>
