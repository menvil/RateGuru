@props([
    'placeholder' => 'Search',
    'label' => null,
    'name' => 'search',
    'value' => null,
    'id' => null,
    'shortcut' => null,
    'disabled' => false,
])

{{--
    Filters as the user types: no submit button. The placeholder says what is
    searched, and doubles as the accessible name unless a label is given.
    Wrapper attributes (class, style) land on the frame; everything else, such
    as wire:model or x-model, lands on the input.
--}}
<label {{ $attributes->only(['class', 'style'])->class(['rg-admin-search', 'rg-admin-search--disabled' => $disabled]) }}>
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
    @if ($shortcut !== null)
        <kbd class="rg-admin-kbd">{{ $shortcut }}</kbd>
    @endif
</label>
