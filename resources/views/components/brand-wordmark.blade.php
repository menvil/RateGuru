@php
    $brandName = app(\App\Support\Settings\ProjectSettingsManager::class)->current()->siteName();
    // Two-tone wordmark from the reference design (Plate + accent Rate): split a
    // CamelCase site name at the second capitalised segment and render the tail
    // in the accent color. A leading acronym is one segment (AI + Guru). Names
    // without an inner capitalised word render single-tone.
    //
    // The name reads its own way (dir="auto"): a Latin name on a page read
    // right to left stays a left-to-right word, so where the header is too
    // narrow for it, it is cut at its end ("Rat…") rather than losing its
    // beginning ("…Guru").
    preg_match('/^(\p{Lu}+[^\p{Lu}]*)(\p{Lu}[^\p{Lu}].*)$/u', $brandName, $brandParts);
@endphp
<span dir="auto" {{ $attributes }}>
    @if($brandParts !== [])
        {{ $brandParts[1] }}<span class="text-rg-accent">{{ $brandParts[2] }}</span>
    @else
        {{ $brandName }}
    @endif
</span>
