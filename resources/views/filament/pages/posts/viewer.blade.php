{{--
    A post's original image over a darker scrim than a dialog's, as large as
    the window allows, with what it is — its size in pixels, its type, how
    large the file is and where it is stored — and Open original for the file
    itself. It sits
    on the overlay layer the dialog and the drawer share, so Escape, the scrim
    and Close dismiss it and focus goes back to the thumbnail or menu item that
    opened it.
--}}
<x-admin.ui.overlay class="rg-admin-dialog-layer rg-admin-viewer-layer" x-on:mousedown.self="dismiss()" x-on:dismiss="$wire.closeViewer()" wire:key="viewer-{{ $viewing }}">
    <div
        class="rg-admin-viewer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rg-admin-posts-viewer-title"
        tabindex="-1"
        x-trap.inert.noscroll="! closing"
    >
        <div class="rg-admin-viewer__image">
            <img src="{{ $viewer['url'] }}" alt="{{ $viewer['title'] }}" />
        </div>
        <div class="rg-admin-viewer__footer">
            <div class="rg-admin-viewer__text">
                <h2 id="rg-admin-posts-viewer-title" class="rg-admin-viewer__title">{{ $viewer['title'] }}</h2>
                <p class="rg-admin-viewer__meta">{{ $viewer['meta'] }}</p>
            </div>
            <x-admin.ui.button size="sm" icon="external-link" :href="$viewer['url']" target="_blank" rel="noopener">Open original</x-admin.ui.button>
            <x-admin.ui.icon-button icon="x" label="Close" variant="ghost" size="sm" x-on:click="dismiss()" />
        </div>
    </div>
</x-admin.ui.overlay>
