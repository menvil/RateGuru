@props([
    'title',
    'side' => 'right',
    'size' => 'lg',
])

@php
    $generatedId = (string) Illuminate\Support\Str::uuid();
    $drawerId = $attributes->get('id') ?? 'drawer-'.$generatedId;
    $titleId = $attributes->has('id') ? $drawerId.'-title' : 'drawer-title-'.$generatedId;

    $panelSizeClass = [
        'md' => 'md:max-w-md',
        'lg' => 'md:max-w-xl lg:max-w-2xl',
        'xl' => 'md:max-w-2xl lg:max-w-3xl',
    ][$size] ?? 'md:max-w-lg';

    $panelDesktopClass = $side === 'left'
        ? 'md:left-0 md:right-auto md:border-r md:border-l-0'
        : 'md:right-0 md:left-auto md:border-l md:border-r-0';

    $enterStartClass = $side === 'left' ? '-translate-x-full' : 'translate-x-full';
    $leaveEndClass = $side === 'left' ? '-translate-x-full' : 'translate-x-full';
@endphp

<div
    x-data="{ open: false, drawerId: @js($drawerId) }"
    x-cloak
    x-on:open-drawer.window="if ($event.detail?.id === drawerId) open = true"
    x-on:close-drawer.window="if ($event.detail?.id === drawerId) open = false"
    x-on:keydown.escape.window="open = false; $dispatch('drawer-closed', { id: drawerId })"
    data-drawer-id="{{ $drawerId }}"
    class="pointer-events-none fixed inset-0 z-50"
>
    <div
        x-show="open"
        x-on:click="open = false; $dispatch('drawer-closed', { id: drawerId })"
        x-transition:enter="motion-safe:transition-opacity motion-reduce:transition-none ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="motion-safe:transition-opacity motion-reduce:transition-none ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="pointer-events-auto fixed inset-0 bg-rg-overlay"
        aria-hidden="true"
    ></div>

    <aside
        x-show="open"
        @click.outside="open = false; $dispatch('drawer-closed', { id: drawerId })"
        x-transition:enter="motion-safe:transform-gpu motion-safe:transition motion-reduce:transition-none ease-out duration-200"
        x-transition:enter-start="{{ $enterStartClass }}"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="motion-safe:transform-gpu motion-safe:transition motion-reduce:transition-none ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="{{ $leaveEndClass }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $titleId }}"
        data-testid="drawer-shell"
        {{ $attributes->class([
            'pointer-events-auto fixed flex flex-col border-rg-border bg-rg-card text-rg-text shadow-rgPopover outline-none overflow-y-auto',
            'inset-x-0 bottom-0 max-h-[90vh] w-full rounded-t-rgCard border-t',
            'md:inset-y-0 md:bottom-auto md:h-dvh md:max-h-none md:border-t-0 md:rounded-none',
            $panelDesktopClass,
            $panelSizeClass,
        ]) }}
    >
        <header class="flex min-h-16 items-center justify-between border-b border-rg-border px-4 py-4 sm:px-6">
            <h2 id="{{ $titleId }}" class="text-base font-semibold text-rg-text">
                {{ $title }}
            </h2>

            <button
                type="button"
                class="rounded-rgSm border border-rg-border2 bg-rg-card2 p-1 text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                aria-label="Close drawer"
                data-testid="post-drawer-close"
                x-on:click="open = false; $dispatch('drawer-closed', { id: drawerId })"
            >
                <x-ui.icon name="x" class="size-4" />
            </button>
        </header>

        <div class="flex-1 overflow-y-auto px-4 py-5 sm:px-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="border-t border-rg-border bg-rg-surface px-4 py-4 sm:px-6">
                {{ $footer }}
            </footer>
        @endisset
    </aside>
</div>
