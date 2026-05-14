@props([
    'title',
    'side' => 'right',
    'size' => 'lg',
])

@php
    $generatedId = (string) Illuminate\Support\Str::uuid();
    $drawerId = $attributes->get('id') ?? 'drawer-'.$generatedId;
    $titleId = $attributes->has('id') ? $drawerId.'-title' : 'drawer-title-'.$generatedId;

    $panelSideClass = [
        'left' => 'inset-y-0 left-0',
        'right' => 'inset-y-0 right-0',
    ][$side] ?? 'inset-y-0 right-0';

    $panelSizeClass = [
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
    ][$size] ?? 'sm:max-w-lg';

    $enterStartClass = $side === 'left' ? '-translate-x-full' : 'translate-x-full';
    $leaveEndClass = $side === 'left' ? '-translate-x-full' : 'translate-x-full';
@endphp

<div
    x-data="{ open: false, drawerId: @js($drawerId) }"
    x-on:open-drawer.window="if ($event.detail?.id === drawerId) open = true"
    x-on:close-drawer.window="if ($event.detail?.id === drawerId) open = false"
    x-on:keydown.escape.window="open = false"
    data-drawer-id="{{ $drawerId }}"
    class="pointer-events-none fixed inset-0 z-50"
>
    <div
        x-show="open"
        x-on:click="open = false"
        x-transition:enter="transition-opacity ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="pointer-events-auto fixed inset-0 bg-black/70"
        aria-hidden="true"
    ></div>

    <aside
        x-show="open"
        @click.outside="open = false"
        x-transition:enter="transform transition ease-out duration-200"
        x-transition:enter-start="{{ $enterStartClass }}"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transform transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="{{ $leaveEndClass }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $titleId }}"
        {{ $attributes->class([
            'pointer-events-auto fixed flex h-dvh w-full flex-col border-white/10 bg-zinc-950 text-zinc-100 shadow-2xl shadow-black/40 outline-none sm:w-full',
            $panelSideClass,
            $panelSizeClass,
            $side === 'left' ? 'border-r' : 'border-l',
        ]) }}
    >
        <header class="flex min-h-16 items-center border-b border-white/10 px-4 py-4 sm:px-6">
            <h2 id="{{ $titleId }}" class="text-base font-semibold text-white">
                {{ $title }}
            </h2>
        </header>

        <div class="flex-1 overflow-y-auto px-4 py-5 sm:px-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="border-t border-white/10 bg-zinc-900/60 px-4 py-4 sm:px-6">
                {{ $footer }}
            </footer>
        @endisset
    </aside>
</div>
