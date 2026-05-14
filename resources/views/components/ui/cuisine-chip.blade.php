@props(['active' => false])

<button
    type="button"
    {{ $attributes->class([
        'inline-flex h-8 min-w-11 items-center justify-center rounded-rgSm border px-2.5 text-xs font-semibold transition',
        $active ? 'border-rg-accentBorder bg-rg-accentSoft text-rg-accent2' : 'border-rg-border2 bg-transparent text-rg-text2 hover:bg-rg-card2',
    ]) }}
>
    {{ $slot }}
</button>
