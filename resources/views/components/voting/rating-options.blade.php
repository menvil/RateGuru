@props([
    'group',
    'options',
    'selectedOptionId' => null,
    'guest' => false,
    'disabled' => true,
    'isOwnPost' => false,
    'error' => '',
])

<div class="flex flex-col gap-3">
    <h3 class="text-sm font-semibold text-rg-text">{{ $group->label }}</h3>

    <div class="flex flex-wrap gap-2">
        @foreach($options as $option)
            @php($active = (int) $selectedOptionId === (int) $option->id)

            @if($disabled)
                <x-ui.button
                    :variant="$active ? 'primary' : 'secondary'"
                    size="sm"
                    :disabled="true"
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    data-state="{{ $active ? 'active' : 'idle' }}"
                    data-testid="rating-option-{{ $option->id }}"
                >
                    {{ $option->label }}
                </x-ui.button>
            @else
                <x-ui.button
                    :variant="$active ? 'primary' : 'secondary'"
                    size="sm"
                    wire:click="vote({{ $option->id }})"
                    wire:target="vote"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-not-allowed"
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    data-state="{{ $active ? 'active' : 'idle' }}"
                    data-testid="rating-option-{{ $option->id }}"
                >
                    {{ $option->label }}
                </x-ui.button>
            @endif
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
