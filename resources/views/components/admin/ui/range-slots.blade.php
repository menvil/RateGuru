@props([
    'active',
    'min',
    'max',
    'count' => 10,
    'size' => 'table',
])

@php
    /*
     * STS-04. How many options of a rating group are active against its
     * allowed minimum and maximum: one slot per option count, ink while
     * active, orange for an active one above the maximum, red where the
     * minimum still needs one, white where one more is allowed, grey beyond
     * the maximum. `table` draws 12×12 slots in a row; `editor` draws 28 high
     * slots across the card, numbered.
     *
     * The slots are a picture: they are named once, as a whole, and the
     * screen says the same in words beside them.
     */
    if (! in_array($size, ['table', 'editor'], true)) {
        throw new InvalidArgumentException("Unknown admin range slots size [{$size}].");
    }

    $active = (int) $active;
    $min = (int) $min;
    $max = (int) $max;
    $count = max((int) $count, $active);

    $state = static fn (int $slot): string => match (true) {
        $slot <= $active => $slot <= $max ? 'active' : 'over',
        $slot <= $min => 'required',
        $slot <= $max => 'allowed',
        default => 'outside',
    };
    $titles = ['active' => 'active', 'over' => 'active, above the maximum', 'required' => 'required, missing', 'allowed' => 'allowed, unused', 'outside' => 'above the maximum'];
@endphp

<div
    {{ $attributes->class(['rg-admin-range-slots', "rg-admin-range-slots--{$size}"]) }}
    role="img"
    aria-label="{{ $active }} active, {{ $min }} to {{ $max }} allowed"
>
    @foreach (range(1, $count) as $slot)
        @if ($size === 'editor')
            <span class="rg-admin-range-slots__cell" aria-hidden="true">
                <span class="rg-admin-range-slots__slot rg-admin-range-slots__slot--{{ $state($slot) }}"></span>
                <span @class(['rg-admin-range-slots__number', 'rg-admin-range-slots__number--outside' => $slot < $min || $slot > $max])>{{ $slot }}</span>
            </span>
        @else
            <span class="rg-admin-range-slots__slot rg-admin-range-slots__slot--{{ $state($slot) }}" title="{{ $slot }} · {{ $titles[$state($slot)] }}" aria-hidden="true"></span>
        @endif
    @endforeach
</div>
