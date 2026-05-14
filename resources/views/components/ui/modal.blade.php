@props([
    'title',
    'size' => 'md',
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
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
>
    <div
        class="fixed inset-0 bg-zinc-950/80 backdrop-blur-sm"
        x-on:click="open = false"
    ></div>

    <div class="relative mx-auto flex min-h-full items-center justify-center">
        <div
            class="relative w-full {{ $maxWidthClass }} overflow-hidden rounded-xl border border-purple-400/20 bg-zinc-950 text-zinc-100 shadow-2xl shadow-purple-950/30"
            x-on:click.stop
        >
            <div class="flex items-start justify-between gap-4 border-b border-zinc-800 px-5 py-4">
                <h2 id="{{ $titleId }}" class="text-base font-semibold text-zinc-50">
                    {{ $title }}
                </h2>

                <button
                    type="button"
                    class="rounded-md p-1 text-zinc-400 transition hover:bg-zinc-900 hover:text-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-400"
                    aria-label="Close modal"
                    x-on:click="open = false"
                >
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="border-t border-purple-400/10 px-5 py-4 text-sm text-zinc-200">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex items-center justify-end gap-3 border-t border-zinc-800 bg-zinc-900/70 px-5 py-4">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
