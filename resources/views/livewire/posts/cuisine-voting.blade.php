<div data-testid="cuisine-voting" class="flex flex-col gap-3">
    @if($post === null)
        <span data-testid="cuisine-voting-unavailable" class="text-xs text-rg-muted">Cuisine voting unavailable</span>
    @else
        @php
            $hasVoted = $currentCuisine !== null;
            $baseClass = 'inline-flex h-8 min-w-12 cursor-pointer items-center justify-center rounded-rgControl border px-2.5 text-[12.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-wait disabled:opacity-60';
            $idleClass = 'border-rg-border2 bg-transparent text-rg-text2 hover:border-rg-accentBorder hover:bg-rg-card2 hover:text-rg-text';
            $activeClass = 'border-rg-accent bg-rg-accentSoft text-rg-accent2';
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
                    @disabled($hasVoted)
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    data-state="{{ $active ? 'active' : 'idle' }}"
                    class="{{ $baseClass }} {{ $active ? $activeClass : $idleClass }}"
                >
                    <span class="sr-only">{{ $this->labelFor($option) }}</span>
                    <span aria-hidden="true">{{ $this->shortLabelFor($option) }}</span>
                </button>
            @endforeach
        </div>

        @if($hasVoted)
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
        @else
            <p class="text-xs text-rg-muted">Vote to reveal results.</p>
        @endif

        @if($error !== '')
            <span data-testid="cuisine-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
