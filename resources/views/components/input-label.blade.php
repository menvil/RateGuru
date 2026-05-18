@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-rg-text2']) }}>
    {{ $value ?? $slot }}
</label>
