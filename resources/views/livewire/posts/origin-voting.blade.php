<div data-testid="origin-voting" class="flex flex-col gap-2">
    @if($post === null)
        <span data-testid="origin-voting-unavailable" class="text-xs text-rg-muted">Origin voting unavailable</span>
    @else
        @php
            $homemadeActive = $currentOrigin === 'homemade';
            $restaurantActive = $currentOrigin === 'restaurant';
            $baseClass = 'inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-rgControl border px-3.5 text-[13px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-not-allowed disabled:opacity-70';
            $idleClass = 'border-rg-border2 bg-transparent text-rg-text2 hover:border-rg-accentBorder hover:bg-rg-card2 hover:text-rg-text';
            $homemadeClass = $homemadeActive
                ? 'border-rg-good bg-rg-goodSoft text-rg-good'
                : $idleClass;
            $restaurantClass = $restaurantActive
                ? 'border-rg-accent bg-rg-accentSoft text-rg-accent2'
                : $idleClass;
        @endphp

        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
            <button
                type="button"
                wire:click="vote('homemade')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-not-allowed"
                @disabled($votingDisabled)
                aria-pressed="{{ $homemadeActive ? 'true' : 'false' }}"
                data-state="{{ $homemadeActive ? 'active' : 'idle' }}"
                class="{{ $baseClass }} {{ $homemadeClass }}"
            >
                <x-ui.icon name="leaf" class="size-3.5" />
                Homemade
            </button>

            <button
                type="button"
                wire:click="vote('restaurant')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-not-allowed"
                @disabled($votingDisabled)
                aria-pressed="{{ $restaurantActive ? 'true' : 'false' }}"
                data-state="{{ $restaurantActive ? 'active' : 'idle' }}"
                class="{{ $baseClass }} {{ $restaurantClass }}"
            >
                <x-ui.icon name="chef" class="size-3.5" />
                Restaurant
            </button>
        </div>

        @if($error !== '')
            <span data-testid="origin-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @elseif(($isOwnPost ?? false) && ! $hasVoted)
            <span data-testid="origin-voting-error" class="text-xs text-rg-muted">You cannot vote on your own post.</span>
        @endif
    @endif
</div>
