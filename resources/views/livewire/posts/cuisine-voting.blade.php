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

        @if($error !== '')
            <span data-testid="cuisine-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
