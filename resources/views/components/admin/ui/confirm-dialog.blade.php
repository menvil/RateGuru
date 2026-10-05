@props([
    'title',
    'tone' => 'default',
    'icon' => null,
    'id' => 'rg-admin-dialog',
])

@php
    /*
     * OVL-01. A confirmation that is open for as long as it is rendered: the
     * screen decides when to draw it, the dialog only how. Escape, the scrim,
     * the close button and a Cancel that calls dismiss() all hide it at once
     * and dispatch a `dismiss` event, so the screen can forget it:
     * x-on:dismiss="$wire.closeConfirmation()". An action that replaces the
     * dialog with something else calls hide() instead, which hides it without
     * dispatching anything (x-admin.ui.overlay).
     *
     * While it is open, keyboard focus moves into it, cannot leave it and goes
     * back to whatever opened it afterwards; the page behind is hidden from
     * assistive technology and does not scroll. The panel itself takes focus
     * when its text is clicked, so Escape still reaches it.
     *
     * The default slot is the description. Optional slots: `details` for a
     * notice or facts under it, `footnote` for a note left of the actions, and
     * `actions` for the buttons, the primary one last. A blocked dialog
     * explains why and offers the alternative instead of a confirm action.
     */
    $icons = [
        'default' => 'info',
        'warning' => 'circle-alert',
    ];

    if (! array_key_exists($tone, $icons)) {
        throw new InvalidArgumentException("Unknown admin dialog tone [{$tone}].");
    }
@endphp

<x-admin.ui.overlay
    {{ $attributes->class(['rg-admin-dialog-layer']) }}
    {{--
        Pressed on the scrim itself: a drag that starts inside the dialog and
        ends outside it is not a dismissal, and neither is the scrollbar of a
        dialog taller than the screen.
    --}}
    x-on:mousedown.self="if ($event.offsetX < $el.clientWidth) dismiss()"
>
    <div
        id="{{ $id }}"
        class="rg-admin-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $id }}-title"
        aria-describedby="{{ $id }}-description"
        tabindex="-1"
        x-trap.inert.noscroll="! closing"
    >
        <div class="rg-admin-dialog__header">
            <span class="rg-admin-dialog__icon rg-admin-dialog__icon--{{ $tone }}">
                <x-admin.ui.icon :name="$icon ?? $icons[$tone]" :size="18" />
            </span>
            <div class="rg-admin-dialog__heading">
                <h2 id="{{ $id }}-title" class="rg-admin-dialog__title">{{ $title }}</h2>
                <div id="{{ $id }}-description" class="rg-admin-dialog__description">{{ $slot }}</div>
            </div>
            <x-admin.ui.icon-button icon="x" label="Close" variant="ghost" size="sm" x-on:click="dismiss()" />
        </div>

        @isset($details)
            <div class="rg-admin-dialog__details">{{ $details }}</div>
        @endisset

        <div class="rg-admin-dialog__footer">
            <span class="rg-admin-dialog__footnote">{{ $footnote ?? '' }}</span>
            {{ $actions ?? '' }}
        </div>
    </div>
</x-admin.ui.overlay>
