@props([
    'label',
    'name' => null,
    'id' => null,
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'hint' => null,
    'error' => null,
    'required' => null,
    'disabled' => false,
    'icon' => null,
])

@php
    // required: true shows "Required", false shows "Optional", null shows neither.
    $id ??= 'rg-admin-field-'.\Illuminate\Support\Str::slug($name ?? $label);
    // The caller's own descriptions come first; the field adds its hint or error.
    $describedBy = collect([
        $attributes->get('aria-describedby'),
        $error !== null ? "{$id}-error" : ($hint !== null ? "{$id}-hint" : null),
    ])->reject(fn (mixed $id): bool => $id === null || $id === '')->implode(' ');
@endphp

<div {{ $attributes->only(['class', 'style'])->class(['rg-admin-field']) }}>
    <div class="rg-admin-field__head">
        <label for="{{ $id }}" class="rg-admin-label">{{ $label }}</label>
        @if ($required === true)
            <span class="rg-admin-field__tag rg-admin-field__tag--required">Required</span>
        @elseif ($required === false)
            <span class="rg-admin-field__tag">Optional</span>
        @endif
    </div>

    <div @class(['rg-admin-input', 'rg-admin-input--invalid' => $error !== null, 'rg-admin-input--disabled' => $disabled])>
        @if ($icon)
            <x-admin.ui.icon :name="$icon" class="rg-admin-input__icon" />
        @endif
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            @if ($name !== null) name="{{ $name }}" @endif
            value="{{ $value }}"
            @if ($placeholder !== null) placeholder="{{ $placeholder }}" @endif
            class="rg-admin-input__control"
            @required($required === true)
            @disabled($disabled)
            @if ($error !== null) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['class', 'style', 'aria-describedby']) }}
        />
    </div>

    @if ($error !== null)
        <p id="{{ $id }}-error" class="rg-admin-error">
            <x-admin.ui.icon name="circle-alert" :size="12" />
            {{ $error }}
        </p>
    @elseif ($hint !== null)
        <p id="{{ $id }}-hint" class="rg-admin-hint">{{ $hint }}</p>
    @endif
</div>
