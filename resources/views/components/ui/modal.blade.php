@props([
    'title',
    'size' => 'md',
    'state' => 'open',
])

@php
    $sizes = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
    ];

    $maxWidthClass = $sizes[$size] ?? $sizes['md'];
    $titleId = 'ui-modal-title-'.str()->uuid();
@endphp

<div
    x-show="{{ $state }}"
    x-cloak
    x-transition:enter="motion-safe:transition-opacity motion-reduce:transition-none ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="motion-safe:transition-opacity motion-reduce:transition-none ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
>
    <div
        data-testid="modal-backdrop"
        class="fixed inset-0 bg-black/70 backdrop-blur-sm motion-safe:transition-opacity motion-reduce:transition-none"
        x-on:click="{{ $state }} = false"
    ></div>

    <div class="relative mx-auto flex min-h-full items-center justify-center">
        <div
            class="relative w-full {{ $maxWidthClass }} overflow-hidden rounded-rgCard border border-rg-border2 bg-rg-card text-rg-text shadow-rgPopover"
            x-on:click.stop
        >
            <div class="flex items-start justify-between gap-4 border-b border-rg-border px-5 py-4">
                <h2 id="{{ $titleId }}" class="text-base font-semibold text-rg-text">
                    {{ $title }}
                </h2>

                <button
                    type="button"
                    class="cursor-pointer rounded-rgSm border border-rg-border2 bg-rg-card2 p-1 text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    aria-label="Close modal"
                    x-on:click="{{ $state }} = false"
                >
                    <x-ui.icon name="x" class="size-4" />
                </button>
            </div>

            <div class="px-5 py-4 text-sm text-rg-text2">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex items-center justify-end gap-3 border-t border-rg-border bg-rg-surface px-5 py-4">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
