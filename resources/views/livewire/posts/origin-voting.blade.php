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

        @php
            $homemade = (int) $post->homemade_votes_count;
            $restaurant = (int) $post->restaurant_votes_count;
            $total = $homemade + $restaurant;

            $homemadePct = $total > 0 ? (int) round(($homemade / $total) * 100) : 0;
            $restaurantPct = $total > 0 ? 100 - $homemadePct : 0;
        @endphp

        <div data-testid="origin-distribution-bar" class="flex flex-col gap-1">
            <div class="flex justify-between text-xs text-rg-muted">
                <span>Homemade {{ $homemadePct }}% ({{ $homemade }})</span>
                <span>Restaurant {{ $restaurantPct }}% ({{ $restaurant }})</span>
            </div>

            <div class="h-2 w-full overflow-hidden rounded-rgPill bg-rg-card2">
                <div
                    class="h-2 rounded-rgPill bg-rg-accent transition-all"
                    style="width: {{ $homemadePct }}%"
                ></div>
            </div>
        </div>

        @if($error !== '')
            <span data-testid="origin-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
