@props([
    'post',
    'currentValue',
    'votingDisabled',
    'isOwnPost',
    'error',
    'rootTestId',
    'unavailableTestId',
    'errorTestId',
    'optionATestId',
    'optionBTestId',
    'optionAValue',
    'optionBValue',
])

<div data-testid="{{ $rootTestId }}" class="flex flex-col gap-2">
    @if($post === null)
        <span data-testid="{{ $unavailableTestId }}" class="text-xs text-rg-muted">Source voting unavailable</span>
    @else
        <p class="sr-only">Source</p>

        @php
            $sourceAActive = $currentValue === $optionAValue;
            $sourceBActive = $currentValue === $optionBValue;
            $baseClass = 'inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-rgControl border px-3.5 text-[13px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-not-allowed disabled:opacity-70';
            $idleClass = 'border-rg-border2 bg-transparent text-rg-text2 hover:border-rg-accentBorder hover:bg-rg-card2 hover:text-rg-text';
            $sourceAClass = $sourceAActive ? 'border-rg-good bg-rg-goodSoft text-rg-good' : $idleClass;
            $sourceBClass = $sourceBActive ? 'border-rg-accent bg-rg-accentSoft text-rg-accent2' : $idleClass;
        @endphp

        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
            <button
                type="button"
                wire:click="vote('{{ $optionAValue }}')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-not-allowed"
                @disabled($votingDisabled)
                aria-pressed="{{ $sourceAActive ? 'true' : 'false' }}"
                data-state="{{ $sourceAActive ? 'active' : 'idle' }}"
                data-testid="{{ $optionATestId }}-{{ $post->id }}"
                class="{{ $baseClass }} {{ $sourceAClass }}"
            >
                <x-ui.icon name="leaf" class="size-3.5" />
                Source A
            </button>

            <button
                type="button"
                wire:click="vote('{{ $optionBValue }}')"
                wire:target="vote"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-not-allowed"
                @disabled($votingDisabled)
                aria-pressed="{{ $sourceBActive ? 'true' : 'false' }}"
                data-state="{{ $sourceBActive ? 'active' : 'idle' }}"
                data-testid="{{ $optionBTestId }}-{{ $post->id }}"
                class="{{ $baseClass }} {{ $sourceBClass }}"
            >
                <x-ui.icon name="chef" class="size-3.5" />
                Source B
            </button>
        </div>

        @if($isOwnPost)
            <span data-testid="{{ $errorTestId }}" class="text-xs text-rg-danger">You cannot vote on your own post.</span>
        @elseif($error !== '')
            <span data-testid="{{ $errorTestId }}" class="text-xs text-rg-danger">{{ $error }}</span>
        @endif
    @endif
</div>
