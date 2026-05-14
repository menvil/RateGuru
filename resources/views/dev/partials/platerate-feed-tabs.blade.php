<div class="mb-3.5 flex items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2">
        @foreach (['All' => true, 'Homemade' => false, 'Restaurant' => false, 'Top' => false] as $label => $active)
            <button
                type="button"
                class="{{ $active ? 'border-rg-accent bg-rg-accent text-white' : 'border-rg-border2 bg-rg-card text-rg-text2 hover:bg-rg-card2' }} h-9 rounded-rgPill border px-4 text-[13px] font-semibold transition"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <button type="button" class="h-9 rounded-rgPill border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text2">
        Hot
    </button>
</div>
