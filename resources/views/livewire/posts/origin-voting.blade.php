<div data-testid="origin-voting" class="flex flex-col gap-2">
    @if($post === null)
        <span data-testid="origin-voting-unavailable" class="text-xs text-rg-muted">Origin voting unavailable</span>
    @else
        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="vote('homemade')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-wait"
                class="inline-flex min-w-[5rem] items-center justify-center gap-1 rounded-rgPill border border-rg-border bg-rg-card2 px-3 py-1.5 text-sm font-semibold text-rg-text transition hover:border-rg-accentBorder disabled:cursor-wait disabled:opacity-60"
            >
                Homemade
            </button>

            <button
                type="button"
                wire:click="vote('restaurant')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-wait"
                class="inline-flex min-w-[5rem] items-center justify-center gap-1 rounded-rgPill border border-rg-border bg-rg-card2 px-3 py-1.5 text-sm font-semibold text-rg-text transition hover:border-rg-accentBorder disabled:cursor-wait disabled:opacity-60"
            >
                Restaurant
            </button>
        </div>

        @if($error !== '')
            <span data-testid="origin-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
