<div data-testid="origin-voting" class="flex flex-col gap-2">
    @if($post === null)
        <span data-testid="origin-voting-unavailable" class="text-xs text-rg-muted">Origin voting unavailable</span>
    @else
        <div class="flex items-center gap-2">
            <button
                type="button"
                class="inline-flex min-w-[5rem] items-center justify-center gap-1 rounded-rgPill border border-rg-border bg-rg-card2 px-3 py-1.5 text-sm font-semibold text-rg-text transition hover:border-rg-accentBorder"
            >
                Homemade
            </button>

            <button
                type="button"
                class="inline-flex min-w-[5rem] items-center justify-center gap-1 rounded-rgPill border border-rg-border bg-rg-card2 px-3 py-1.5 text-sm font-semibold text-rg-text transition hover:border-rg-accentBorder"
            >
                Restaurant
            </button>
        </div>
    @endif
</div>
