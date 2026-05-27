<div
    data-testid="sort-dropdown"
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative"
>
    <button
        type="button"
        @click="open = ! open"
        class="inline-flex items-center gap-1.5 rounded-rgPill border border-rg-border2 bg-rg-card2 px-3 py-1 text-[13px] font-medium text-rg-text2 transition-colors hover:border-rg-accent hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
        aria-haspopup="true"
        :aria-expanded="open"
    >
        {{ $currentLabel }}
        <svg class="h-3 w-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 z-50 mt-1 min-w-32 origin-top-right rounded-rgCard border border-rg-border bg-rg-card p-1 shadow-rgPopover ring-1 ring-rg-borderSoft"
        style="display: none;"
    >
        @foreach($options as $value => $label)
            <button
                type="button"
                wire:click="$set('sort', '{{ $value }}')"
                @click="open = false"
                aria-pressed="{{ $sort === $value ? 'true' : 'false' }}"
                class="flex w-full items-center rounded-rgControl px-3 py-1.5 text-left text-[13px] transition-colors {{ $sort === $value ? 'bg-rg-accent text-rg-onAccent' : 'text-rg-text2 hover:bg-rg-card2 hover:text-rg-text' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
