<div data-testid="cuisine-voting" class="flex flex-col gap-3">
    @if($post === null)
        <span data-testid="cuisine-voting-unavailable" class="text-xs text-rg-muted">Cuisine voting unavailable</span>
    @else
        @php
            $baseClass = 'inline-flex min-h-9 items-center justify-center gap-1 rounded-rgPill border px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-wait disabled:opacity-60';
            $idleClass = 'border-rg-border bg-rg-card2 text-rg-text2 hover:border-rg-accentBorder hover:bg-rg-cardHover hover:text-rg-text';
            $activeClass = 'border-rg-accent bg-rg-accentSoft text-rg-text';
        @endphp

        <div class="flex flex-wrap gap-2">
            @foreach($options as $option)
                @php($active = $currentCuisine === $option->value)
                <button
                    type="button"
                    wire:click="vote('{{ $option->value }}')"
                    wire:target="vote"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    data-state="{{ $active ? 'active' : 'idle' }}"
                    class="{{ $baseClass }} {{ $active ? $activeClass : $idleClass }}"
                >
                    {{ $this->labelFor($option) }}
                </button>
            @endforeach
        </div>

        <div data-testid="cuisine-distribution-panel" class="flex flex-col gap-2">
            @if($this->distribution['total'] === 0)
                <span class="text-xs text-rg-muted">No cuisine votes yet</span>
            @else
                @foreach($this->distribution['rows'] as $row)
                    <div class="flex flex-col gap-1">
                        <div class="flex justify-between text-xs text-rg-muted">
                            <span>{{ $row['label'] }}</span>
                            <span>{{ $row['count'] }} · {{ $row['percentage'] }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-rgPill bg-rg-card2">
                            <div
                                class="h-2 rounded-rgPill bg-rg-accent transition-all"
                                style="width: {{ $row['percentage'] }}%"
                            ></div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        @if($error !== '')
            <span data-testid="cuisine-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
