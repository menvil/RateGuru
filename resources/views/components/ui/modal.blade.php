@props([
    'title',
    'size' => 'md',
    'state' => 'open',
    'allowOverflow' => false,
    // Opt-in dialog behaviours. All off by default, so every existing modal
    // renders and behaves exactly as it did before they existed.
    'trapFocus' => false,     // keep focus inside, give it back on close, lock page scroll while open
    'closeOnEscape' => false, // Escape closes the dialog
    'fitViewport' => false,   // never taller than the viewport: header stays put, body scrolls
    'belowHeader' => false,   // laid out between the app header and the bottom of the screen, with equal gaps
])

@php
    $sizes = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        'fullscreen' => 'sm:max-w-[min(96vw,1200px)]',
    ];

    $maxWidthClass = $sizes[$size] ?? $sizes['md'];
    $titleId = 'ui-modal-title-'.str()->uuid();
@endphp

<div
    {{ $attributes }}
    x-show="{{ $state }}"
    x-cloak
    x-transition:enter="motion-safe:transition-opacity motion-reduce:transition-none ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="motion-safe:transition-opacity motion-reduce:transition-none ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-on:click.stop
    @if ($closeOnEscape) x-on:keydown.escape.window="{{ $state }} = false" @endif
    @if ($belowHeader)
        {{-- A page-level dialog sits under the sticky header (z-[60]), so its
             top must start where the header ends — measured, because the
             header grows on mobile and a dialog may live inside a panel. --}}
        x-effect="if ({{ $state }}) { $nextTick(() => rgPlaceModalBelowHeader($el)) }"
        x-on:resize.window="if ({{ $state }}) { rgPlaceModalBelowHeader($el) }"
        data-modal-below-header
        class="fixed inset-x-0 bottom-0 top-[var(--rg-modal-top,0px)] z-50 overflow-y-auto px-4 py-6 sm:px-6"
    @else
    class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-6"
    @endif
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
>
    <div
        data-testid="modal-backdrop"
        class="fixed inset-0 bg-rg-overlay backdrop-blur-sm motion-safe:transition-opacity motion-reduce:transition-none"
        x-on:click.stop="{{ $state }} = false"
    ></div>

    <div class="relative mx-auto flex min-h-full items-center justify-center">
        <div
            data-modal-panel
            class="relative w-full {{ $maxWidthClass }} {{ $allowOverflow ? 'overflow-visible' : 'overflow-hidden' }} rounded-rgCard border border-rg-border2 bg-rg-card text-rg-text shadow-rgPopover{{ $fitViewport ? ' flex max-h-[calc(100dvh-3rem)] flex-col' : '' }}"
            x-on:click.stop
            @if ($trapFocus) x-trap.noscroll.noautofocus="{{ $state }}" @endif
        >
            <div class="flex items-start justify-between gap-4 border-b border-rg-border px-5 py-4{{ $fitViewport ? ' shrink-0' : '' }}">
                <h2 id="{{ $titleId }}" class="text-base font-semibold text-rg-text">
                    {{ $title }}
                </h2>

                <button
                    type="button"
                    class="cursor-pointer rounded-rgSm border border-rg-border2 bg-rg-card2 p-1 text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    aria-label="{{ __('ui.a11y.close') }}"
                    data-testid="modal-close"
                    x-on:click="{{ $state }} = false"
                >
                    <x-ui.icon name="x" class="size-4" />
                </button>
            </div>

            <div class="px-5 py-4 text-sm text-rg-text2{{ $fitViewport ? ' min-h-0 overflow-y-auto overscroll-contain' : '' }}" data-modal-body @if ($fitViewport) data-testid="modal-body" @endif>
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex items-center justify-end gap-3 border-t border-rg-border bg-rg-surface px-5 py-4{{ $fitViewport ? ' shrink-0' : '' }}">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
