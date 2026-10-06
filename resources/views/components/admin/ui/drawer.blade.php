@props([
    'title',
    'subtitle' => null,
    'wide' => false,
    'id' => 'rg-admin-drawer',
])

{{--
    OVL-02. A panel on the right, full height, over the drawer scrim: 448 wide,
    480 with `wide` for long lists, never wider than the screen. Like the
    confirmation dialog it is open for as long as it is rendered; Escape, the
    scrim and the close button hide it at once and dispatch `dismiss` for the
    screen to forget it, e.g. x-on:dismiss="$wire.closeDrawer()"
    (x-admin.ui.overlay).

    While it is open, keyboard focus moves into it, cannot leave it and goes
    back to whatever opened it afterwards; the page behind is hidden from
    assistive technology and does not scroll. The body scrolls on its own, and
    the panel takes focus when its text is clicked, so Escape still reaches it.

    The default slot is the body. Optional slots: `leading` before the title
    (an icon or a flag) and `footer` for a note and actions, destructive ones
    on the left.
--}}
<x-admin.ui.overlay {{ $attributes->class(['rg-admin-drawer-layer']) }}>
    <div class="rg-admin-drawer-scrim" x-on:mousedown="dismiss()" aria-hidden="true"></div>

    <div
        id="{{ $id }}"
        @class(['rg-admin-drawer', 'rg-admin-drawer--wide' => $wide])
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $id }}-title"
        @if ($subtitle !== null) aria-describedby="{{ $id }}-subtitle" @endif
        tabindex="-1"
        x-trap.inert.noscroll="! closing"
    >
        <div class="rg-admin-drawer__header">
            @isset($leading)
                <span class="rg-admin-drawer__leading" aria-hidden="true">{{ $leading }}</span>
            @endisset
            <div class="rg-admin-drawer__heading">
                <h2 id="{{ $id }}-title" class="rg-admin-drawer__title">{{ $title }}</h2>
                @if ($subtitle !== null)
                    <p id="{{ $id }}-subtitle" class="rg-admin-drawer__subtitle">{{ $subtitle }}</p>
                @endif
            </div>
            <x-admin.ui.icon-button icon="x" label="Close" variant="ghost" size="sm" x-on:click="dismiss()" />
        </div>

        <div class="rg-admin-drawer__body">{{ $slot }}</div>

        @isset($footer)
            <div {{ $footer->attributes->class(['rg-admin-drawer__footer']) }}>{{ $footer }}</div>
        @endisset
    </div>
</x-admin.ui.overlay>
