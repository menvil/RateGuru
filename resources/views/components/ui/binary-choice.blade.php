@props(['selected' => 'homemade'])

<div {{ $attributes->class(['grid h-[38px] grid-cols-2 gap-2.5']) }}>
    @foreach (['homemade' => 'Homemade', 'restaurant' => 'Restaurant'] as $value => $label)
        @php
            $active = $selected === $value;
            $activeClass = $value === 'homemade'
                ? 'border-rg-goodBorder bg-rg-goodSoft text-rg-good'
                : 'border-rg-accentBorder bg-rg-accentSoft text-rg-accent2';
        @endphp

        <button
            type="button"
            class="{{ $active ? $activeClass : 'border-rg-border2 bg-transparent text-rg-text2' }} rounded-rgControl border px-3 text-[13px] font-semibold transition hover:bg-rg-card2"
        >
            {{ $label }}
        </button>
    @endforeach
</div>
