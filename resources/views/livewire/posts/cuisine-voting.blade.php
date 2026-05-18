<div data-testid="cuisine-voting" class="flex flex-col gap-3">
    @if($post === null)
        <span data-testid="cuisine-voting-unavailable" class="text-xs text-rg-muted">Cuisine voting unavailable</span>
    @else
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            @foreach($options as $option)
                <button
                    type="button"
                    wire:click="vote('{{ $option->value }}')"
                    wire:target="vote"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    class="inline-flex items-center justify-center gap-1 rounded-rgPill border border-rg-border bg-rg-card2 px-3 py-1.5 text-sm font-semibold text-rg-text transition hover:border-rg-accentBorder disabled:cursor-wait disabled:opacity-60"
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
