@props([
    'label',
    'id',
    'value' => null,
    'prefix' => null,
    'generated' => null,
    'locked' => false,
    'hint' => null,
    'error' => null,
    'warning' => null,
])

@php
    /*
     * FRM-04. A slug or key: generated on create, locked once saved, and
     * changed only on purpose. The value is mono; `prefix` is an optional
     * plain lead-in inside the field.
     *
     * While it is being generated, `generated` names where from (“Generated
     * from name”) in a chip beside the label. Locked, the field is sunken and
     * read-only — still focusable, so its value can be read and copied — with
     * a lock and “Locked” beside the label, and the `unlock` slot, when given,
     * as the text action that unlocks it:
     *
     *     <x-slot:unlock wire:click="unlockSlug">Change slug</x-slot:unlock>
     *
     * Unlocked again, `warning` says what changing it breaks; the screen keeps
     * showing it until the change is saved or abandoned. Attributes go to the
     * input (wire:model="slug").
     */
    $describedBy = collect([
        $error !== null ? "{$id}-error" : ($hint !== null ? "{$id}-hint" : null),
        $warning !== null ? "{$id}-warning" : null,
    ])->filter()->implode(' ');
@endphp

<div class="rg-admin-field rg-admin-identifier">
    <div class="rg-admin-field__head rg-admin-identifier__head">
        <label for="{{ $id }}" class="rg-admin-label">{{ $label }}</label>
        @if ($locked)
            <span class="rg-admin-identifier__locked">
                <x-admin.ui.icon name="lock" :size="12" />
                Locked
            </span>
            @isset($unlock)
                <button type="button" {{ $unlock->attributes->class(['rg-admin-identifier__unlock']) }}>{{ $unlock }}</button>
            @endisset
        @elseif ($generated !== null)
            <span class="rg-admin-identifier__chip">{{ $generated }}</span>
        @endif
    </div>

    <div @class(['rg-admin-input', 'rg-admin-identifier__frame', 'rg-admin-identifier__frame--locked' => $locked, 'rg-admin-input--invalid' => $error !== null])>
        @if ($prefix !== null)
            <span class="rg-admin-identifier__prefix" aria-hidden="true">{{ $prefix }}</span>
        @endif
        <input
            id="{{ $id }}"
            type="text"
            value="{{ $value }}"
            class="rg-admin-input__control rg-admin-identifier__control"
            spellcheck="false"
            autocomplete="off"
            autocapitalize="off"
            @readonly($locked)
            @if ($error !== null) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes }}
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

    @if ($warning !== null)
        <x-admin.ui.inline-notice tone="warning" id="{{ $id }}-warning">{{ $warning }}</x-admin.ui.inline-notice>
    @endif
</div>
