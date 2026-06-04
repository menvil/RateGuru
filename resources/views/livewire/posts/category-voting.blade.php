<div data-testid="category-voting" class="flex flex-col gap-3">
    @if($post === null)
        <span data-testid="category-voting-unavailable" class="text-xs text-rg-muted">Category voting unavailable</span>
    @else
        @php
            $baseClass = $variant === 'compact'
                ? 'inline-flex h-7 min-w-9 cursor-pointer items-center justify-center rounded-rgSm border px-2 text-[11px] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-not-allowed disabled:opacity-70'
                : 'inline-flex h-8 min-w-11 cursor-pointer items-center justify-center rounded-rgSm border px-2.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-not-allowed disabled:opacity-70';
            $idleClass = 'border-rg-border2 bg-transparent text-rg-text2 hover:bg-rg-card2';
            $activeClass = 'border-rg-accentBorder bg-rg-accentSoft text-rg-accent2';
        @endphp

        <div class="{{ $variant === 'compact' ? 'flex flex-wrap gap-1.5' : 'flex flex-wrap gap-2' }}">
            @foreach($options as $option)
                @php($active = $currentCuisine === $option->value)
                <button
                    type="button"
                    wire:click="vote('{{ $option->value }}')"
                    wire:target="vote"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-not-allowed"
                    @disabled($votingDisabled)
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    data-state="{{ $active ? 'active' : 'idle' }}"
                    class="{{ $baseClass }} {{ $active ? $activeClass : $idleClass }}"
                >
                    <span class="sr-only">{{ $this->labelFor($option) }}</span>
                    <span aria-hidden="true">{{ $this->shortLabelFor($option) }}</span>
                </button>
            @endforeach
        </div>

        @if($error !== '')
            <span data-testid="category-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
