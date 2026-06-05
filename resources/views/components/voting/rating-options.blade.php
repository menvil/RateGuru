@props([
    'group',
    'options',
    'selectedOptionId' => null,
    'guest' => false,
])

<div class="flex flex-col gap-3">
    <h3 class="text-sm font-semibold text-rg-text">{{ $group->label }}</h3>

    <div class="flex flex-wrap gap-2">
        @foreach($options as $option)
            @php($active = (int) $selectedOptionId === (int) $option->id)

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
        @endforeach
    </div>

    @if($guest)
        <p class="text-xs text-rg-muted">Sign in to vote.</p>
    @endif
</div>
