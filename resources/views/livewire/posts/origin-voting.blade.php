<div data-testid="origin-voting" class="flex flex-col gap-2">
    @if($post === null)
        <span data-testid="origin-voting-unavailable" class="text-xs text-rg-muted">Origin voting unavailable</span>
    @else
        @php
            $homemadeActive = $currentOrigin === 'homemade';
            $restaurantActive = $currentOrigin === 'restaurant';
            $baseClass = 'inline-flex min-w-[5rem] items-center justify-center gap-1 rounded-rgPill border px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-wait disabled:opacity-60';
            $idleClass = 'border-rg-border bg-rg-card2 text-rg-text2 hover:border-rg-accentBorder hover:bg-rg-cardHover hover:text-rg-text';
            $activeClass = 'border-rg-accent bg-rg-accentSoft text-rg-text';
        @endphp

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="vote('homemade')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-wait"
                aria-pressed="{{ $homemadeActive ? 'true' : 'false' }}"
                data-state="{{ $homemadeActive ? 'active' : 'idle' }}"
                class="{{ $baseClass }} {{ $homemadeActive ? $activeClass : $idleClass }}"
            >
                Homemade
            </button>

            <button
                type="button"
                wire:click="vote('restaurant')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-wait"
                aria-pressed="{{ $restaurantActive ? 'true' : 'false' }}"
                data-state="{{ $restaurantActive ? 'active' : 'idle' }}"
                class="{{ $baseClass }} {{ $restaurantActive ? $activeClass : $idleClass }}"
            >
                Restaurant
            </button>
        </div>

        <div data-testid="origin-distribution-bar" class="flex flex-col gap-1">
            <div class="flex justify-between text-xs text-rg-muted">
                <span>Homemade {{ $this->originDistribution['homemadePct'] }}% ({{ $this->originDistribution['homemade'] }})</span>
                <span>Restaurant {{ $this->originDistribution['restaurantPct'] }}% ({{ $this->originDistribution['restaurant'] }})</span>
            </div>

            <div class="h-2 w-full overflow-hidden rounded-rgPill bg-rg-card2">
                <div
                    class="h-2 rounded-rgPill bg-rg-accent transition-all"
                    style="width: {{ $this->originDistribution['homemadePct'] }}%"
                ></div>
            </div>
        </div>

        @if($error !== '')
            <span data-testid="origin-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
