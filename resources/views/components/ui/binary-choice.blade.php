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
            data-state="{{ $active ? 'active' : 'inactive' }}"
            aria-pressed="{{ $active ? 'true' : 'false' }}"
            x-bind:data-state="selected === @js($option['value']) ? 'active' : 'inactive'"
            x-bind:aria-pressed="selected === @js($option['value']) ? 'true' : 'false'"
            x-on:click="selected = @js($option['value']); $dispatch('choice-changed', { value: selected })"
            class="{{ $option['activeStateClass'] }} rounded-rgControl border px-3 text-[13px] font-semibold transition data-[state=inactive]:border-rg-border2 data-[state=inactive]:bg-transparent data-[state=inactive]:text-rg-text2 hover:bg-rg-card2"
        >
            {{ $option['label'] }}
        </button>
    @endforeach
</div>
