{{--
    One of a few choices for the selected step, as the reference lists them:
    a row each, the chosen one framed, with a note under them.
--}}
<fieldset class="rg-admin-field rg-admin-games__radio-rows" @isset($testid) data-testid="{{ $testid }}" @endisset>
    <legend class="rg-admin-label">{{ $label }}</legend>
    @foreach ($options as $optionValue => $optionLabel)
        <label @class(['rg-admin-games__radio-row', 'rg-admin-games__radio-row--on' => (string) $value === (string) $optionValue])>
            <input type="radio" value="{{ $optionValue }}" wire:model.live="{{ $model }}" />
            {{ $optionLabel }}
        </label>
    @endforeach
    @if (($note ?? '') !== '')
        <span class="rg-admin-games__note">{{ $note }}</span>
    @endif
</fieldset>
