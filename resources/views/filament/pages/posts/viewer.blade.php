{{--
    A post's original image over a darker scrim than a dialog's, as large as
    the window allows, with what it is — its size in pixels, its type, how
    large the file is and where it is stored, its content rating and what each
    classifier last said of it — and Open original for the file itself. It sits
    on the overlay layer the dialog and the drawer share, so Escape, the scrim
    and Close dismiss it and focus goes back to the thumbnail or menu item that
    opened it. Where the file came from is said too, for moderators alone:
    the link it was taken from, to open the original post, or that it was
    uploaded as a file; and the source its author named.
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
            @if ($viewer['video'])
                <video src="{{ $viewer['url'] }}" @if($viewer['poster']) poster="{{ $viewer['poster'] }}" @endif controls muted playsinline preload="metadata" aria-label="{{ $viewer['title'] }}"></video>
            @else
                <img src="{{ $viewer['url'] }}" alt="{{ $viewer['title'] }}" />
            @endif
        </div>
        <div class="rg-admin-viewer__footer">
            <div class="rg-admin-viewer__text">
                <h2 id="rg-admin-posts-viewer-title" class="rg-admin-viewer__title">{{ $viewer['title'] }}</h2>
                <p class="rg-admin-viewer__meta">{{ $viewer['meta'] }}</p>
                <p class="rg-admin-viewer__meta">
                    <x-admin.ui.badge :tone="$viewer['rating']['tone']">{{ $viewer['rating']['label'] }}</x-admin.ui.badge>
                    @foreach ($viewer['classifiers'] as $line)
                        <span>{{ $line }}</span>@if (! $loop->last)<span aria-hidden="true"> · </span>@endif
                    @endforeach
                </p>
                <p class="rg-admin-viewer__meta" data-testid="post-viewer-origin">
                    @if ($viewer['takenFrom'])
                        Taken from a link:
                        <a href="{{ $viewer['takenFrom']['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="rg-admin-viewer__link" title="{{ $viewer['takenFrom']['url'] }}">{{ $viewer['takenFrom']['host'] }}</a>
                    @else
                        Uploaded as a file
                    @endif
                    @if ($viewer['source'])
                        <span aria-hidden="true"> · </span>
                        Source given:
                        <a href="{{ $viewer['source']['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="rg-admin-viewer__link" title="{{ $viewer['source']['url'] }}">{{ $viewer['source']['host'] }}</a>
                    @endif
                </p>
            </div>
            <x-admin.ui.button size="sm" icon="external-link" :href="$viewer['url']" target="_blank" rel="noopener">Open original</x-admin.ui.button>
            <x-admin.ui.icon-button icon="x" label="Close" variant="ghost" size="sm" x-on:click="dismiss()" />
        </div>
    </div>
</x-admin.ui.overlay>
