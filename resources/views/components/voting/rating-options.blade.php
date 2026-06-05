@props([
    'group',
    'options',
    'selectedOptionId' => null,
    'guest' => false,
    'disabled' => true,
    'isOwnPost' => false,
    'error' => '',
    'distribution' => [],
    'variant' => 'default',
    'testIdPrefix' => 'rating-option',
])

<div class="flex flex-col gap-3">
    <h3 class="text-sm font-semibold text-rg-text">{{ $group->label }}</h3>

    <div class="{{ $variant === 'compact' ? 'flex flex-wrap gap-1.5' : 'flex flex-wrap gap-2' }}">
        @foreach($options as $option)
            @php($active = (int) $selectedOptionId === (int) $option->id)
            @php($result = $distribution[$option->id] ?? null)
            @php($resultLabel = $result === null ? null : $result['count'].' '.($result['count'] === 1 ? 'vote' : 'votes').' · '.number_format($result['percent'], 0).'%')
            @php($sizeClass = $variant === 'compact' ? '!h-7 !min-w-9 !px-2 !text-[11px]' : '')

            <x-ui.button
                :variant="$active ? 'primary' : 'secondary'"
                size="sm"
                :class="$sizeClass"
                :disabled="$disabled"
                aria-pressed="{{ $active ? 'true' : 'false' }}"
                data-state="{{ $active ? 'active' : 'idle' }}"
                data-testid="{{ $testIdPrefix }}-{{ $option->id }}"
                :wire:click="$disabled ? false : 'vote('.$option->id.')'"
                :wire:target="$disabled ? false : 'vote'"
                :wire:loading.attr="$disabled ? false : 'disabled'"
                :wire:loading.class="$disabled ? false : 'opacity-60 cursor-not-allowed'"
            >
                <span>{{ $option->label }}</span>
                @if($resultLabel !== null)
                    <span class="text-[11px] font-medium opacity-75">{{ $resultLabel }}</span>
                @endif
            </x-ui.button>
        @endforeach
    </div>

    @if($guest)
        <p class="text-xs text-rg-muted">Sign in to vote.</p>
    @elseif($isOwnPost)
        <p class="text-xs text-rg-danger">You cannot vote on your own post.</p>
    @elseif($error !== '')
        <p class="text-xs text-rg-danger">{{ $error }}</p>
    @endif
</div>
