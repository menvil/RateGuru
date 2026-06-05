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
        class="flex h-8 cursor-pointer items-center gap-1.5 rounded-rgSm border border-rg-border2 bg-rg-card2 px-2.5 text-[12.5px] text-rg-text2 transition hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
        aria-haspopup="true"
        :aria-expanded="open"
    >
        {{ $currentLabel }}
        <x-ui.icon name="chevron-down" class="size-3.5" />
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
        class="absolute right-0 z-50 mt-2 w-32 origin-top-right rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
        style="display: none;"
    >
        @foreach($options as $value => $label)
            <button
                type="button"
                wire:click="$set('sort', '{{ $value }}')"
                @click="open = false"
                aria-pressed="{{ $sort === $value ? 'true' : 'false' }}"
                class="block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-[12.5px] transition {{ $sort === $value ? 'bg-rg-accentSoft text-rg-accent2' : 'text-rg-text2 hover:bg-rg-card' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
