@php
    $brandName = app(\App\Support\Settings\ProjectSettingsManager::class)->current()->siteName();
    // Two-tone wordmark from the reference design (Plate + accent Rate): split a
    // CamelCase site name at the second capitalised segment and render the tail
    // in the accent color. Names without an inner capital render single-tone.
    preg_match('/^(\p{Lu}[^\p{Lu}]+)(\p{Lu}.*)$/u', $brandName, $brandParts);
@endphp
<span {{ $attributes }}>
    @if($brandParts !== [])
        {{ $brandParts[1] }}<span class="text-rg-accent">{{ $brandParts[2] }}</span>
    @else
        {{ $brandName }}
    @endif
</span>
