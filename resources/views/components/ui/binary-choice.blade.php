<div
    role="group"
    x-data="{ selected: @js($selected) }"
    {{ $attributes->class(['grid h-[38px] grid-cols-2 gap-2.5']) }}
>
    @foreach ($options as $option)
        @php
            $active = $selected === $option['value'];
        @endphp

        <button
            type="button"
            name="{{ $name }}"
            value="{{ $option['value'] }}"
            aria-pressed="{{ $active ? 'true' : 'false' }}"
            x-bind:aria-pressed="selected === @js($option['value']) ? 'true' : 'false'"
            x-on:click="selected = @js($option['value']); $dispatch('choice-changed', { value: selected })"
            class="rounded-rgControl border px-3 text-[13px] font-semibold transition hover:bg-rg-card2"
            x-bind:class="selected === @js($option['value']) ? @js($option['activeClass']) : @js($inactiveClass)"
        >
            {{ $option['label'] }}
        </button>
    @endforeach
</div>
