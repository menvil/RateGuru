@props([
    'placeholder' => 'Search',
    'label' => null,
    'name' => 'search',
    'value' => null,
    'id' => null,
    'shortcut' => null,
    'disabled' => false,
    'clearable' => false,
])

{{--
    Filters as the user types: no submit button. The placeholder says what is
    searched, and doubles as the accessible name unless a label is given.
    Wrapper attributes (class, style) land on the frame; everything else, such
    as wire:model or x-model, lands on the input.

    A clearable field shows a clear button while it holds text. It empties the
    field the way typing would — an input event, so x-model and wire:model
    follow — and leaves focus in it. A button may not sit inside a label, so
    that frame is a div that focuses the field itself.
--}}
@php
    $frame = $clearable ? 'div' : 'label';
    $frameAttributes = $attributes->only(['class', 'style'])->class(['rg-admin-search', 'rg-admin-search--disabled' => $disabled]);

    if ($clearable) {
        $frameAttributes = $frameAttributes->merge([
            'x-data' => '',
            'x-on:click' => "if (! \$event.target.closest('input, button')) \$el.querySelector('input').focus()",
        ]);
    }
@endphp

<{{ $frame }} {{ $frameAttributes }}>
    <x-admin.ui.icon name="search" :size="18" class="rg-admin-search__icon" />
    <input
        type="search"
        name="{{ $name }}"
        @if ($id !== null) id="{{ $id }}" @endif
        value="{{ $value }}"
        placeholder="{{ $placeholder }}"
        aria-label="{{ $label ?? $placeholder }}"
        autocomplete="off"
        class="rg-admin-search__control"
        @disabled($disabled)
        {{ $attributes->except(['class', 'style']) }}
    />
    @if ($clearable)
        {{-- Hidden by the stylesheet while the placeholder shows, that is while the field is empty. --}}
        <button
            type="button"
            class="rg-admin-search__clear"
            aria-label="Clear search"
            @if ($id !== null) aria-controls="{{ $id }}" @endif
            @disabled($disabled)
            x-on:click="const field = $el.parentElement.querySelector('input'); field.value = ''; field.dispatchEvent(new Event('input', { bubbles: true })); field.focus()"
        >
            <x-admin.ui.icon name="x" :size="16" />
        </button>
    @endif
    @if ($shortcut !== null)
        <kbd class="rg-admin-kbd">{{ $shortcut }}</kbd>
    @endif
</{{ $frame }}>
