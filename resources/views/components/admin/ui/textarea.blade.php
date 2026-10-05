@props([
    'label',
    'name' => null,
    'id' => null,
    'value' => null,
    'placeholder' => null,
    'rows' => 3,
    'hint' => null,
    'error' => null,
    'required' => null,
    'disabled' => false,
    'limit' => null,
    'tone' => 'default',
    'compact' => false,
])

@php
    /*
     * tone draws an unsaved draft: "info" for content the system generated,
     * "changed" for a manual edit. Which record state maps to which tone is the
     * caller's decision; the field itself knows nothing about translations.
     *
     * limit is a soft limit: the counter turns red past it, but typing is not
     * cut off, so the error can say how far over the text is.
     */
    if (! in_array($tone, ['default', 'info', 'changed'], true)) {
        throw new InvalidArgumentException("Unknown admin textarea tone [{$tone}].");
    }

    $id ??= 'rg-admin-field-'.\Illuminate\Support\Str::slug($name ?? $label);
    $length = mb_strlen((string) $value);
    // The caller's own descriptions come first; the field adds its counter and message.
    $describedBy = collect([
        $attributes->get('aria-describedby'),
        $limit !== null ? "{$id}-counter" : null,
        $error !== null ? "{$id}-error" : ($hint !== null ? "{$id}-hint" : null),
    ])->reject(fn (mixed $id): bool => $id === null || $id === '')->implode(' ');
@endphp

<div
    {{ $attributes->only(['class', 'style'])->class(['rg-admin-field']) }}
    {{-- Characters are counted as code points on both sides, as Laravel's max rule counts them. --}}
    @if ($limit !== null) x-data="{ length: @js($length), limit: @js((int) $limit) }" @endif
>
    <div class="rg-admin-field__head">
        <label for="{{ $id }}" class="rg-admin-label">{{ $label }}</label>
        @if ($required === true)
            <span class="rg-admin-field__tag rg-admin-field__tag--required">Required</span>
        @elseif ($required === false)
            <span class="rg-admin-field__tag">Optional</span>
        @endif
        @if ($limit !== null)
            <span
                id="{{ $id }}-counter"
                @class(['rg-admin-field__counter', 'rg-admin-field__counter--over' => $length > $limit])
                x-bind:class="{ 'rg-admin-field__counter--over': length > limit }"
                x-text="length + ' / ' + limit"
            >{{ $length }} / {{ $limit }}</span>
        @endif
    </div>

    <textarea
        id="{{ $id }}"
        @if ($name !== null) name="{{ $name }}" @endif
        rows="{{ $rows }}"
        @if ($placeholder !== null) placeholder="{{ $placeholder }}" @endif
        @class([
            'rg-admin-textarea',
            'rg-admin-textarea--compact' => $compact,
            'rg-admin-textarea--info' => $tone === 'info',
            'rg-admin-textarea--changed' => $tone === 'changed',
            'rg-admin-textarea--invalid' => $error !== null,
        ])
        @required($required === true)
        @disabled($disabled)
        @if ($error !== null) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($limit !== null) x-on:input="length = Array.from($event.target.value).length" @endif
        {{ $attributes->except(['class', 'style', 'aria-describedby']) }}
    >{{ $value }}</textarea>

    @if ($error !== null)
        <p id="{{ $id }}-error" class="rg-admin-error">
            <x-admin.ui.icon name="circle-alert" :size="12" />
            {{ $error }}
        </p>
    @elseif ($hint !== null)
        <p id="{{ $id }}-hint" class="rg-admin-hint">{{ $hint }}</p>
    @endif
</div>
