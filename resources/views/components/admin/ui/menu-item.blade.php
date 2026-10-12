@props([
    'icon' => null,
    'hint' => null,
    'href' => null,
    'tone' => 'default',
    'disabled' => false,
])

@php
    /*
     * One item of a row menu (TBL-04): a 16 icon, a 14px label and an
     * optional 12px hint under it. A link with `href`, otherwise a button
     * that does what its attributes say (wire:click="…").
     *
     * A disabled item stays in the menu, greyed, with the reason as its hint.
     * It keeps its place in the keyboard order and is marked aria-disabled,
     * as a menu's items are, so the reason is heard — and it carries none of
     * the attributes it was given, so it can do nothing. Danger is for
     * destructive items, which follow a separator.
     */
    if (! in_array($tone, ['default', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown admin menu item tone [{$tone}].");
    }

    $classes = [
        'rg-admin-menu__item',
        'rg-admin-menu__item--danger' => $tone === 'danger',
        'rg-admin-menu__item--disabled' => $disabled,
    ];
@endphp

@if ($disabled)
    <button type="button" role="menuitem" tabindex="-1" aria-disabled="true" {{ $attributes->only(['class', 'id', 'data-action'])->class($classes) }}>
        @if ($icon)
            <x-admin.ui.icon :name="$icon" :size="16" />
        @endif
        <span class="rg-admin-menu__text">
            <span class="rg-admin-menu__label">{{ $slot }}</span>
            @if ($hint !== null)
                <span class="rg-admin-menu__hint">{{ $hint }}</span>
            @endif
        </span>
    </button>
@elseif ($href !== null)
    <a href="{{ $href }}" role="menuitem" tabindex="-1" {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-admin.ui.icon :name="$icon" :size="16" />
        @endif
        <span class="rg-admin-menu__text">
            <span class="rg-admin-menu__label">{{ $slot }}</span>
            @if ($hint !== null)
                <span class="rg-admin-menu__hint">{{ $hint }}</span>
            @endif
        </span>
    </a>
@else
    <button type="button" role="menuitem" tabindex="-1" {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-admin.ui.icon :name="$icon" :size="16" />
        @endif
        <span class="rg-admin-menu__text">
            <span class="rg-admin-menu__label">{{ $slot }}</span>
            @if ($hint !== null)
                <span class="rg-admin-menu__hint">{{ $hint }}</span>
            @endif
        </span>
    </button>
@endif
