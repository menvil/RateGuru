@props([
    'width' => '100%',
    'height' => '10px',
    'shape' => 'pill',
    'tone' => 'strong',
])

@php
    /*
     * One placeholder bar. Rows of them take the grid of the real row they
     * stand in for; the surrounding region says what is loading, because the
     * bars themselves are hidden from assistive technology.
     */
    if (! in_array($shape, ['pill', 'box', 'control'], true)) {
        throw new InvalidArgumentException("Unknown admin skeleton shape [{$shape}].");
    }

    if (! in_array($tone, ['strong', 'soft'], true)) {
        throw new InvalidArgumentException("Unknown admin skeleton tone [{$tone}].");
    }
@endphp

<span
    aria-hidden="true"
    {{ $attributes->class([
        'rg-admin-skeleton',
        'rg-admin-skeleton--soft' => $tone === 'soft',
        'rg-admin-skeleton--box' => $shape === 'box',
        'rg-admin-skeleton--control' => $shape === 'control',
    ]) }}
    style="width: {{ $width }}; height: {{ $height }};"
></span>
