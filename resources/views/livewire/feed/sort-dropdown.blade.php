<div data-testid="sort-dropdown">
    <div class="flex gap-1">
        @foreach($options as $value => $label)
            <button
                type="button"
                wire:click="$set('sort', '{{ $value }}')"
                aria-pressed="{{ $sort === $value ? 'true' : 'false' }}"
                class="rounded-rgPill border px-3 py-1 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent {{ $sort === $value ? 'border-rg-accent bg-rg-accent text-white' : 'border-rg-border2 bg-rg-card2 text-rg-text2 hover:border-rg-accent hover:text-rg-text' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
