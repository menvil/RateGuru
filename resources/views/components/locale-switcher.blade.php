@php
    $manager = app(\App\Support\Locale\LocaleManager::class);
    $supported = $manager->supported();
    $current = app()->getLocale();
@endphp

<div x-data="{ open: false }" class="relative" @click.outside="open = false" @keydown.escape.window="open = false">
    <button
        type="button"
        @click="open = !open"
        aria-label="Switch language"
        data-testid="locale-switcher-trigger"
        class="inline-flex h-9 cursor-pointer items-center gap-1 rounded-rgControl border border-rg-border2 bg-rg-card px-3 text-[13px] font-medium text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
    >
        <span>{{ $supported[$current]['native'] ?? strtoupper($current) }}</span>
        <svg class="size-3 fill-current opacity-60" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        data-testid="locale-switcher-menu"
        class="absolute right-0 z-50 mt-1 min-w-36 origin-top-right rounded-rgCard border border-rg-border bg-rg-card p-1 shadow-rgPopover"
        style="display: none;"
    >
        @foreach($supported as $code => $info)
            <form method="POST" action="{{ route('locale.change') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $code }}">
                <button
                    type="submit"
                    data-testid="locale-option-{{ $code }}"
                    class="flex w-full items-center gap-2 rounded-rgSm px-3 py-2 text-left text-sm transition hover:bg-rg-card2 {{ $code === $current ? 'font-semibold text-rg-text' : 'text-rg-text2' }}"
                >
                    {{ $info['native'] }}
                    @if($code === $current)
                        <svg class="ml-auto size-3 text-rg-accent" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    @endif
                </button>
            </form>
        @endforeach
    </div>
</div>
